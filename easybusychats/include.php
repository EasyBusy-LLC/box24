<?php

use Bitrix\Main\Loader;
use Bitrix\Main\Config\Option;
use Bitrix\Main\ModuleManager;
use EasyBusyChats\Installer\DatabaseInstaller;
use EasyBusyChats\Installer\AccessGroupInstaller;
use EasyBusyChats\Installer\EventInstaller;
use EasyBusyChats\Installer\LeftMenuInstaller;
use EasyBusyChats\Installer\OptionInstaller;

Loader::registerNamespace('EasyBusyChats', __DIR__ . '/lib');

if (ModuleManager::isModuleInstalled('easybusychats')
    && (int)Option::get('easybusychats', 'database_schema_version', '0') < 10
) {
    OptionInstaller::install();
    DatabaseInstaller::install();
}

if (ModuleManager::isModuleInstalled('easybusychats')
    && (int)Option::get(
        'easybusychats',
        AccessGroupInstaller::OPTION_POLICY_VERSION,
        '0'
    ) < AccessGroupInstaller::POLICY_VERSION
) {
    AccessGroupInstaller::install();
    LeftMenuInstaller::install();
    EventInstaller::install();
}

if (ModuleManager::isModuleInstalled('easybusychats')
    && (int)Option::get('easybusychats', EventInstaller::OPTION_VERSION, '0') < EventInstaller::VERSION
) {
    EventInstaller::install();
}
