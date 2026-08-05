<?php

namespace EasyBusyChats\Installer;

use Bitrix\Main\Config\Option;
use Bitrix\Main\GroupTable;
use EasyBusyChats\Config;

final class AccessGroupInstaller
{
    public const GROUP_STRING_ID = 'EASYBUSYCHATS_ACCESS';
    public const GROUP_NAME = 'EasyBusy Chats: Пользователи';
    public const OPTION_GROUP_ID = 'access_group_id';
    public const OPTION_POLICY_VERSION = 'access_policy_version';
    public const POLICY_VERSION = 1;

    public static function install(?int $installerUserId = null): int
    {
        $groupId = self::findGroupId();
        if ($groupId <= 0) {
            $group = new \CGroup();
            $groupId = (int)$group->Add([
                'ACTIVE' => 'Y',
                'C_SORT' => 100,
                'NAME' => self::GROUP_NAME,
                'DESCRIPTION' => 'Пользователи с доступом к модулю EasyBusy Chats.',
                'STRING_ID' => self::GROUP_STRING_ID,
            ]);
            if ($groupId <= 0) {
                throw new \RuntimeException('Could not create the EasyBusy Chats access group: ' . $group->LAST_ERROR);
            }
        } else {
            $group = new \CGroup();
            $group->Update($groupId, [
                'ACTIVE' => 'Y',
                'NAME' => self::GROUP_NAME,
                'DESCRIPTION' => 'Пользователи с доступом к модулю EasyBusy Chats.',
                'STRING_ID' => self::GROUP_STRING_ID,
            ]);
        }

        Option::set(Config::MODULE_ID, self::OPTION_GROUP_ID, (string)$groupId);
        Option::set(Config::MODULE_ID, self::OPTION_POLICY_VERSION, (string)self::POLICY_VERSION);

        $installerUserId ??= self::currentAdminId();
        if ($installerUserId > 0 && self::isAdministrator($installerUserId)) {
            \CUser::AppendUserGroup($installerUserId, $groupId);
        }

        return $groupId;
    }

    public static function uninstall(): void
    {
        $groupId = self::findGroupId();
        if ($groupId > 0) {
            \CGroup::Delete($groupId);
        }

        Option::delete(Config::MODULE_ID, ['name' => self::OPTION_GROUP_ID]);
        Option::delete(Config::MODULE_ID, ['name' => self::OPTION_POLICY_VERSION]);
    }

    public static function getGroupId(): int
    {
        $configuredId = (int)Option::get(Config::MODULE_ID, self::OPTION_GROUP_ID, '0');
        if ($configuredId > 0) {
            $group = GroupTable::getByPrimary($configuredId, ['select' => ['ID', 'STRING_ID', 'ACTIVE']])->fetch();
            if ($group && (string)$group['STRING_ID'] === self::GROUP_STRING_ID && (string)$group['ACTIVE'] === 'Y') {
                return $configuredId;
            }
        }

        return self::findGroupId();
    }

    private static function findGroupId(): int
    {
        $group = GroupTable::getList([
            'select' => ['ID'],
            'filter' => ['=STRING_ID' => self::GROUP_STRING_ID],
            'limit' => 1,
        ])->fetch();

        return (int)($group['ID'] ?? 0);
    }

    private static function currentAdminId(): int
    {
        global $USER;

        return is_object($USER) && $USER->IsAuthorized() && $USER->IsAdmin()
            ? (int)$USER->GetID()
            : 0;
    }

    private static function isAdministrator(int $userId): bool
    {
        return in_array(1, array_map('intval', \CUser::GetUserGroup($userId)), true);
    }
}
