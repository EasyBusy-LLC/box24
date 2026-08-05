<?php

define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);

$_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__, 4);
$_SERVER['SCRIPT_NAME'] = '/bitrix/admin/php_command_line.php';
$_SERVER['REQUEST_URI'] = $_SERVER['SCRIPT_NAME'];

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

use Bitrix\Main\Loader;
use EasyBusyChats\Exception\IntegrationException;
use EasyBusyChats\Installer\AccessGroupInstaller;
use EasyBusyChats\Installer\LeftMenuInstaller;
use EasyBusyChats\Service\AccessPolicyService;
use EasyBusyChats\Service\BoxAuthService;

if (!Loader::includeModule('easybusychats')) {
    throw new RuntimeException('easybusychats module is not registered');
}

$policy = new AccessPolicyService();
$groupId = AccessGroupInstaller::getGroupId();
$testUserId = (int)(getenv('EASYBUSYCHATS_POLICY_TEST_USER_ID') ?: 4);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$assert($groupId > 0, 'Access group was not installed');
$assert($policy->isAllowed(1), 'Bitrix administrator must always be allowed');

$originallyAllowed = in_array($groupId, array_map('intval', CUser::GetUserGroup($testUserId)), true);
try {
    CUser::RemoveUserGroup($testUserId, [$groupId]);
    CAccess::ClearCache($testUserId);
    LeftMenuInstaller::syncUser($testUserId, false);
    $assert(!$policy->isAllowed($testUserId), 'Revoked user still has access');

    $denied = false;
    try {
        (new BoxAuthService())->getRuntimeBootstrap('s1', $testUserId);
    } catch (IntegrationException $error) {
        $denied = $error->getErrorCode() === 'EASYBUSY_ACCESS_DENIED';
    }
    $assert($denied, 'Runtime token issuance was not blocked');

    CUser::AppendUserGroup($testUserId, $groupId);
    CAccess::ClearCache($testUserId);
    LeftMenuInstaller::syncUser($testUserId, true);
    $assert($policy->isAllowed($testUserId), 'Granted user has no access');

    $menuItems = CUserOptions::GetOption('intranet', 'left_menu_self_items_s1', [], $testUserId);
    $hasMenuItem = false;
    foreach (is_array($menuItems) ? $menuItems : [] as $item) {
        if (is_array($item) && ($item['LINK'] ?? '') === '/easybusychats/') {
            $hasMenuItem = true;
            break;
        }
    }
    $assert($hasMenuItem, 'Granted user has no personal menu item');
} finally {
    if ($originallyAllowed) {
        CUser::AppendUserGroup($testUserId, $groupId);
        LeftMenuInstaller::syncUser($testUserId, true);
    } else {
        CUser::RemoveUserGroup($testUserId, [$groupId]);
        LeftMenuInstaller::syncUser($testUserId, false);
    }
    CAccess::ClearCache($testUserId);
}

echo json_encode([
    'groupId' => $groupId,
    'groupStringId' => AccessGroupInstaller::GROUP_STRING_ID,
    'administratorAllowed' => true,
    'revocationBlockedRuntimeToken' => true,
    'grantRestoredAccess' => true,
    'menuSynchronized' => true,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
