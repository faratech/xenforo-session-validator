<?php

namespace WindowsForum\SessionValidator\XF\Template;

class Templater extends XFCP_Templater
{
    protected static $wfStaticCssManifest;
    protected static $wfStaticCssManifestMtime;

    public function addDefaultHandlers()
    {
        parent::addDefaultHandlers();

        $this->addFunctions([
            'wf_route_css_url' => 'fnWfRouteCssUrl',
        ]);
    }

    public function getCssLoadUrl(array $templates, $includeValidation = true)
    {
        $url = parent::getCssLoadUrl($templates, $includeValidation);

        if ($this->wfShouldUseStaticCss($templates))
        {
            $staticUrl = $this->wfGetStaticCssUrl($url);
            if ($staticUrl)
            {
                return $staticUrl;
            }
        }

        return $url;
    }

    public function fnWfRouteCssUrl($templater, &$escape, string $part = 'critical'): string
    {
        $escape = false;

        if (!$this->wfShouldUseRouteCss())
        {
            return '';
        }

        $bucket = $this->wfRouteCssBucket();
        if ($bucket === '')
        {
            return '';
        }

        $manifest = $this->wfStaticCssManifest();
        $routeCss = $manifest['routeCssSplit'] ?? [];
        if (!is_array($routeCss))
        {
            return '';
        }

        $bucketCss = $routeCss[$bucket] ?? $routeCss['default'] ?? null;
        if (!is_array($bucketCss))
        {
            return '';
        }

        return (string)($bucketCss[$part] ?? '');
    }

    protected function wfShouldUseStaticCss(array $templates): bool
    {
        if ($templates === ['__SENTINEL__'])
        {
            return false;
        }

        // Gate on the style the manifest was actually BUILT for (its styleId),
        // not a hardcoded id. The old `=== 47` went stale at the wf4/style-50
        // cutover, leaving this inert for guests; hardcoding also risked serving
        // one style's static CSS to another. When the manifest is rebuilt for the
        // live default style, this activates automatically.
        $manifest = $this->wfStaticCssManifest();
        if (empty($manifest) || (int)$this->getStyleId() !== (int)($manifest['styleId'] ?? 0))
        {
            return false;
        }

        if (!empty($_GET['wf_static_css']) && $_GET['wf_static_css'] === '0')
        {
            return false;
        }

        try
        {
            if (\XF::visitor()->user_id)
            {
                return false;
            }

            return $this->app->container('app.classType') === 'Pub';
        }
        catch (\Throwable $e)
        {
            return false;
        }
    }

    protected function wfShouldUseRouteCss(): bool
    {
        if (empty($_GET['wf_route_css']) || $_GET['wf_route_css'] !== '2')
        {
            return false;
        }

        // Gate on the style the manifest was actually BUILT for (its styleId),
        // not a hardcoded id. The old `=== 47` went stale at the wf4/style-50
        // cutover, leaving this inert for guests; hardcoding also risked serving
        // one style's static CSS to another. When the manifest is rebuilt for the
        // live default style, this activates automatically.
        $manifest = $this->wfStaticCssManifest();
        if (empty($manifest) || (int)$this->getStyleId() !== (int)($manifest['styleId'] ?? 0))
        {
            return false;
        }

        try
        {
            if (\XF::visitor()->user_id)
            {
                return false;
            }

            return $this->app->container('app.classType') === 'Pub';
        }
        catch (\Throwable $e)
        {
            return false;
        }
    }

    protected function wfRouteCssBucket(): string
    {
        $template = '';
        if (isset($this->defaultParams['xf']['reply']['template']))
        {
            $template = (string)$this->defaultParams['xf']['reply']['template'];
        }
        else if (isset($this->defaultParams['template']))
        {
            $template = (string)$this->defaultParams['template'];
        }

        if ($template === '')
        {
            return 'default';
        }

        return preg_replace('/[^a-z0-9_.-]+/i', '_', $template) ?: 'default';
    }

    protected function wfGetStaticCssUrl(string $url): ?string
    {
        $manifest = $this->wfStaticCssManifest();
        if (!$manifest)
        {
            return null;
        }

        $decodedUrl = html_entity_decode($url, ENT_QUOTES, 'UTF-8');
        if (!empty($manifest['byOriginal'][$decodedUrl]))
        {
            return $manifest['byOriginal'][$decodedUrl];
        }

        $pathOnly = parse_url($decodedUrl, PHP_URL_PATH);
        $query = parse_url($decodedUrl, PHP_URL_QUERY);
        if ($pathOnly && $query)
        {
            $pathUrl = $pathOnly . '?' . $query;
            if (!empty($manifest['byOriginal'][$pathUrl]))
            {
                return $manifest['byOriginal'][$pathUrl];
            }
        }

        $key = $this->wfStaticCssKeyFromUrl($decodedUrl);
        if ($key && !empty($manifest['byKey'][$key]))
        {
            return $manifest['byKey'][$key];
        }

        return null;
    }

    protected function wfStaticCssManifest(): array
    {
        $path = \XF::getRootDirectory() . '/internal_data/wf_css_static/manifest.json';
        if (!is_file($path))
        {
            self::$wfStaticCssManifest = [];
            self::$wfStaticCssManifestMtime = null;
            return self::$wfStaticCssManifest;
        }

        $mtime = filemtime($path) ?: 0;
        if (self::$wfStaticCssManifest !== null && self::$wfStaticCssManifestMtime === $mtime)
        {
            return self::$wfStaticCssManifest;
        }

        $json = file_get_contents($path);
        $manifest = is_string($json) ? json_decode($json, true) : null;
        self::$wfStaticCssManifest = is_array($manifest) ? $manifest : [];
        self::$wfStaticCssManifestMtime = $mtime;

        return self::$wfStaticCssManifest;
    }

    protected function wfStaticCssKeyFromUrl(string $url): ?string
    {
        $query = parse_url($url, PHP_URL_QUERY);
        if (!$query)
        {
            return null;
        }

        parse_str($query, $params);
        if (empty($params['css']) || empty($params['s']) || empty($params['l']) || empty($params['d']))
        {
            return null;
        }

        return 'css=' . (string)$params['css']
            . '&s=' . (string)$params['s']
            . '&l=' . (string)$params['l']
            . '&d=' . (string)$params['d']
            . '&k=' . (string)($params['k'] ?? '');
    }
}
