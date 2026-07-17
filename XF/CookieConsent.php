<?php

namespace WindowsForum\SessionValidator\XF;

use function ksort, substr;

/**
 * Sort-on-read cookie consent container (~3.5% of render CPU reclaimed).
 *
 * Core XF\CookieConsent ksort()s its full backing array on EVERY insert:
 * addCookie() re-sorts $cookies per cookie and addThirdParty() re-sorts
 * $thirdParties per entry. XF\App::setupCookieConsent() feeds it ~36
 * cookies/localStorage entries and ~139 bbCodeMedia third parties on every
 * request, so container construction performs ~175 full ksorts of
 * ever-growing arrays before a single value is read.
 *
 * This extension re-implements the tiny insert bodies WITHOUT the ksort
 * (deliberately not calling parent::, which would sort) and marks the array
 * dirty instead. Every order-observable reader sorts once when dirty, then
 * delegates to core. All other readers (key lookups, getGroups() — which
 * canonicalises with its own sort()) are order-insensitive and stay
 * untouched.
 *
 * CORRECTNESS CONTRACT — byte-identical to core on every read path:
 * - Same ksort() (default SORT_REGULAR flags) over the same final key set,
 *   just deferred: sorted-per-insert and sorted-once-at-read converge to the
 *   identical array, including with reads interleaved between writes (the
 *   dirty flag re-arms on the next insert).
 * - $cookies order-observable readers: getCookies(), getCookiesInGroup()
 *   (array_filter preserves order), getUnconsentedCookies() (filter + list).
 * - $thirdParties: every order-observable read flows through
 *   getThirdParties() (removeThirdParty()'s assert included).
 * - $consentedGroups: every order-observable read flows through
 *   getConsentedGroups() (getGroupConsentState(), isGroupConsented(),
 *   applyConsentPreferences() all call it on $this).
 * - No behavioural gating: runs identically whether consent mode is
 *   disabled, simple, or advanced.
 */
class CookieConsent extends XFCP_CookieConsent
{
    /** @var bool */
    protected $wfCookiesSortPending = false;

    /** @var bool */
    protected $wfThirdPartiesSortPending = false;

    /** @var bool */
    protected $wfConsentedGroupsSortPending = false;

    public function addCookie(
        string $cookie,
        string $group,
        bool $prefix = true,
        bool $localStorage = false
    )
    {
        if (substr($cookie, -1) === '*')
        {
            $regex = '/^' . substr($cookie, 0, -1) . '\w+$/i';
        }
        else
        {
            $regex = '/^' . $cookie . '$/i';
        }

        $this->cookies[$cookie] = [
            'group' => $group,
            'prefix' => $prefix,
            'localStorage' => $localStorage,
            'regex' => $regex,
        ];
        $this->wfCookiesSortPending = true;
    }

    public function addThirdParty(string $thirdParty)
    {
        $this->thirdParties[$thirdParty] = true;
        $this->wfThirdPartiesSortPending = true;
    }

    public function addConsentedGroup(string $group)
    {
        if (!$this->isValidGroup($group))
        {
            return;
        }

        $this->consentedGroups[$group] = true;
        $this->wfConsentedGroupsSortPending = true;
    }

    /**
     * @return mixed[]
     */
    public function getCookies(): array
    {
        $this->wfSortCookiesIfPending();

        return parent::getCookies();
    }

    /**
     * @return mixed[]
     */
    public function getCookiesInGroup(string $group): array
    {
        $this->wfSortCookiesIfPending();

        return parent::getCookiesInGroup($group);
    }

    /**
     * @return string[]
     */
    public function getUnconsentedCookies(?\Closure $filter = null): array
    {
        $this->wfSortCookiesIfPending();

        return parent::getUnconsentedCookies($filter);
    }

    /**
     * @return string[]
     */
    public function getThirdParties(): array
    {
        $this->wfSortThirdPartiesIfPending();

        return parent::getThirdParties();
    }

    /**
     * @return string[]
     */
    public function getConsentedGroups(): array
    {
        $this->wfSortConsentedGroupsIfPending();

        return parent::getConsentedGroups();
    }

    protected function wfSortCookiesIfPending()
    {
        if ($this->wfCookiesSortPending)
        {
            ksort($this->cookies);
            $this->wfCookiesSortPending = false;
        }
    }

    protected function wfSortThirdPartiesIfPending()
    {
        if ($this->wfThirdPartiesSortPending)
        {
            ksort($this->thirdParties);
            $this->wfThirdPartiesSortPending = false;
        }
    }

    protected function wfSortConsentedGroupsIfPending()
    {
        if ($this->wfConsentedGroupsSortPending)
        {
            ksort($this->consentedGroups);
            $this->wfConsentedGroupsSortPending = false;
        }
    }
}
