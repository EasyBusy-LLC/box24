<?php

namespace EasyBusyChats\Installer;

use Bitrix\Main\EventManager;
use EasyBusyChats\Integration\CrmCardIntegration;
use EasyBusyChats\Integration\UserAccessIntegration;

final class EventInstaller
{
    public static function install(): void
    {
        self::uninstall();
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
    }

    public static function uninstall(): void
    {
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
