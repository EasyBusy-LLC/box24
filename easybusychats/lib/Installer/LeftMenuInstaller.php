<?php

namespace EasyBusyChats\Installer;

use Bitrix\Intranet\UI\LeftMenu\Menu;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\UserTable;

final class LeftMenuInstaller
{
    private const OPTION_PREFIX = 'left_menu_items_to_all_';
    private const ITEM_ID = 'menu_easybusychats';
    private const ITEM_LINK = '/easybusychats/';

    public static function install(): void
    {
        self::removeSharedItem();

        global $USER;
        if (is_object($USER) && $USER->IsAuthorized()) {
            $userId = (int)$USER->GetID();
            self::syncUser($userId, (new \EasyBusyChats\Service\AccessPolicyService())->isAllowed($userId));
        }
    }

    public static function syncUser(int $userId, bool $allowed): void
    {
        if ($userId <= 0) {
            return;
        }

        $changed = false;
        foreach (self::getSiteIds() as $siteId) {
            $optionName = 'left_menu_self_items_' . $siteId;
            $items = \CUserOptions::GetOption('intranet', $optionName, [], $userId);
            $items = is_array($items) ? array_values(array_filter($items, 'is_array')) : [];
            $filtered = array_values(array_filter(
                $items,
                static fn(array $item): bool => !self::isEasyBusyItem($item)
            ));
            if ($allowed) {
                $filtered[] = self::getMenuItem();
            }

            if ($items === $filtered) {
                continue;
            }

            $changed = true;
            if ($filtered === []) {
                \CUserOptions::DeleteOption('intranet', $optionName, false, $userId);
            } else {
                \CUserOptions::SetOption('intranet', $optionName, $filtered, false, $userId);
            }
        }

        if ($changed) {
            self::clearMenuCache();
        }
    }

    public static function uninstall(): void
    {
        self::removeSharedItem();
        $users = UserTable::getList(['select' => ['ID']]);
        while ($user = $users->fetch()) {
            self::syncUser((int)$user['ID'], false);
        }
    }

    private static function removeSharedItem(): void
    {
        foreach (self::getSiteIds() as $siteId) {
            $optionName = self::OPTION_PREFIX . $siteId;
            $items = array_values(array_filter(
                self::getItems($optionName, $siteId),
                static fn(array $item): bool => !self::isEasyBusyItem($item)
            ));

            if ($items === []) {
                Option::delete('intranet', [
                    'name' => $optionName,
                    'site_id' => $siteId,
                ]);
            } else {
                Option::set('intranet', $optionName, serialize($items), $siteId);
            }
        }

        self::clearMenuCache();
    }

    private static function getMenuItem(): array
    {
        return [
            'ID' => self::ITEM_ID,
            'TEXT' => 'EasyBusy Chats',
            'LINK' => self::ITEM_LINK,
            'NEW_PAGE' => 'N',
        ];
    }

    private static function getItems(string $optionName, string $siteId): array
    {
        $serializedItems = Option::get('intranet', $optionName, '', $siteId);
        if ($serializedItems === '') {
            return [];
        }

        $items = @unserialize($serializedItems, ['allowed_classes' => false]);

        return is_array($items)
            ? array_values(array_filter($items, 'is_array'))
            : [];
    }

    private static function isEasyBusyItem(array $item): bool
    {
        return ($item['ID'] ?? null) === self::ITEM_ID
            || ($item['LINK'] ?? null) === self::ITEM_LINK;
    }

    private static function getSiteIds(): array
    {
        if (Loader::includeModule('intranet')) {
            return [Menu::getDefaultSiteId()];
        }

        return ['s1'];
    }

    private static function clearMenuCache(): void
    {
        \CBitrixComponent::clearComponentCache('bitrix:menu');
    }
}
