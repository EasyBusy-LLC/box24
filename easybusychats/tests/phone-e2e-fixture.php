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
use Bitrix\Main\Web\HttpClient;
use Bitrix\Main\Web\Json;
use EasyBusyChats\Config;
use EasyBusyChats\Model\ChatContactTable;
use EasyBusyChats\Service\ChatValidator;
use EasyBusyChats\Service\CrmService;
use EasyBusyChats\Service\EasyBusyApiClient;
use EasyBusyChats\Service\MappingService;
use EasyBusyChats\Service\PhoneContactMatcher;
use EasyBusyChats\Service\PhoneLinkService;
use EasyBusyChats\Service\PhoneNormalizer;
use EasyBusyChats\Service\BoxAuthService;

if (!Loader::includeModule('easybusychats')) {
    throw new RuntimeException('easybusychats module is not registered');
}

$userId = 1;
$siteId = 's1';
$GLOBALS['USER']->Authorize($userId);
$action = (string)($argv[1] ?? '');
$input = json_decode((string)($argv[2] ?? '{}'), true);
$input = is_array($input) ? $input : [];

try {
    if ($action === 'setup') {
        echo Json::encode(setupFixture($siteId, $userId), JSON_UNESCAPED_SLASHES) . PHP_EOL;
    } elseif ($action === 'create-contact') {
        $marker = validMarker($input['marker'] ?? '');
        $phone = PhoneNormalizer::normalize($input['phone'] ?? '');
        if (!PhoneNormalizer::isValid($phone)) {
            throw new RuntimeException('Fixture phone is invalid');
        }
        echo Json::encode([
            'contactId' => createContact($marker . ' delayed', $phone, $userId),
        ]) . PHP_EOL;
    } elseif ($action === 'resolve') {
        $chat = ChatValidator::normalize($input['chat'] ?? []);
        echo Json::encode((new PhoneLinkService($siteId, $userId))->resolve($chat), JSON_UNESCAPED_SLASHES) . PHP_EOL;
    } elseif ($action === 'cleanup') {
        cleanupFixture($siteId, $input, $userId);
        echo Json::encode(['cleaned' => true]) . PHP_EOL;
    } else {
        throw new RuntimeException('Unknown fixture action');
    }
} catch (Throwable $error) {
    fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . PHP_EOL);
    exit(1);
}

function setupFixture(string $siteId, int $userId): array
{
    $marker = 'EB phone E2E ' . bin2hex(random_bytes(6));
    $runtime = (new BoxAuthService())->getRuntimeBootstrap($siteId, $userId);
    $companyId = (string)$runtime['companyId'];
    $token = (string)$runtime['accessToken'];
    $source = ChatContactTable::getList([
        'select' => ['PROVIDER', 'ACCOUNT_ID'],
        'filter' => [
            '=SITE_ID' => $siteId,
            '=EASYBUSY_COMPANY_ID' => $companyId,
            '!PROVIDER' => '',
            '!ACCOUNT_ID' => '',
        ],
        'order' => ['ID' => 'DESC'],
        'limit' => 20,
    ])->fetchAll();
    if ($source === []) {
        throw new RuntimeException('A mapped EasyBusy account is required for the E2E fixture');
    }

    $crm = new CrmService($userId);
    $matcher = new PhoneContactMatcher($crm);
    $api = new EasyBusyApiClient();
    $selected = [];
    $seenPhones = [];

    foreach ($source as $account) {
        $provider = (string)$account['PROVIDER'];
        $accountId = (string)$account['ACCOUNT_ID'];
        foreach (loadChats($token, $companyId, $provider, $accountId) as $rawChat) {
            $chatId = trim((string)($rawChat['id'] ?? $rawChat['chatId'] ?? ''));
            if ($chatId === '') {
                continue;
            }
            $mapped = ChatContactTable::getList([
                'select' => ['ID'],
                'filter' => [
                    '=SITE_ID' => $siteId,
                    '=EASYBUSY_COMPANY_ID' => $companyId,
                    '=CHAT_ID' => $chatId,
                ],
                'limit' => 1,
            ])->fetch();
            if ($mapped) {
                continue;
            }
            $externalContactId = trim((string)(
                $rawChat['externalContactId']
                ?? $rawChat['contactId']
                ?? ($rawChat['contact']['id'] ?? '')
            ));
            $chat = ChatValidator::normalize([
                'easyBusyCompanyId' => $companyId,
                'chatId' => $chatId,
                'accountId' => $accountId,
                'provider' => $provider,
                'externalContactId' => $externalContactId,
                'title' => (string)($rawChat['title'] ?? $rawChat['name'] ?? 'E2E chat'),
            ]);
            try {
                $contact = $api->getContact($token, $chat);
            } catch (Throwable) {
                continue;
            }
            $phone = PhoneNormalizer::normalize($contact['phoneNumber'] ?? '');
            if (!PhoneNormalizer::isValid($phone) || isset($seenPhones[$phone])) {
                continue;
            }
            try {
                if ($matcher->find($phone)['candidates'] !== []) {
                    continue;
                }
            } catch (Throwable) {
                continue;
            }
            $chat['externalContactId'] = (string)($contact['id'] ?? $chat['externalContactId']);
            $seenPhones[$phone] = true;
            $selected[] = [
                'chat' => [
                    'easyBusyCompanyId' => $chat['companyId'],
                    'chatId' => $chat['chatId'],
                    'accountId' => $chat['accountId'],
                    'provider' => $chat['provider'],
                    'externalContactId' => $chat['externalContactId'],
                ],
                'phone' => $phone,
            ];
            if (count($selected) === 3) {
                break 2;
            }
        }
    }
    if (count($selected) < 3) {
        throw new RuntimeException('Three unlinked EasyBusy chats with unused phones are required');
    }

    $contactIds = [];
    try {
        $uniqueId = createContact($marker . ' unique', $selected[0]['phone'], $userId);
        $contactIds[] = $uniqueId;
        $multipleA = createContact($marker . ' multiple A', $selected[1]['phone'], $userId);
        $contactIds[] = $multipleA;
        $multipleB = createContact($marker . ' multiple B', $selected[1]['phone'], $userId);
        $contactIds[] = $multipleB;

        return [
            'marker' => $marker,
            'contactIds' => $contactIds,
            'unique' => $selected[0] + ['contactId' => $uniqueId],
            'multiple' => $selected[1] + ['contactIds' => [$multipleA, $multipleB]],
            'none' => $selected[2],
        ];
    } catch (Throwable $error) {
        cleanupContacts($contactIds, $marker, $userId);
        throw $error;
    }
}

function loadChats(string $token, string $companyId, string $provider, string $accountId): array
{
    $client = new HttpClient([
        'socketTimeout' => 15,
        'streamTimeout' => 15,
        'redirect' => false,
        'disableSslVerification' => false,
    ]);
    $client->setHeader('Accept', 'application/json');
    $client->setHeader('Authorization', 'Bearer ' . $token);
    $url = sprintf(
        '%s/%s/%s/account/%s/chats?Offset=0&Take=100',
        Config::getEasyBusyApiBaseUrl(),
        rawurlencode($provider),
        rawurlencode($companyId),
        rawurlencode($accountId)
    );
    $response = $client->get($url);
    if ((int)$client->getStatus() !== 200 || !is_string($response)) {
        return [];
    }
    $payload = Json::decode($response);
    if (isset($payload['items']) && is_array($payload['items'])) {
        $payload = $payload['items'];
    }

    return is_array($payload) ? array_values(array_filter($payload, 'is_array')) : [];
}

function createContact(string $name, string $phone, int $userId): int
{
    $fields = [
        'NAME' => $name,
        'ASSIGNED_BY_ID' => $userId,
        'OPENED' => 'Y',
        'FM' => ['PHONE' => [['VALUE' => $phone, 'VALUE_TYPE' => 'WORK']]],
    ];
    $options = ['CURRENT_USER' => $userId];
    $id = (int)(new CCrmContact(false))->Add($fields, true, $options);
    if ($id <= 0) {
        throw new RuntimeException('Could not create E2E contact');
    }

    return $id;
}

function cleanupFixture(string $siteId, array $fixture, int $userId): void
{
    $marker = validMarker($fixture['marker'] ?? '');
    $contactIds = array_values(array_unique(array_filter(array_map('intval', $fixture['contactIds'] ?? []))));
    $mappings = new MappingService();
    foreach (['unique', 'multiple', 'none'] as $key) {
        $rawChat = $fixture[$key]['chat'] ?? null;
        if (!is_array($rawChat)) {
            continue;
        }
        try {
            $chat = ChatValidator::normalize($rawChat);
            $mapping = $mappings->getChatMappings($siteId, $chat)['contact'];
            if ($mapping && in_array((int)$mapping['BITRIX_CONTACT_ID'], $contactIds, true)) {
                $mappings->unlinkContact($siteId, $chat);
            }
        } catch (Throwable) {
        }
    }
    cleanupContacts($contactIds, $marker, $userId);
}

function cleanupContacts(array $contactIds, string $marker, int $userId): void
{
    foreach ($contactIds as $contactId) {
        $row = CCrmContact::GetListEx([], ['=ID' => (int)$contactId, 'CHECK_PERMISSIONS' => 'N'], false, false, ['ID', 'NAME'])->Fetch();
        if (!$row || !str_starts_with((string)$row['NAME'], $marker)) {
            continue;
        }
        (new CCrmContact(false))->Delete((int)$contactId, ['CURRENT_USER' => $userId]);
    }
}

function validMarker(mixed $value): string
{
    $marker = trim((string)$value);
    if (!preg_match('/^EB phone E2E [a-f0-9]{12}$/', $marker)) {
        throw new RuntimeException('Fixture marker is invalid');
    }

    return $marker;
}
