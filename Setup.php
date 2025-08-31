<?php

namespace WindowsForum\SessionValidator;

use XF\AddOn\AbstractSetup;
use XF\AddOn\StepRunnerInstallTrait;
use XF\AddOn\StepRunnerUninstallTrait;
use XF\AddOn\StepRunnerUpgradeTrait;

class Setup extends AbstractSetup
{
    use StepRunnerInstallTrait;
    use StepRunnerUpgradeTrait;
    use StepRunnerUninstallTrait;

    public function installStep1()
    {
        // XenForo will automatically import _data files
    }

    public function uninstallStep1()
    {
        $this->db()->delete('xf_option_group', 'group_id = ?', 'wfSessionValidator');

        $options = [
            'wfSessionValidator_enabled',
            'wfSessionValidator_verboseOutput',
            'wfSessionValidator_cloudflareOnly',
            'wfCacheOptimizer_enabled',
            'wfCacheOptimizer_extendedCacheNodes',
            'wfCacheOptimizer_homepage',
            'wfCacheOptimizer_homepageEdgeCache',
            'wfCacheOptimizer_default',
            'wfCacheOptimizer_defaultEdgeCache',
            'wfCacheOptimizer_forums',
            'wfCacheOptimizer_forumsEdgeCache',
            'wfCacheOptimizer_windowsNews',
            'wfCacheOptimizer_windowsNewsEdgeCache',
            'wfCacheOptimizer_ageThreshold1Day',
            'wfCacheOptimizer_ageThreshold7Days',
            'wfCacheOptimizer_ageThreshold30Days',
            'wfCacheOptimizer_threadFresh',
            'wfCacheOptimizer_threadFreshEdgeCache',
            'wfCacheOptimizer_thread1Day',
            'wfCacheOptimizer_thread1DayEdgeCache',
            'wfCacheOptimizer_thread7Days',
            'wfCacheOptimizer_thread7DaysEdgeCache',
            'wfCacheOptimizer_thread30Days',
            'wfCacheOptimizer_thread30DaysEdgeCache',
        ];

        foreach ($options as $option)
        {
            $this->db()->delete('xf_option', 'option_id = ?', $option);
        }

        $phrases = [
            'option.wfSessionValidator_enabled',
            'option.wfSessionValidator_verboseOutput',
            'option_explain.wfSessionValidator_enabled',
            'option_explain.wfSessionValidator_verboseOutput',
            'option.wfSessionValidator_cloudflareOnly',
            'option_explain.wfSessionValidator_cloudflareOnly',
            'option_group.wfSessionValidator',
            'option_group.wfSessionValidator_description',
            'option.wfCacheOptimizer_enabled',
            'option_explain.wfCacheOptimizer_enabled',
            'option.wfCacheOptimizer_forceHeaders',
            'option_explain.wfCacheOptimizer_forceHeaders',
            'option.wfCacheOptimizer_homepage',
            'option_explain.wfCacheOptimizer_homepage',
            'option.wfCacheOptimizer_whatsNew',
            'option_explain.wfCacheOptimizer_whatsNew',
            'option.wfCacheOptimizer_whatsNewEdgeCache',
            'option_explain.wfCacheOptimizer_whatsNewEdgeCache',
            'option.wfCacheOptimizer_forums',
            'option_explain.wfCacheOptimizer_forums',
            'option.wfCacheOptimizer_forumsEdgeCache',
            'option_explain.wfCacheOptimizer_forumsEdgeCache',
            'option.wfCacheOptimizer_windowsNews',
            'option_explain.wfCacheOptimizer_windowsNews',
            'option.wfCacheOptimizer_windowsNewsEdgeCache',
            'option_explain.wfCacheOptimizer_windowsNewsEdgeCache',
            'option.wfCacheOptimizer_search',
            'option_explain.wfCacheOptimizer_search',
            'option.wfCacheOptimizer_searchEdgeCache',
            'option_explain.wfCacheOptimizer_searchEdgeCache',
            'option.wfCacheOptimizer_members',
            'option_explain.wfCacheOptimizer_members',
            'option.wfCacheOptimizer_membersEdgeCache',
            'option_explain.wfCacheOptimizer_membersEdgeCache',
            'option.wfCacheOptimizer_help',
            'option_explain.wfCacheOptimizer_help',
            'option.wfCacheOptimizer_helpEdgeCache',
            'option_explain.wfCacheOptimizer_helpEdgeCache',
            'option.wfCacheOptimizer_media',
            'option_explain.wfCacheOptimizer_media',
            'option.wfCacheOptimizer_mediaEdgeCache',
            'option_explain.wfCacheOptimizer_mediaEdgeCache',
            'option.wfCacheOptimizer_resources',
            'option_explain.wfCacheOptimizer_resources',
            'option.wfCacheOptimizer_resourcesEdgeCache',
            'option_explain.wfCacheOptimizer_resourcesEdgeCache',
            'option.wfCacheOptimizer_default',
            'option_explain.wfCacheOptimizer__default',
            'option.wfCacheOptimizer_defaultEdgeCache',
            'option_explain.wfCacheOptimizer_defaultEdgeCache',
            'option.wfCacheOptimizer_threadFresh',
            'option_explain.wfCacheOptimizer_threadFresh',
            'option.wfCacheOptimizer_threadFreshEdgeCache',
            'option_explain.wfCacheOptimizer_threadFreshEdgeCache',
            'option.wfCacheOptimizer_thread1Day',
            'option_explain.wfCacheOptimizer_thread1Day',
            'option.wfCacheOptimizer_thread1DayEdgeCache',
            'option_explain.wfCacheOptimizer_thread1DayEdgeCache',
            'option.wfCacheOptimizer_thread7Days',
            'option_explain.wfCacheOptimizer_thread7Days',
            'option.wfCacheOptimizer_thread7DaysEdgeCache',
            'option_explain.wfCacheOptimizer_thread7DaysEdgeCache',
            'option.wfCacheOptimizer_thread30Days',
            'option_explain.wfCacheOptimizer_thread30Days',
            'option.wfCacheOptimizer_thread30DaysEdgeCache',
            'option_explain.wfCacheOptimizer_thread30DaysEdgeCache',
            'option.wfCacheOptimizer_extendedCacheNodes',
            'option_explain.wfCacheOptimizer_extendedCacheNodes',
            'option.wfCacheOptimizer_homepageEdgeCache',
            'option_explain.wfCacheOptimizer_homepageEdgeCache',
            'option.wfCacheOptimizer_extendedThreadFresh',
            'option_explain.wfCacheOptimizer_extendedThreadFresh',
            'option.wfCacheOptimizer_extendedThreadFreshEdgeCache',
            'option_explain.wfCacheOptimizer_extendedThreadFreshEdgeCache',
            'option.wfCacheOptimizer_extendedThread1Day',
            'option_explain.wfCacheOptimizer_extendedThread1Day',
            'option.wfCacheOptimizer_extendedThread1DayEdgeCache',
            'option_explain.wfCacheOptimizer_extendedThread1DayEdgeCache',
            'option.wfCacheOptimizer_extendedThread7Days',
            'option_explain.wfCacheOptimizer_extendedThread7Days',
            'option.wfCacheOptimizer_extendedThread7DaysEdgeCache',
            'option_explain.wfCacheOptimizer_extendedThread7DaysEdgeCache',
            'option.wfCacheOptimizer_extendedThread30Days',
            'option_explain.wfCacheOptimizer_extendedThread30Days',
            'option.wfCacheOptimizer_extendedThread30DaysEdgeCache',
            'option_explain.wfCacheOptimizer_extendedThread30DaysEdgeCache',
            'option.wfCacheOptimizer_ageThreshold1Day',
            'option_explain.wfCacheOptimizer_ageThreshold1Day',
            'option.wfCacheOptimizer_ageThreshold7Days',
            'option_explain.wfCacheOptimizer_ageThreshold7Days',
            'option.wfCacheOptimizer_ageThreshold30Days',
            'option_explain.wfCacheOptimizer_ageThreshold30Days',
            'option.wfCacheOptimizer_ancientThreshold',
            'option_explain.wfCacheOptimizer_ancientThreshold',
            'option.wfCacheOptimizer_ancientCache',
            'option_explain.wfCacheOptimizer_ancientCache',
            'option.wfCacheOptimizer_ancientCacheEdgeCache',
            'option_explain.wfCacheOptimizer_ancientCacheEdgeCache',
            'option.wfCacheOptimizer_default',
            'option_explain.wfCacheOptimizer_default',
            'option.wfCacheOptimizer_defaultEdgeCache',
            'option_explain.wfCacheOptimizer_defaultEdgeCache',
            'option.wfCacheOptimizer_homepageEdgeCache',
            'option_explain.wfCacheOptimizer_homepageEdgeCache',
        ];

        foreach ($phrases as $phrase)
        {
            $this->db()->delete('xf_phrase', 'title = ?', $phrase);
        }
    }
}