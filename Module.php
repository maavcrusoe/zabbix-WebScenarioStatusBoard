<?php

namespace Modules\WebScenarioStatusBoard;

use APP;
use CMenuItem;
use Zabbix\Core\CModule;

class Module extends CModule {

    public function init(): void {
        APP::Component()->get('menu.main')
            ->findOrAdd(_('Monitoring'))
            ->getSubmenu()
            ->insertAfter(_('Discovery'), ((new CMenuItem(_('Web Scenario Board')))
                ->setAction('web.scenario.status.board')));
    }
}
