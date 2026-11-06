<?php

namespace EasyBusyChats\Installer;

use Bitrix\Main\Config\Option;
use Bitrix\Main\EventManager;
use EasyBusyChats\Integration\CrmCardIntegration;
use EasyBusyChats\Integration\UserAccessIntegration;

final class EventInstaller
{
    public const OPTION_VERSION = 'event_handlers_version';
    public const VERSION = 2;

    public static function install(): void
    {
        self::uninstall();
        EventManager::getInstance()->registerEventHandler(
            'crm',
            'onEntityDetailsTabsInitialized',
            'easybusychats',
            CrmCardIntegration::class,
            'onEntityDetailsTabsInitialized'
        );
        EventManager::getInstance()->registerEventHandler(
            'main',
            'OnProlog',
            'easybusychats',
            CrmCardIntegration::class,
            'onProlog'
        );
        EventManager::getInstance()->registerEventHandler(
            'main',
            'OnProlog',
            'easybusychats',
            UserAccessIntegration::class,
            'onProlog'
        );
        Option::set('easybusychats', self::OPTION_VERSION, (string)self::VERSION);
    }

    public static function uninstall(): void
    {
        EventManager::getInstance()->unRegisterEventHandler(
            'crm',
            'onEntityDetailsTabsInitialized',
            'easybusychats',
            CrmCardIntegration::class,
            'onEntityDetailsTabsInitialized'
        );
        EventManager::getInstance()->unRegisterEventHandler(
            'main',
            'OnProlog',
            'easybusychats',
            CrmCardIntegration::class,
            'onProlog'
        );
        EventManager::getInstance()->unRegisterEventHandler(
            'main',
            'OnProlog',
            'easybusychats',
            UserAccessIntegration::class,
            'onProlog'
        );
    }
}
