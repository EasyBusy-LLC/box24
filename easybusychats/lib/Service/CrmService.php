<?php

namespace EasyBusyChats\Service;

use Bitrix\Crm\Binding\DealContactTable;
use Bitrix\Crm\Integrity\DuplicateCommunicationCriterion;
use Bitrix\Main\Loader;
use EasyBusyChats\Exception\IntegrationException;

final class CrmService
{
    private int $userId;
    private PermissionService $permissions;

    public function __construct(int $userId)
    {
        if (!Loader::includeModule('crm')) {
            throw new IntegrationException('CRM is unavailable.', 'CRM_UNAVAILABLE');
        }
        $this->userId = $userId;
        $this->permissions = new PermissionService($userId);
    }

    public function getPermissions(): PermissionService
    {
        return $this->permissions;
    }

    public function getContact(int $contactId): array
    {
        $this->permissions->assertCanReadContact($contactId);
        $row = \CCrmContact::GetListEx(
            [],
            ['=ID' => $contactId, 'CHECK_PERMISSIONS' => 'Y'],
            false,
            false,
            ['ID', 'NAME', 'SECOND_NAME', 'LAST_NAME', 'FORMATTED_NAME']
        )->Fetch();
        if (!$row) {
            throw new IntegrationException('Contact not found.', 'CONTACT_NOT_FOUND');
        }

        return $this->formatContact($row);
    }

    /**
     * Prefill for the plugin's create-contact dialog: the contact itself, or the deal's primary contact.
     * Returns [] when there is no readable contact.
     */
    public function getNewChatPreset(string $entityType, int $entityId): array
    {
        if ($entityType === 'deal') {
            $entityId = $this->getDealPrimaryContactId($entityId);
        } elseif ($entityType !== 'contact') {
            return [];
        }
        if ($entityId <= 0) {
            return [];
        }
        $this->permissions->assertCanReadContact($entityId);
        $row = \CCrmContact::GetListEx(
            [],
            ['=ID' => $entityId, 'CHECK_PERMISSIONS' => 'Y'],
            false,
            false,
            ['ID', 'NAME', 'LAST_NAME']
        )->Fetch();
        if (!$row) {
            return [];
        }

        $firstName = trim((string)($row['NAME'] ?? ''));
        $lastName = trim((string)($row['LAST_NAME'] ?? ''));
        if ($firstName === '') {
            [$firstName, $lastName] = [$lastName, ''];
        }

        return array_filter([
            'firstName' => mb_substr($firstName, 0, 100),
            'lastName' => mb_substr($lastName, 0, 100),
            'phoneNumber' => $this->firstInternationalPhone($entityId),
        ], static fn(string $value): bool => $value !== '');
    }

    /** The deal's primary contact, or 0 when it has none. */
    public function getDealPrimaryContactId(int $dealId): int
    {
        $this->getDeal($dealId);

        return (int)\Bitrix\Crm\Binding\EntityBinding::getPrimaryEntityID(
            \CCrmOwnerType::Contact,
            DealContactTable::getDealBindings($dealId)
        );
    }

    /** E.164 form of the first phone that has a country code; local numbers are left for the user to enter. */
    private function firstInternationalPhone(int $contactId): string
    {
        $multi = \CCrmFieldMulti::GetListEx(
            ['ID' => 'ASC'],
            ['=ENTITY_ID' => 'CONTACT', '=ELEMENT_ID' => $contactId, '=TYPE_ID' => 'PHONE'],
            false,
            false,
            ['VALUE']
        );
        while ($item = $multi->Fetch()) {
            $digits = PhoneNormalizer::normalize($item['VALUE'] ?? '');
            if (PhoneNormalizer::isValid($digits) && $digits[0] !== '0' && strlen($digits) <= 15) {
                return '+' . $digits;
            }
        }

        return '';
    }

    public function searchContacts(string $rawQuery, int $limit = 20): array
    {
        $query = ChatValidator::searchQuery($rawQuery);
        $limit = max(1, min(50, $limit));
        $rows = [];

        if (ctype_digit($query)) {
            $byId = \CCrmContact::GetListEx(
                [],
                ['=ID' => (int)$query, 'CHECK_PERMISSIONS' => 'Y'],
                false,
                ['nTopCount' => 1],
                ['ID', 'NAME', 'SECOND_NAME', 'LAST_NAME', 'FORMATTED_NAME']
            )->Fetch();
            if ($byId) {
                $rows[(int)$byId['ID']] = $byId;
            }
        }

        $multiIds = [];
        $multiResult = \CCrmFieldMulti::GetListEx(
            ['ID' => 'DESC'],
            [
                '=ENTITY_ID' => 'CONTACT',
                '@TYPE_ID' => ['PHONE', 'EMAIL'],
                '%VALUE' => $query,
            ],
            false,
            ['nTopCount' => $limit],
            ['ELEMENT_ID']
        );
        while ($multi = $multiResult->Fetch()) {
            $multiIds[] = (int)$multi['ELEMENT_ID'];
        }
        if ($multiIds !== []) {
            $byMulti = \CCrmContact::GetListEx(
                ['ID' => 'DESC'],
                ['@ID' => array_values(array_unique($multiIds)), 'CHECK_PERMISSIONS' => 'Y'],
                false,
                ['nTopCount' => $limit],
                ['ID', 'NAME', 'SECOND_NAME', 'LAST_NAME', 'FORMATTED_NAME']
            );
            while ($row = $byMulti->Fetch()) {
                $rows[(int)$row['ID']] = $row;
            }
        }

        $byName = \CCrmContact::GetListEx(
            ['ID' => 'DESC'],
            ['%FULL_NAME' => $query, 'CHECK_PERMISSIONS' => 'Y'],
            false,
            ['nTopCount' => $limit],
            ['ID', 'NAME', 'SECOND_NAME', 'LAST_NAME', 'FORMATTED_NAME']
        );
        while ($row = $byName->Fetch()) {
            $rows[(int)$row['ID']] = $row;
            if (count($rows) >= $limit) {
                break;
            }
        }

        return array_map(fn(array $row): array => $this->formatContact($row), array_slice(array_values($rows), 0, $limit));
    }

    public function findContactDuplicates(string $phone, string $email): array
    {
        $candidateIds = [];
        foreach ([['PHONE', $phone], ['EMAIL', $email]] as [$type, $value]) {
            $value = trim((string)$value);
            if ($value === '') {
                continue;
            }
            $result = \CCrmFieldMulti::GetListEx(
                [],
                ['=ENTITY_ID' => 'CONTACT', '=TYPE_ID' => $type, '=VALUE' => $value],
                false,
                ['nTopCount' => 20],
                ['ELEMENT_ID']
            );
            while ($row = $result->Fetch()) {
                $candidateIds[] = (int)$row['ELEMENT_ID'];
            }
        }

        $duplicates = [];
        foreach (array_values(array_unique($candidateIds)) as $contactId) {
            try {
                $duplicates[] = $this->getContact($contactId);
            } catch (IntegrationException) {
                // Do not expose contacts unavailable to the current user.
            }
        }

        return $duplicates;
    }

    public function findContactIdsByExactPhone(string $phone, int $limit = 51): array
    {
        $this->permissions->assertCanReadContacts();
        $phone = PhoneNormalizer::normalize($phone);
        if (!PhoneNormalizer::isValid($phone)) {
            return [];
        }

        $criterion = new DuplicateCommunicationCriterion('PHONE', $phone);
        $duplicate = $criterion->find(\CCrmOwnerType::Contact, max(1, min(101, $limit)));
        $ids = [];
        foreach ($duplicate ? $duplicate->getEntitiesByType(\CCrmOwnerType::Contact) : [] as $entity) {
            $id = (int)$entity->getEntityID();
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    public function findContactIdsByPhoneTail(string $phone, int $limit = 51): array
    {
        $this->permissions->assertCanReadContacts();
        $phone = PhoneNormalizer::normalize($phone);
        if (!PhoneNormalizer::isValid($phone)) {
            return [];
        }
        $tail = substr($phone, -PhoneNormalizer::MIN_MATCH_DIGITS);
        $result = \CCrmContact::GetListEx(
            ['ID' => 'ASC'],
            [
                'SEARCH_CONTENT' => $tail,
                '__ENABLE_SEARCH_CONTENT_PHONE_DETECTION' => false,
                'CHECK_PERMISSIONS' => 'Y',
            ],
            false,
            ['nTopCount' => max(1, min(101, $limit))],
            ['ID']
        );
        $ids = [];
        while ($row = $result->Fetch()) {
            $id = (int)($row['ID'] ?? 0);
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    public function getReadableContacts(array $contactIds): array
    {
        $items = [];
        foreach (array_values(array_unique(array_map('intval', $contactIds))) as $contactId) {
            if ($contactId <= 0) {
                continue;
            }
            try {
                $items[] = $this->getContact($contactId);
            } catch (IntegrationException $error) {
                if (!in_array($error->getErrorCode(), ['CONTACT_ACCESS_DENIED', 'CONTACT_NOT_FOUND'], true)) {
                    throw $error;
                }
            }
        }

        return $items;
    }

    public function createContact(array $data): array
    {
        $this->permissions->assertCanAddContact();
        $name = trim(strip_tags((string)($data['name'] ?? '')));
        $phone = trim((string)($data['phone'] ?? ''));
        $email = filter_var(trim((string)($data['email'] ?? '')), FILTER_VALIDATE_EMAIL)
            ? trim((string)$data['email'])
            : '';
        if ($name === '') {
            $name = 'EasyBusy contact';
        }

        $fields = [
            'NAME' => mb_substr($name, 0, 255),
            'ASSIGNED_BY_ID' => $this->userId,
            'OPENED' => 'Y',
            'FM' => [],
        ];
        if ($phone !== '') {
            $fields['FM']['PHONE'] = [['VALUE' => mb_substr($phone, 0, 64), 'VALUE_TYPE' => 'WORK']];
        }
        if ($email !== '') {
            $fields['FM']['EMAIL'] = [['VALUE' => mb_substr($email, 0, 255), 'VALUE_TYPE' => 'WORK']];
        }

        $entity = new \CCrmContact(false);
        $contactId = (int)$entity->Add($fields, true, ['CURRENT_USER' => $this->userId]);
        if ($contactId <= 0) {
            throw new IntegrationException('Could not create contact.', 'CONTACT_CREATE_FAILED', true);
        }

        return $this->getContact($contactId);
    }

    public function getDeal(int $dealId): array
    {
        $this->permissions->assertCanReadDeal($dealId);
        $row = \CCrmDeal::GetListEx(
            [],
            ['=ID' => $dealId, 'CHECK_PERMISSIONS' => 'Y'],
            false,
            false,
            ['ID', 'TITLE', 'CONTACT_ID', 'STAGE_ID', 'CATEGORY_ID']
        )->Fetch();
        if (!$row) {
            throw new IntegrationException('Deal not found.', 'DEAL_NOT_FOUND');
        }

        return $this->formatDeal($row);
    }

    public function searchDeals(string $rawQuery, ?int $contactId = null, int $limit = 20): array
    {
        $query = trim($rawQuery);
        if ($query !== '') {
            $query = ChatValidator::searchQuery($query);
        }
        $limit = max(1, min(50, $limit));
        $filter = ['CHECK_PERMISSIONS' => 'Y'];
        if ($contactId !== null && $contactId > 0) {
            $this->permissions->assertCanReadContact($contactId);
            $filter['=CONTACT_ID'] = $contactId;
        }
        if ($query !== '') {
            if (ctype_digit($query)) {
                $filter['=ID'] = (int)$query;
            } else {
                $filter['%TITLE'] = $query;
            }
        }

        $result = \CCrmDeal::GetListEx(
            ['ID' => 'DESC'],
            $filter,
            false,
            ['nTopCount' => $limit],
            ['ID', 'TITLE', 'CONTACT_ID', 'STAGE_ID', 'CATEGORY_ID']
        );
        $items = [];
        while ($row = $result->Fetch()) {
            $items[] = $this->formatDeal($row);
        }

        return $items;
    }

    public function createDeal(int $contactId, string $title): array
    {
        $this->permissions->assertCanReadContact($contactId);
        $this->permissions->assertCanAddDeal();
        $contact = $this->getContact($contactId);
        $title = trim(strip_tags($title));
        if ($title === '') {
            $title = ($contact['name'] ?: 'Contact') . ' — EasyBusy';
        }

        $fields = [
            'TITLE' => mb_substr($title, 0, 255),
            'CONTACT_ID' => $contactId,
            'CONTACT_IDS' => [$contactId],
            'ASSIGNED_BY_ID' => $this->userId,
            'OPENED' => 'Y',
        ];
        $entity = new \CCrmDeal(false);
        $dealId = (int)$entity->Add($fields, true, ['CURRENT_USER' => $this->userId]);
        if ($dealId <= 0) {
            throw new IntegrationException('Could not create deal.', 'DEAL_CREATE_FAILED', true);
        }

        return $this->getDeal($dealId);
    }

    public function assertDealMatchesContact(int $dealId, int $contactId): void
    {
        $deal = $this->getDeal($dealId);
        $contactIds = $deal['contactIds'];
        if ($contactIds !== [] && !in_array($contactId, $contactIds, true)) {
            throw new IntegrationException(
                'This deal belongs to another contact.',
                'DEAL_CONTACT_MISMATCH',
                false,
                ['dealId' => $dealId]
            );
        }
    }

    public function getContactUrl(int $contactId): string
    {
        $this->permissions->assertCanReadContact($contactId);

        return \CCrmOwnerType::GetEntityShowPath(\CCrmOwnerType::Contact, $contactId, false);
    }

    public function getDealUrl(int $dealId): string
    {
        $this->permissions->assertCanReadDeal($dealId);

        return \CCrmOwnerType::GetEntityShowPath(\CCrmOwnerType::Deal, $dealId, false);
    }

    private function formatContact(array $row): array
    {
        $contactId = (int)$row['ID'];
        $phones = [];
        $emails = [];
        $multi = \CCrmFieldMulti::GetListEx(
            ['ID' => 'ASC'],
            ['=ENTITY_ID' => 'CONTACT', '=ELEMENT_ID' => $contactId, '@TYPE_ID' => ['PHONE', 'EMAIL']],
            false,
            false,
            ['TYPE_ID', 'VALUE']
        );
        while ($item = $multi->Fetch()) {
            if ($item['TYPE_ID'] === 'PHONE') {
                $phones[] = (string)$item['VALUE'];
            } elseif ($item['TYPE_ID'] === 'EMAIL') {
                $emails[] = (string)$item['VALUE'];
            }
        }
        $name = trim((string)($row['FORMATTED_NAME'] ?? ''));
        if ($name === '') {
            $name = trim(implode(' ', array_filter([
                $row['NAME'] ?? '', $row['SECOND_NAME'] ?? '', $row['LAST_NAME'] ?? '',
            ])));
        }

        return [
            'id' => $contactId,
            'name' => $name !== '' ? $name : 'Contact #' . $contactId,
            'phones' => $phones,
            'emails' => $emails,
            'url' => \CCrmOwnerType::GetEntityShowPath(\CCrmOwnerType::Contact, $contactId, false),
        ];
    }

    private function formatDeal(array $row): array
    {
        $dealId = (int)$row['ID'];
        $contactIds = array_map('intval', DealContactTable::getDealContactIDs($dealId));
        if ($contactIds === [] && (int)($row['CONTACT_ID'] ?? 0) > 0) {
            $contactIds[] = (int)$row['CONTACT_ID'];
        }

        return [
            'id' => $dealId,
            'title' => (string)$row['TITLE'],
            'contactIds' => array_values(array_unique($contactIds)),
            'stageId' => (string)($row['STAGE_ID'] ?? ''),
            'categoryId' => (int)($row['CATEGORY_ID'] ?? 0),
            'url' => \CCrmOwnerType::GetEntityShowPath(\CCrmOwnerType::Deal, $dealId, false),
        ];
    }
}
