<?php

namespace EasyBusyChats\Service;

use Bitrix\Main\Application;
use Bitrix\Main\Type\DateTime;
use EasyBusyChats\Exception\IntegrationException;
use EasyBusyChats\Model\ChatContactTable;
use EasyBusyChats\Model\ChatDealTable;

final class MappingService
{
    public function getChatMappings(string $siteId, array $chat): array
    {
        $key = $this->keyFilter($siteId, $chat);
        $contact = ChatContactTable::getList([
            'filter' => $key,
            'limit' => 1,
        ])->fetch() ?: null;
        $deals = ChatDealTable::getList([
            'filter' => $key,
            'order' => ['ID' => 'ASC'],
        ])->fetchAll();

        return ['contact' => $contact, 'deals' => $deals];
    }

    public function linkContact(
        string $siteId,
        array $chat,
        int $contactId,
        int $userId,
        bool $replace = false,
        string $source = 'manual'
    ): array {
        $this->assertAccount($chat);
        $connection = Application::getConnection();
        $connection->startTransaction();
        try {
            $key = $this->keyFilter($siteId, $chat);
            $existing = ChatContactTable::getList(['filter' => $key, 'limit' => 1])->fetch();
            $fields = $this->contactFields($siteId, $chat, $contactId, $userId, $existing ?: null, $source);

            if ($existing) {
                $existingContactId = (int)$existing['BITRIX_CONTACT_ID'];
                if ($existingContactId !== $contactId && !$replace) {
                    throw new IntegrationException(
                        'This chat is already linked to another contact.',
                        'MAPPING_CONFLICT',
                        false,
                        ['contactId' => $existingContactId]
                    );
                }
                if ($existingContactId !== $contactId) {
                    ChatDealTable::deleteByFilter($key);
                }
                $result = ChatContactTable::update((int)$existing['ID'], $fields);
            } else {
                $result = ChatContactTable::add($fields);
            }

            if (!$result->isSuccess()) {
                throw new IntegrationException('Could not save contact mapping.', 'DATABASE_ERROR', true);
            }
            $connection->commitTransaction();

            return $this->getChatMappings($siteId, $chat);
        } catch (IntegrationException $error) {
            $connection->rollbackTransaction();
            throw $error;
        } catch (\Throwable $error) {
            $connection->rollbackTransaction();
            throw new IntegrationException('Could not save contact mapping.', 'DATABASE_ERROR', true);
        }
    }

    public function unlinkContact(string $siteId, array $chat): void
    {
        $connection = Application::getConnection();
        $connection->startTransaction();
        try {
            $key = $this->keyFilter($siteId, $chat);
            ChatDealTable::deleteByFilter($key);
            ChatContactTable::deleteByFilter($key);
            $connection->commitTransaction();
        } catch (\Throwable) {
            $connection->rollbackTransaction();
            throw new IntegrationException('Could not unlink contact.', 'DATABASE_ERROR', true);
        }
    }

    public function linkDeal(string $siteId, array $chat, int $dealId, int $userId): array
    {
        $this->assertAccount($chat);
        $key = $this->keyFilter($siteId, $chat);
        $existingContact = ChatContactTable::getList(['filter' => $key, 'limit' => 1])->fetch();
        if (!$existingContact) {
            throw new IntegrationException('Link a contact before linking a deal.', 'LINK_CONTACT_FIRST');
        }

        $existing = ChatDealTable::getList([
            'filter' => $key + ['=BITRIX_DEAL_ID' => $dealId],
            'limit' => 1,
        ])->fetch();
        if (!$existing) {
            $now = new DateTime();
            $result = ChatDealTable::add([
                'SITE_ID' => $siteId,
                'EASYBUSY_COMPANY_ID' => $chat['companyId'],
                'CHAT_ID' => $chat['chatId'],
                'ACCOUNT_ID' => $chat['accountId'],
                'BITRIX_DEAL_ID' => $dealId,
                'CREATED_BY' => $userId,
                'CREATED_AT' => $now,
                'UPDATED_AT' => $now,
            ]);
            if (!$result->isSuccess()) {
                $retry = ChatDealTable::getList([
                    'filter' => $key + ['=BITRIX_DEAL_ID' => $dealId],
                    'limit' => 1,
                ])->fetch();
                if (!$retry) {
                    throw new IntegrationException('Could not save deal mapping.', 'DATABASE_ERROR', true);
                }
            }
        }

        return $this->getChatMappings($siteId, $chat);
    }

    public function unlinkDeal(string $siteId, array $chat, int $dealId): void
    {
        ChatDealTable::deleteByFilter($this->keyFilter($siteId, $chat) + ['=BITRIX_DEAL_ID' => $dealId]);
    }

    public function getContactChats(string $siteId, int $contactId): array
    {
        return ChatContactTable::getList([
            'filter' => ['=SITE_ID' => $siteId, '=BITRIX_CONTACT_ID' => $contactId],
            'order' => ['UPDATED_AT' => 'DESC'],
        ])->fetchAll();
    }

    public function getDealChats(string $siteId, int $dealId): array
    {
        $dealMappings = ChatDealTable::getList([
            'filter' => ['=SITE_ID' => $siteId, '=BITRIX_DEAL_ID' => $dealId],
            'order' => ['UPDATED_AT' => 'DESC'],
        ])->fetchAll();
        if ($dealMappings === []) {
            return [];
        }

        $items = [];
        foreach ($dealMappings as $mapping) {
            $contact = ChatContactTable::getList([
                'filter' => $this->keyFilter($siteId, [
                    'companyId' => (string)$mapping['EASYBUSY_COMPANY_ID'],
                    'chatId' => (string)$mapping['CHAT_ID'],
                    'accountId' => (string)($mapping['ACCOUNT_ID'] ?? ''),
                ]),
                'limit' => 1,
            ])->fetch();
            if ($contact) {
                $items[] = $contact + ['BITRIX_DEAL_ID' => $dealId];
            }
        }

        return $items;
    }

    /**
     * A chat id is the peer on the messenger (a Telegram user id, a phone), so the same person
     * on two accounts has the same chat id. The account tells those chats apart. Without an
     * account only rows saved without one match, never another account's chat.
     */
    private function keyFilter(string $siteId, array $chat): array
    {
        $accountId = (string)($chat['accountId'] ?? '');

        return [
            '=SITE_ID' => $siteId,
            '=EASYBUSY_COMPANY_ID' => $chat['companyId'],
            '=CHAT_ID' => $chat['chatId'],
            // The contact column is NOT NULL, so rows saved without an account hold ''.
            '=ACCOUNT_ID' => $accountId,
        ];
    }

    // New links must name the account, otherwise they could not be told apart from other accounts' chats.
    private function assertAccount(array $chat): void
    {
        if ((string)($chat['accountId'] ?? '') === '') {
            throw new IntegrationException('The chat account is missing.', 'CHAT_ACCOUNT_REQUIRED');
        }
    }

    private function contactFields(
        string $siteId,
        array $chat,
        int $contactId,
        int $userId,
        ?array $existing,
        string $source
    ): array {
        $now = new DateTime();
        $allowedSources = ['manual', 'contact_created', 'phone_auto', 'phone_selected'];
        if (!in_array($source, $allowedSources, true)) {
            $source = 'manual';
        }
        if ($existing && (int)$existing['BITRIX_CONTACT_ID'] === $contactId) {
            $source = (string)($existing['LINK_SOURCE'] ?? 'manual');
        }
        $fields = [
            'SITE_ID' => $siteId,
            'EASYBUSY_COMPANY_ID' => $chat['companyId'],
            'CHAT_ID' => $chat['chatId'],
            'ACCOUNT_ID' => $chat['accountId'],
            'PROVIDER' => $chat['provider'],
            'EXTERNAL_CONTACT_ID' => $chat['externalContactId'],
            'BITRIX_CONTACT_ID' => $contactId,
            'LINK_SOURCE' => $source,
            'CHAT_TITLE' => $chat['title'],
            'AVATAR_URL' => $chat['avatarUrl'],
            'LAST_MESSAGE_AT' => $chat['lastMessageAt'],
            'CREATED_BY' => $existing ? (int)$existing['CREATED_BY'] : $userId,
            'CREATED_AT' => $existing ? $existing['CREATED_AT'] : $now,
            'UPDATED_AT' => $now,
        ];

        return $fields;
    }
}
