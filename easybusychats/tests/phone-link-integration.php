<?php

declare(strict_types=1);

define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
$_SERVER['DOCUMENT_ROOT'] = '/var/www/html';
$_SERVER['REQUEST_URI'] = '/bitrix/admin/';

ob_start();
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
ob_end_clean();

use Bitrix\Main\Loader;
use EasyBusyChats\Exception\IntegrationException;
use EasyBusyChats\Service\ChatValidator;
use EasyBusyChats\Service\CrmService;
use EasyBusyChats\Service\MappingService;
use EasyBusyChats\Service\PhoneContactMatcher;
use EasyBusyChats\Service\PhoneLinkService;

if (!Loader::includeModule('easybusychats')) {
    throw new RuntimeException('easybusychats module is not registered');
}

$userId = 1;
$GLOBALS['USER']->Authorize($userId);
$siteId = 's1';
$marker = 'EB phone test ' . bin2hex(random_bytes(5));
$contactIds = [];
$mappingChats = [];
$mappings = new MappingService();

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$createContact = static function (string $name, string|array $phone) use (&$contactIds, $userId): int {
    $entity = new CCrmContact(false);
    $phones = is_array($phone) ? $phone : [$phone];
    $fields = [
        'NAME' => $name,
        'ASSIGNED_BY_ID' => $userId,
        'OPENED' => 'Y',
        'FM' => ['PHONE' => array_map(
            static fn(string $value): array => ['VALUE' => $value, 'VALUE_TYPE' => 'WORK'],
            $phones
        )],
    ];
    $options = ['CURRENT_USER' => $userId];
    $id = (int)$entity->Add($fields, true, $options);
    if ($id <= 0) {
        throw new RuntimeException('Could not create isolated test contact');
    }
    $contactIds[] = $id;

    return $id;
};
$chat = static function (string $suffix): array {
    return ChatValidator::normalize([
        'easyBusyCompanyId' => 'test-company-' . $suffix,
        'chatId' => 'test-chat-' . $suffix,
        'accountId' => 'test-account',
        'externalContactId' => '1234567890',
        'provider' => 'viberpersonal',
        'title' => 'EasyBusy phone integration test',
    ]);
};

$failure = null;
try {
    $crm = new CrmService($userId);
    $matcher = new PhoneContactMatcher($crm);

    $exactSuffix = (string)random_int(100000000, 999999999);
    $exactPhone = '380' . $exactSuffix;
    $exactId = $createContact($marker . ' exact', '+' . $exactPhone);
    $exact = $matcher->find('+380 (' . substr($exactSuffix, 0, 2) . ') ' . substr($exactSuffix, 2));
    $assert(count($exact['candidates']) === 1, 'exact match must be unique: ' . json_encode($exact));
    $assert((int)$exact['candidates'][0]['id'] === $exactId, 'exact match returned another contact');
    $assert($exact['matchType'] === 'exact', 'exact match type is missing');

    $tailSuffix = (string)random_int(100000000, 999999999);
    $tailId = $createContact($marker . ' tail', '+48' . $tailSuffix);
    $tail = $matcher->find('380' . $tailSuffix);
    $assert(count($tail['candidates']) === 1, 'tail match must be unique');
    $assert((int)$tail['candidates'][0]['id'] === $tailId, 'tail match returned another contact');
    $assert($tail['matchType'] === 'tail' && $tail['matchLength'] === 9, 'tail match metadata is invalid');

    $multiPhoneSuffix = (string)random_int(100000000, 999999999);
    $multiPhoneId = $createContact(
        $marker . ' multiple phones',
        ['+12025550182', '+48' . $multiPhoneSuffix]
    );
    $multiPhone = $matcher->find('380' . $multiPhoneSuffix);
    $assert(count($multiPhone['candidates']) === 1, 'one contact with multiple phones must be deduplicated');
    $assert((int)$multiPhone['candidates'][0]['id'] === $multiPhoneId, 'multiple-phone contact was not found');

    $sameExactSuffix = (string)random_int(100000000, 999999999);
    $sameExactPhone = '380' . $sameExactSuffix;
    $sameExactA = $createContact($marker . ' same exact A', '+' . $sameExactPhone);
    $sameExactB = $createContact($marker . ' same exact B', $sameExactPhone);
    $sameExact = $matcher->find($sameExactPhone);
    $sameExactIds = array_map(static fn(array $item): int => (int)$item['id'], $sameExact['candidates']);
    sort($sameExactIds);
    $expectedSameExactIds = [$sameExactA, $sameExactB];
    sort($expectedSameExactIds);
    $assert($sameExactIds === $expectedSameExactIds, 'same exact phone on two contacts must be ambiguous');

    $noMatch = $matcher->find('380' . (string)random_int(100000000, 999999999));
    $assert($noMatch['candidates'] === [], 'unknown phone unexpectedly matched a contact');

    $multipleSuffix = (string)random_int(100000000, 999999999);
    $multipleA = $createContact($marker . ' multiple A', '+48' . $multipleSuffix);
    $multipleB = $createContact($marker . ' multiple B', '+49' . $multipleSuffix);
    $multiple = $matcher->find('380' . $multipleSuffix);
    $multipleIds = array_map(static fn(array $item): int => (int)$item['id'], $multiple['candidates']);
    sort($multipleIds);
    $expectedIds = [$multipleA, $multipleB];
    sort($expectedIds);
    $assert($multipleIds === $expectedIds, 'multiple matches must stay ambiguous and deduplicated');

    $autoChat = $chat(bin2hex(random_bytes(6)));
    $mappingChats[] = $autoChat;
    $contactLoads = 0;
    $service = new PhoneLinkService(
        $siteId, $userId, null, $mappings, $crm, $matcher, null,
        static function () use (&$contactLoads, $exactPhone): array {
            $contactLoads++;
            return ['id' => '1234567890', 'phoneNumber' => $exactPhone];
        },
        static fn(): array => ['companyId' => $autoChat['companyId'], 'accessToken' => 'test-token']
    );
    $linked = $service->resolve($autoChat);
    $assert($linked['status'] === 'linked' && (int)$linked['contactId'] === $exactId, 'unique phone was not auto-linked');
    $autoMapping = $mappings->getChatMappings($siteId, $autoChat)['contact'];
    $assert(($autoMapping['LINK_SOURCE'] ?? '') === 'phone_auto', 'phone_auto source was not stored');
    $again = $service->resolve($autoChat);
    $assert($again['status'] === 'already_linked', 'repeated auto-link is not idempotent');
    $assert($contactLoads === 1, 'already linked chat performed another EasyBusy lookup');

    $selectedChat = $chat(bin2hex(random_bytes(6)));
    $mappingChats[] = $selectedChat;
    $selectionService = new PhoneLinkService(
        $siteId, $userId, null, $mappings, $crm, $matcher, null,
        static fn(): array => ['id' => '1234567890', 'phoneNumber' => '380' . $multipleSuffix],
        static fn(): array => ['companyId' => $selectedChat['companyId'], 'accessToken' => 'test-token']
    );
    $selection = $selectionService->select($selectedChat, $multipleB);
    $assert($selection['status'] === 'linked', 'explicit candidate selection failed');
    $selectedMapping = $mappings->getChatMappings($siteId, $selectedChat)['contact'];
    $assert((int)$selectedMapping['BITRIX_CONTACT_ID'] === $multipleB, 'wrong selected contact was linked');
    $assert(($selectedMapping['LINK_SOURCE'] ?? '') === 'phone_selected', 'phone_selected source was not stored');

    $manualChat = $chat(bin2hex(random_bytes(6)));
    $mappingChats[] = $manualChat;
    $mappings->linkContact($siteId, $manualChat, $exactId, $userId, false, 'manual');
    $manualLoads = 0;
    $manualService = new PhoneLinkService(
        $siteId, $userId, null, $mappings, $crm, $matcher, null,
        static function () use (&$manualLoads): array {
            $manualLoads++;
            return ['id' => '1234567890', 'phoneNumber' => '380000000000'];
        },
        static fn(): array => ['companyId' => $manualChat['companyId'], 'accessToken' => 'test-token']
    );
    $manualResult = $manualService->resolve($manualChat);
    $assert($manualResult['status'] === 'already_linked' && $manualLoads === 0, 'manual mapping was not protected');
    $assert(($mappings->getChatMappings($siteId, $manualChat)['contact']['LINK_SOURCE'] ?? '') === 'manual', 'manual source changed');

    $noPhoneChat = $chat(bin2hex(random_bytes(6)));
    $noPhoneService = new PhoneLinkService(
        $siteId, $userId, null, $mappings, $crm, $matcher, null,
        static fn(): ?array => null,
        static fn(): array => ['companyId' => $noPhoneChat['companyId'], 'accessToken' => 'test-token']
    );
    $assert($noPhoneService->resolve($noPhoneChat)['status'] === 'phone_missing', 'missing phone status is invalid');

    $shortPhoneChat = $chat(bin2hex(random_bytes(6)));
    $shortPhoneService = new PhoneLinkService(
        $siteId, $userId, null, $mappings, $crm, $matcher, null,
        static fn(): array => ['id' => '1234567890', 'phoneNumber' => '123-45'],
        static fn(): array => ['companyId' => $shortPhoneChat['companyId'], 'accessToken' => 'test-token']
    );
    $assert($shortPhoneService->resolve($shortPhoneChat)['status'] === 'phone_invalid', 'short phone status is invalid');

    $accessDenied = false;
    try {
        (new PhoneContactMatcher(new CrmService(2147480000)))->find($exactPhone);
    } catch (IntegrationException $error) {
        $accessDenied = $error->getErrorCode() === 'CONTACT_ACCESS_DENIED';
    }
    $assert($accessDenied, 'CRM read permission denial was not enforced');

    echo "phone linking integration tests passed\n";
} catch (Throwable $error) {
    $failure = $error;
} finally {
    foreach ($mappingChats as $mappingChat) {
        try {
            $mappings->unlinkContact($siteId, $mappingChat);
        } catch (Throwable) {
        }
    }
    foreach (array_reverse($contactIds) as $contactId) {
        try {
            (new CCrmContact(false))->Delete($contactId, ['CURRENT_USER' => $userId]);
        } catch (Throwable) {
        }
    }
}

if ($failure instanceof Throwable) {
    fwrite(STDERR, get_class($failure) . ': ' . $failure->getMessage() . "\n");
    exit(1);
}
