<?php

namespace EasyBusyChats\Installer;

use Bitrix\Main\Config\Option;
use EasyBusyChats\Config;

final class OptionInstaller
{
    public static function install(): void
    {
        foreach (Config::getDefaults() as $name => $value) {
            if (Option::get(Config::MODULE_ID, $name, '__easybusy_missing__') === '__easybusy_missing__') {
                Option::set(Config::MODULE_ID, $name, (string)$value);
            }
        }
        $apiBaseUrl = rtrim(Option::get(Config::MODULE_ID, Config::OPTION_API_BASE_URL, ''), '/');
        if ($apiBaseUrl === 'https://api.dev.easy-busy.cloud/chats/v1') {
            Option::set(
                Config::MODULE_ID,
                Config::OPTION_API_BASE_URL,
                Config::getDefaults()[Config::OPTION_API_BASE_URL]
            );
        }
    }

    public static function uninstall(bool $deleteSettings): void
    {
        if ($deleteSettings) {
            Option::delete(Config::MODULE_ID);
        }
    }
}
