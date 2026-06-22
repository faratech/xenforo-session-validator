<?php

namespace WindowsForum\SessionValidator\XF\Pub\Controller;

use XF\Entity\Search;
use XF\Mvc\ParameterBag;
use XF\Mvc\Reply\AbstractReply;

class SearchController extends XFCP_SearchController
{
    /**
     * Redis-based guest search rate limiter and canonical search cache.
     *
     * Problem: bots with rotating IPs hammer /search/{stale_id}/?c[users]=X&o=date
     * Each nonexistent search_id triggers a full ES re-search via runSearch().
     * XF's built-in cache (getPreviousSearch) works per-query but under high
     * concurrency, many processes miss the cache window simultaneously.
     *
     * Fix: maintain a Redis hash of query_hash => search_id so the redirect
     * to a valid search_id is near-instant without touching MySQL or ES.
     * Rate limiting is per-IP across all guest search execution paths.
     */

    protected const SEARCH_CACHE_KEY = 'wf_search:canonical';
    protected const SEARCH_CACHE_TTL = 3600;
    protected const SEARCH_STATS_KEY = 'wf_search:stats';
    protected const RATE_LIMIT_KEY_PREFIX = 'wf_search:rate:';
    protected const RATE_LIMIT_WINDOW = 60;
    protected const RATE_LIMIT_MAX_PER_IP = 10;
    protected const MIN_AUTO_COMPLETE_KEYWORD_LENGTH = 2;

    /**
     * Skip CSRF validation for guests so Cloudflare managed challenges work.
     * When CF challenges a POST to /search/search, it replays the request
     * after solving, but the CSRF token no longer matches. Guests don't have
     * sessions worth protecting with CSRF anyway.
     */
    public function checkCsrfIfNeeded($action, ParameterBag $params)
    {
        if (\XF::visitor()->user_id === 0)
        {
            return;
        }

        parent::checkCsrfIfNeeded($action, $params);
    }

    /**
     * Rate-limit the main search submission (POST /search/search).
     */
    public function actionSearch()
    {
        if ($this->request->exists('from_search_menu'))
        {
            return parent::actionSearch();
        }

        $this->assertNotEmbeddedImageRequest();

        if (\XF::visitor()->user_id === 0)
        {
            $redis = $this->getSearchRedis();

            $input = $this->getSearchInput();
            $query = $this->prepareSearchQuery($input, $constraints);
            $cacheField = $this->getSearchCacheField($query);

            if ($redis)
            {
                $cachedReply = $this->getCachedGuestSearchRedirect($redis, $cacheField);
                if ($cachedReply)
                {
                    $this->recordSearchStat($redis, 'action_search_cache_hit');
                    return $cachedReply;
                }

                if ($this->isGuestSearchRateLimited($redis))
                {
                    $this->recordSearchStat($redis, 'action_search_rate_limited');
                    return $this->message(\XF::phrase('wf_search_rate_limited'));
                }
            }

            $searchPlugin = $this->plugin(\XF\ControllerPlugin\SearchPlugin::class);
            $searchPlugin->assertValidSearchQuery($query);

            $searchRepo = $this->repository(\XF\Repository\SearchRepository::class);
            $search = $searchRepo->runSearch($query, $constraints);

            if (!$search)
            {
                return $this->message(\XF::phrase('no_results_found'));
            }

            if ($redis)
            {
                $this->cacheGuestSearchRedirect($redis, $cacheField, $search->search_id);
                $this->recordSearchStat($redis, 'action_search_cache_store');
            }

            return $this->redirect($this->buildLink('search', $search), '');
        }

        return parent::actionSearch();
    }

    /**
     * Intercept guest requests to nonexistent search IDs — use canonical cache
     * to redirect instantly without hitting ES, and rate-limit new executions.
     */
    public function actionResults(ParameterBag $params)
    {
        $visitor = \XF::visitor();

        if ($visitor->user_id === 0 && $params->search_id)
        {
            $search = $this->em()->find(Search::class, $params->search_id);
            if (!$search || $visitor->user_id !== $search->user_id)
            {
                return $this->handleGuestSearchFallback($params);
            }
        }

        return parent::actionResults($params);
    }

    protected function handleGuestSearchFallback(ParameterBag $params): AbstractReply
    {
        $redis = $this->getSearchRedis();

        // Build the query from URL params to get the hash
        $searchData = $this->convertShortSearchInputNames();
        $query = $this->prepareSearchQuery($searchData, $constraints);

        $queryHash = $query->getUniqueQueryHash();
        $searchType = $query->getHandlerType() ?: '';
        $cacheField = $queryHash . ':' . $searchType;

        // Check Redis for a canonical search_id for this query
        if ($redis)
        {
            $cachedReply = $this->getCachedGuestSearchRedirect($redis, $cacheField);
            if ($cachedReply)
            {
                $this->recordSearchStat($redis, 'results_cache_hit');
                return $cachedReply;
            }

            // Per-IP rate limit: prevent any single source from hammering ES
            if ($this->isGuestSearchRateLimited($redis))
            {
                $this->recordSearchStat($redis, 'results_rate_limited');
                return $this->message(\XF::phrase('wf_search_rate_limited'));
            }
        }

        // Let XF run the search normally (will hit cache or ES)
        $searchPlugin = $this->plugin(\XF\ControllerPlugin\SearchPlugin::class);
        $searchPlugin->assertValidSearchQuery($query);

        $searchRepo = $this->repository(\XF\Repository\SearchRepository::class);
        $search = $searchRepo->runSearch($query, $constraints);

        if (!$search)
        {
            return $this->message(\XF::phrase('no_results_found'));
        }

        // Cache the canonical search_id in Redis for future requests
        if ($redis)
        {
            $this->cacheGuestSearchRedirect($redis, $cacheField, $search->search_id);
            $this->recordSearchStat($redis, 'results_cache_store');
        }

        return $this->redirect($this->buildLink('search', $search), '');
    }

    public function actionAutoComplete(ParameterBag $params): AbstractReply
    {
        if (\XF::visitor()->user_id === 0)
        {
            $input = $this->getSearchInput();
            $keywords = trim((string) ($input['keywords'] ?? ''));
            if ($keywords === '')
            {
                $keywords = trim((string) $this->filter('q', 'str'));
            }

            if ($keywords === '' || \XF\Util\Str::strlen($keywords) < self::MIN_AUTO_COMPLETE_KEYWORD_LENGTH)
            {
                $redis = $this->getSearchRedis();
                if ($redis)
                {
                    $this->recordSearchStat($redis, 'autocomplete_short_circuit');
                }

                $this->app->response()->header('Cache-Control', 'private, no-cache, no-store, max-age=0');
                $this->app->response()->header('Cloudflare-CDN-Cache-Control', 'no-store');

                return $this->view('XF:Search\AutoComplete', '', [
                    'results' => [],
                    'q' => $keywords,
                ]);
            }
        }

        return parent::actionAutoComplete($params);
    }

    /**
     * Rate-limit the actionOlder endpoint (deep pagination).
     */
    public function actionOlder(ParameterBag $params)
    {
        if (\XF::visitor()->user_id === 0)
        {
            $redis = $this->getSearchRedis();
            if ($redis && $this->isGuestSearchRateLimited($redis))
            {
                return $this->message(\XF::phrase('wf_search_rate_limited'));
            }
        }

        return parent::actionOlder($params);
    }

    /**
     * Rate-limit the actionMember endpoint (profile "postings" tab).
     */
    public function actionMember()
    {
        if (\XF::visitor()->user_id === 0)
        {
            $redis = $this->getSearchRedis();
            if ($redis && $this->isGuestSearchRateLimited($redis))
            {
                return $this->message(\XF::phrase('wf_search_rate_limited'));
            }
        }

        return parent::actionMember();
    }

    /**
     * Per-IP rate limiter for guest search execution.
     * Returns true if the request should be blocked.
     */
    protected function isGuestSearchRateLimited(\Redis $redis): bool
    {
        $ip = $this->request->getIp();
        $rateLimitKey = self::RATE_LIMIT_KEY_PREFIX . md5($ip);
        $current = $redis->incr($rateLimitKey);
        if ($current === 1)
        {
            $redis->expire($rateLimitKey, self::RATE_LIMIT_WINDOW);
        }

        return $current > self::RATE_LIMIT_MAX_PER_IP;
    }

    protected function getSearchCacheField($query): string
    {
        $queryHash = $query->getUniqueQueryHash();
        $searchType = $query->getHandlerType() ?: '';

        return $queryHash . ':' . $searchType;
    }

    protected function getCachedGuestSearchRedirect(\Redis $redis, string $cacheField): ?AbstractReply
    {
        $cachedId = $redis->hGet(self::SEARCH_CACHE_KEY, $cacheField);
        if (!$cachedId)
        {
            return null;
        }

        $cachedSearch = $this->em()->find(Search::class, (int) $cachedId);
        if ($cachedSearch)
        {
            return $this->redirect($this->buildLink('search', $cachedSearch), '');
        }

        $redis->hDel(self::SEARCH_CACHE_KEY, $cacheField);
        $this->recordSearchStat($redis, 'stale_canonical_removed');

        return null;
    }

    protected function cacheGuestSearchRedirect(\Redis $redis, string $cacheField, int $searchId): void
    {
        $redis->hSet(self::SEARCH_CACHE_KEY, $cacheField, $searchId);
        $redis->expire(self::SEARCH_CACHE_KEY, self::SEARCH_CACHE_TTL);
    }

    protected function recordSearchStat(\Redis $redis, string $field): void
    {
        try
        {
            $redis->hIncrBy(self::SEARCH_STATS_KEY, $field, 1);
            $redis->expire(self::SEARCH_STATS_KEY, 86400);
        }
        catch (\Exception $e)
        {
        }
    }

    /** @var \Redis|null */
    protected static $searchRedis = null;

    protected function getSearchRedis(): ?\Redis
    {
        if (static::$searchRedis !== null)
        {
            try {
                static::$searchRedis->ping();
                return static::$searchRedis;
            } catch (\Exception $e) {
                static::$searchRedis = null;
            }
        }

        try
        {
            $redis = new \Redis();
            $redis->pconnect('127.0.0.1', 6379, 2.0, 'wf_search');
            $redis->select(0);
            static::$searchRedis = $redis;
            return $redis;
        }
        catch (\Exception $e)
        {
            return null;
        }
    }
}
