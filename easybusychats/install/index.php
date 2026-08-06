<?php

use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Loader;
use Bitrix\Main\ModuleManager;
use EasyBusyChats\Installer\DatabaseInstaller;
use EasyBusyChats\Installer\AccessGroupInstaller;
use EasyBusyChats\Installer\EventInstaller;
use EasyBusyChats\Installer\LeftMenuInstaller;
use EasyBusyChats\Installer\OptionInstaller;

Loc::loadMessages(__FILE__);
Loader::registerNamespace('EasyBusyChats', dirname(__DIR__) . '/lib');

require_once __DIR__ . '/../lib/Config.php';
require_once __DIR__ . '/../lib/Model/ChatContactTable.php';
require_once __DIR__ . '/../lib/Model/ChatDealTable.php';
require_once __DIR__ . '/../lib/Model/UserTokenTable.php';
require_once __DIR__ . '/../lib/Model/BoxCredentialTable.php';
require_once __DIR__ . '/../lib/Installer/DatabaseInstaller.php';
require_once __DIR__ . '/../lib/Installer/AccessGroupInstaller.php';
require_once __DIR__ . '/../lib/Installer/EventInstaller.php';
require_once __DIR__ . '/../lib/Installer/LeftMenuInstaller.php';
require_once __DIR__ . '/../lib/Installer/OptionInstaller.php';

class easybusychats extends CModule
{
    public $MODULE_ID = 'easybusychats';
    public $MODULE_VERSION;
    public $MODULE_VERSION_DATE;
    public $MODULE_NAME;
    public $MODULE_DESCRIPTION;
    public $PARTNER_NAME;
    public $PARTNER_URI;

    public function __construct()
    {
        $version = [];
        include __DIR__ . '/version.php';

        $this->MODULE_VERSION = $version['VERSION'] ?? '1.0.0';
        $this->MODULE_VERSION_DATE = $version['VERSION_DATE'] ?? '2026-08-03 00:00:00';
        $this->MODULE_NAME = Loc::getMessage('EASYBUSYCHATS_MODULE_NAME');
        $this->MODULE_DESCRIPTION = Loc::getMessage('EASYBUSYCHATS_MODULE_DESCRIPTION');
        $this->PARTNER_NAME = Loc::getMessage('EASYBUSYCHATS_PARTNER_NAME');
        $this->PARTNER_URI = '';
    }

    public function DoInstall(): void
    {
        ModuleManager::registerModule($this->MODULE_ID);
        OptionInstaller::install();
        DatabaseInstaller::install();
        AccessGroupInstaller::install();
        $this->InstallFiles();
        LeftMenuInstaller::install();
        EventInstaller::install();
    }

    public function DoUninstall(): void
    {
        global $APPLICATION;

        $step = (int)($_REQUEST['step'] ?? 1);
        if ($step < 2) {
            $APPLICATION->IncludeAdminFile(
                Loc::getMessage('EASYBUSYCHATS_UNINSTALL_TITLE'),
                __DIR__ . '/unstep1.php'
            );
            return;
        }

        $deleteMappings = ($_REQUEST['delete_mappings'] ?? 'N') === 'Y';
        EventInstaller::uninstall();
        LeftMenuInstaller::uninstall();
        AccessGroupInstaller::uninstall();
        $this->UnInstallFiles();
        DatabaseInstaller::uninstall($deleteMappings);
        OptionInstaller::uninstall(true);
        ModuleManager::unRegisterModule($this->MODULE_ID);

        $APPLICATION->IncludeAdminFile(
            Loc::getMessage('EASYBUSYCHATS_UNINSTALL_TITLE'),
            __DIR__ . '/unstep2.php'
        );
    }

    public function InstallDB(): bool
    {
        DatabaseInstaller::install();
        return true;
    }

    public function InstallEvents(): bool
    {
        EventInstaller::install();
        return true;
    }

    public function UnInstallEvents(): bool
    {
        EventInstaller::uninstall();
        return true;
    }

    public function InstallFiles(): bool
    {
        CopyDirFiles(
            __DIR__ . '/public',
            $_SERVER['DOCUMENT_ROOT'],
            true,
            true
        );

        return true;
    }

    public function UnInstallFiles(): bool
    {
        DeleteDirFilesEx('/easybusychats');

        return true;
    }
}
