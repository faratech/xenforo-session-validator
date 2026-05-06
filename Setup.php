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
        // XF auto-imports _data/* on install. No DB schema to create.
    }

    public function uninstallStep1()
    {
        // XF's AddOnUninstallData job removes options, phrases, option groups,
        // class extensions, listeners, template modifications, etc. by addon_id
        // automatically (see XF\Job\AddOnUninstallData). Nothing to do here.
    }
}
