<?php

namespace EasyBusyChats\Service;

use Bitrix\Main\UserTable;
use EasyBusyChats\Exception\IntegrationException;
use EasyBusyChats\Installer\AccessGroupInstaller;
use EasyBusyChats\Installer\LeftMenuInstaller;

final class AccessPolicyService
{
    public function isAllowed(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        $groups = array_map('intval', \CUser::GetUserGroup($userId));
        if (in_array(1, $groups, true)) {
            return true;
        }

        $accessGroupId = AccessGroupInstaller::getGroupId();

        return $accessGroupId > 0 && in_array($accessGroupId, $groups, true);
    }

    public function assertAllowed(int $userId): void
    {
        if (!$this->isAllowed($userId)) {
            throw new IntegrationException(
                'Access to EasyBusy Chats has not been granted by the Bitrix24 administrator.',
                'EASYBUSY_ACCESS_DENIED'
            );
        }
    }

    public function getUsersForManagement(): array
    {
        $users = [];
        $result = UserTable::getList([
            'select' => ['ID', 'LOGIN', 'NAME', 'LAST_NAME', 'EMAIL', 'ACTIVE', 'BLOCKED', 'EXTERNAL_AUTH_ID'],
            'filter' => ['=ACTIVE' => 'Y'],
            'order' => ['LAST_NAME' => 'ASC', 'NAME' => 'ASC', 'ID' => 'ASC'],
        ]);
        while ($user = $result->fetch()) {
            $externalAuthId = trim((string)($user['EXTERNAL_AUTH_ID'] ?? ''));
            if ($externalAuthId !== '') {
                continue;
            }

            $userId = (int)$user['ID'];
            $groups = array_map('intval', \CUser::GetUserGroup($userId));
            $isAdmin = in_array(1, $groups, true);
            $name = trim(implode(' ', array_filter([
                trim((string)($user['NAME'] ?? '')),
                trim((string)($user['LAST_NAME'] ?? '')),
            ])));

            $users[] = [
                'id' => $userId,
                'login' => (string)$user['LOGIN'],
                'name' => $name !== '' ? $name : (string)$user['LOGIN'],
                'email' => (string)($user['EMAIL'] ?? ''),
                'isAdmin' => $isAdmin,
                'allowed' => $isAdmin || $this->isAllowed($userId),
            ];
        }

        return $users;
    }

    public function replaceAllowedUsers(array $selectedUserIds): void
    {
        $groupId = AccessGroupInstaller::getGroupId();
        if ($groupId <= 0) {
            throw new IntegrationException('The EasyBusy Chats access group is unavailable.', 'ACCESS_GROUP_UNAVAILABLE');
        }

        $selected = array_fill_keys(array_values(array_unique(array_filter(
            array_map('intval', $selectedUserIds),
            static fn(int $id): bool => $id > 0
        ))), true);

        foreach ($this->getUsersForManagement() as $user) {
            $userId = (int)$user['id'];
            if ($user['isAdmin']) {
                LeftMenuInstaller::syncUser($userId, true);
                continue;
            }

            $shouldAllow = isset($selected[$userId]);
            $currentlyAllowed = in_array($groupId, array_map('intval', \CUser::GetUserGroup($userId)), true);
            if ($shouldAllow && !$currentlyAllowed) {
                \CUser::AppendUserGroup($userId, $groupId);
            } elseif (!$shouldAllow && $currentlyAllowed) {
                \CUser::RemoveUserGroup($userId, [$groupId]);
            }

            LeftMenuInstaller::syncUser($userId, $shouldAllow);
        }
    }
}
