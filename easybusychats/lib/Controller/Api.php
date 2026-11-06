<?php

namespace EasyBusyChats\Controller;

use Bitrix\Main\Application;
use Bitrix\Main\Engine;
use Bitrix\Main\Error;
use EasyBusyChats\Config;
use EasyBusyChats\Exception\IntegrationException;
use EasyBusyChats\Service\ChatValidator;
use EasyBusyChats\Service\BoxAuthService;
use EasyBusyChats\Service\CrmService;
use EasyBusyChats\Service\Logger;
use EasyBusyChats\Service\MappingService;
use EasyBusyChats\Service\PhoneLinkService;
use EasyBusyChats\Service\AccessPolicyService;

final class Api extends Engine\Controller
{
    private const MUTATING_ACTIONS = [
        'createContact', 'linkContact', 'unlinkContact', 'linkNewChat',
        'createDeal', 'linkDeal', 'unlinkDeal',
        'getRuntimeBootstrap',
        'resolveContactByPhone', 'selectContactByPhone',
    ];

    public function configureActions(): array
    {
        $actions = [];
        foreach (self::MUTATING_ACTIONS as $action) {
            $actions[$action] = [
                'prefilters' => [
                    new Engine\ActionFilter\Authentication(),
                    new Engine\ActionFilter\HttpMethod([Engine\ActionFilter\HttpMethod::METHOD_POST]),
                    new Engine\ActionFilter\Csrf(),
                ],
            ];
        }

        return $actions;
    }

    public function getBootstrapAction(): ?array
    {
        return $this->execute('getBootstrap', function (): array {
            $crm = $this->crm();

            return [
                'protocolVersion' => 1,
                'siteId' => $this->siteId(),
                'language' => Config::getDefaultLanguage(),
                'pluginOrigin' => Config::getPluginOrigin(),
                'requestTimeout' => Config::getRequestTimeout(),
                'features' => [
                    'contacts' => Config::isContactIntegrationEnabled(),
                    'deals' => Config::isDealIntegrationEnabled(),
                ],
                'permissions' => $crm->getPermissions()->getCapabilities(),
                'authStatus' => $this->auth()->getStatus(),
            ];
        });
    }

    public function getRuntimeBootstrapAction(): ?array
    {
        return $this->execute('getRuntimeBootstrap', function (): array {
            return [
                'easyBusy' => $this->auth()->getRuntimeBootstrap($this->siteId(), $this->userId()),
            ];
        });
    }

    public function getChatContextAction(array $chat = []): ?array
    {
        return $this->execute('getChatContext', function () use ($chat): array {
            return $this->buildContext(ChatValidator::normalize($chat));
        }, $chat);
    }

    public function searchContactsAction(string $query = '', int $limit = 20): ?array
    {
        return $this->execute('searchContacts', function () use ($query, $limit): array {
            if (!Config::isContactIntegrationEnabled()) {
                throw new IntegrationException('Contact integration is disabled.', 'CONTACT_INTEGRATION_DISABLED');
            }

            return ['items' => $this->crm()->searchContacts($query, $limit)];
        });
    }

    public function createContactAction(array $chat = [], array $contact = []): ?array
    {
        return $this->execute('createContact', function () use ($chat, $contact): array {
            $this->assertContactEnabled();
            $normalized = ChatValidator::normalize($chat);
            $data = [
                'name' => trim((string)($contact['name'] ?? $normalized['name'] ?? $normalized['title'])),
                'phone' => trim((string)($contact['phone'] ?? $normalized['phone'])),
                'email' => trim((string)($contact['email'] ?? $normalized['email'])),
            ];
            $crm = $this->crm();
            $duplicates = $crm->findContactDuplicates($data['phone'], $data['email']);
            if ($duplicates !== []) {
                return ['status' => 'duplicate', 'candidates' => $duplicates];
            }

            $created = $crm->createContact($data);
            $this->mappings()->linkContact(
                $this->siteId(),
                $normalized,
                (int)$created['id'],
                $this->userId(),
                false,
                'contact_created'
            );

            return ['status' => 'created', 'contact' => $created, 'context' => $this->buildContext($normalized)];
        }, $chat);
    }

    public function linkContactAction(array $chat = [], int $contactId = 0, bool $replace = false): ?array
    {
        return $this->execute('linkContact', function () use ($chat, $contactId, $replace): array {
            $this->assertContactEnabled();
            $normalized = ChatValidator::normalize($chat);
            $contactId = ChatValidator::positiveId($contactId, 'contactId');
            $crm = $this->crm();
            $crm->getPermissions()->assertCanUpdateContact($contactId);
            $crm->getContact($contactId);
            $this->mappings()->linkContact(
                $this->siteId(),
                $normalized,
                $contactId,
                $this->userId(),
                $replace
            );

            return ['context' => $this->buildContext($normalized)];
        }, $chat);
    }

    public function unlinkContactAction(array $chat = []): ?array
    {
        return $this->execute('unlinkContact', function () use ($chat): array {
            $this->assertContactEnabled();
            $normalized = ChatValidator::normalize($chat);
            $mapping = $this->mappings()->getChatMappings($this->siteId(), $normalized);
            if ($mapping['contact']) {
                $this->crm()->getPermissions()->assertCanUpdateContact((int)$mapping['contact']['BITRIX_CONTACT_ID']);
                $this->mappings()->unlinkContact($this->siteId(), $normalized);
            }

            return ['context' => $this->buildContext($normalized)];
        }, $chat);
    }

    public function resolveContactByPhoneAction(array $chat = []): ?array
    {
        return $this->execute('resolveContactByPhone', function () use ($chat): array {
            $this->assertContactEnabled();
            $normalized = ChatValidator::normalize($chat);
            $result = $this->phoneLinks()->resolve($normalized);
            $result['context'] = $this->buildContext($result['chat']);
            unset($result['chat']);

            return $result;
        }, $chat);
    }

    public function selectContactByPhoneAction(array $chat = [], int $contactId = 0): ?array
    {
        return $this->execute('selectContactByPhone', function () use ($chat, $contactId): array {
            $this->assertContactEnabled();
            $normalized = ChatValidator::normalize($chat);
            $contactId = ChatValidator::positiveId($contactId, 'contactId');
            $result = $this->phoneLinks()->select($normalized, $contactId);
            $result['context'] = $this->buildContext($result['chat']);
            unset($result['chat']);

            return $result;
        }, $chat);
    }

    public function searchDealsAction(string $query = '', int $contactId = 0, int $limit = 20): ?array
    {
        return $this->execute('searchDeals', function () use ($query, $contactId, $limit): array {
            $this->assertDealEnabled();

            return ['items' => $this->crm()->searchDeals($query, $contactId > 0 ? $contactId : null, $limit)];
        });
    }

    public function createDealAction(array $chat = [], string $title = ''): ?array
    {
        return $this->execute('createDeal', function () use ($chat, $title): array {
            $this->assertDealEnabled();
            $normalized = ChatValidator::normalize($chat);
            $mapping = $this->requireContactMapping($normalized);
            $contactId = (int)$mapping['BITRIX_CONTACT_ID'];
            $deal = $this->crm()->createDeal($contactId, $title);
            $this->mappings()->linkDeal($this->siteId(), $normalized, (int)$deal['id'], $this->userId());

            return ['deal' => $deal, 'context' => $this->buildContext($normalized)];
        }, $chat);
    }

    public function linkDealAction(array $chat = [], int $dealId = 0): ?array
    {
        return $this->execute('linkDeal', function () use ($chat, $dealId): array {
            $this->assertDealEnabled();
            $normalized = ChatValidator::normalize($chat);
            $dealId = ChatValidator::positiveId($dealId, 'dealId');
            $mapping = $this->requireContactMapping($normalized);
            $contactId = (int)$mapping['BITRIX_CONTACT_ID'];
            $crm = $this->crm();
            $crm->getPermissions()->assertCanUpdateDeal($dealId);
            $crm->assertDealMatchesContact($dealId, $contactId);
            $this->mappings()->linkDeal($this->siteId(), $normalized, $dealId, $this->userId());

            return ['context' => $this->buildContext($normalized)];
        }, $chat);
    }

    public function unlinkDealAction(array $chat = [], int $dealId = 0): ?array
    {
        return $this->execute('unlinkDeal', function () use ($chat, $dealId): array {
            $this->assertDealEnabled();
            $normalized = ChatValidator::normalize($chat);
            $dealId = ChatValidator::positiveId($dealId, 'dealId');
            $this->crm()->getPermissions()->assertCanUpdateDeal($dealId);
            $this->mappings()->unlinkDeal($this->siteId(), $normalized, $dealId);

            return ['context' => $this->buildContext($normalized)];
        }, $chat);
    }

    public function getContactChatsAction(int $contactId = 0): ?array
    {
        return $this->execute('getContactChats', function () use ($contactId): array {
            $contactId = ChatValidator::positiveId($contactId, 'contactId');
            $crm = $this->crm();
            $crm->getPermissions()->assertCanReadContact($contactId);
            $companyId = (string)($this->auth()->getStatus()['clientId'] ?? '');
            $canUnlink = true;
            try {
                $crm->getPermissions()->assertCanUpdateContact($contactId);
            } catch (IntegrationException) {
                $canUnlink = false;
            }

            return [
                'items' => array_map(
                    fn(array $item): array => $this->formatChatMapping($item, $canUnlink, $companyId),
                    $this->mappings()->getContactChats($this->siteId(), $contactId)
                ),
            ];
        });
    }

    /** Prefill for "New chat" in a CRM card: the contact, or the deal's primary contact. */
    public function getNewChatPresetAction(int $contactId = 0, int $dealId = 0): ?array
    {
        return $this->execute('getNewChatPreset', function () use ($contactId, $dealId): array {
            if ($dealId > 0) {
                $this->assertDealEnabled();
                $preset = $this->crm()->getNewChatPreset('deal', $dealId);
            } else {
                $this->assertContactEnabled();
                $preset = $this->crm()->getNewChatPreset('contact', ChatValidator::positiveId($contactId, 'contactId'));
            }

            return ['preset' => $preset];
        });
    }

    /**
     * Links a chat made with "New chat" in a CRM card to that card's record. For a deal the chat is
     * linked to the deal's primary contact first, because deal links require a contact link.
     */
    public function linkNewChatAction(array $chat = [], int $contactId = 0, int $dealId = 0, bool $replace = false): ?array
    {
        return $this->execute('linkNewChat', function () use ($chat, $contactId, $dealId, $replace): array {
            $normalized = ChatValidator::normalize($chat);
            $crm = $this->crm();
            if ($dealId > 0) {
                $this->assertDealEnabled();
                $crm->getPermissions()->assertCanUpdateDeal($dealId);
                $contactId = $crm->getDealPrimaryContactId($dealId);
                if ($contactId <= 0) {
                    throw new IntegrationException('The deal has no contact to link the chat to.', 'DEAL_HAS_NO_CONTACT');
                }
            } else {
                $this->assertContactEnabled();
                $contactId = ChatValidator::positiveId($contactId, 'contactId');
            }
            $crm->getPermissions()->assertCanUpdateContact($contactId);
            $crm->getContact($contactId);
            // An existing chat may come back for a known phone; it moves here only when the user confirmed ($replace).
            $this->mappings()->linkContact($this->siteId(), $normalized, $contactId, $this->userId(), $replace);
            if ($dealId > 0) {
                $this->mappings()->linkDeal($this->siteId(), $normalized, $dealId, $this->userId());
            }

            return ['context' => $this->buildContext($normalized)];
        }, $chat);
    }

    public function getDealChatsAction(int $dealId = 0): ?array
    {
        return $this->execute('getDealChats', function () use ($dealId): array {
            $dealId = ChatValidator::positiveId($dealId, 'dealId');
            $crm = $this->crm();
            $crm->getPermissions()->assertCanReadDeal($dealId);
            $companyId = (string)($this->auth()->getStatus()['clientId'] ?? '');
            $canUnlink = true;
            try {
                $crm->getPermissions()->assertCanUpdateDeal($dealId);
            } catch (IntegrationException) {
                $canUnlink = false;
            }

            return [
                'items' => array_map(
                    fn(array $item): array => $this->formatChatMapping($item, $canUnlink, $companyId),
                    $this->mappings()->getDealChats($this->siteId(), $dealId)
                ),
            ];
        });
    }

    private function buildContext(array $chat): array
    {
        $crm = $this->crm();
        $mappings = $this->mappings()->getChatMappings($this->siteId(), $chat);
        $contact = null;
        $deals = [];
        $hasContactMapping = $mappings['contact'] !== null;

        if ($mappings['contact'] && Config::isContactIntegrationEnabled()) {
            try {
                $contact = $crm->getContact((int)$mappings['contact']['BITRIX_CONTACT_ID']);
            } catch (IntegrationException $error) {
                if ($error->getErrorCode() !== 'CONTACT_ACCESS_DENIED') {
                    throw $error;
                }
            }
        }
        if (Config::isDealIntegrationEnabled()) {
            foreach ($mappings['deals'] as $dealMapping) {
                try {
                    $deals[] = $crm->getDeal((int)$dealMapping['BITRIX_DEAL_ID']);
                } catch (IntegrationException $error) {
                    if ($error->getErrorCode() !== 'DEAL_ACCESS_DENIED') {
                        throw $error;
                    }
                }
            }
        }

        return [
            'chat' => $this->formatNormalizedChat($chat),
            'hasContactMapping' => $hasContactMapping,
            'contact' => $contact,
            'deals' => $deals,
            'permissions' => $crm->getPermissions()->getCapabilities(),
            'features' => [
                'contacts' => Config::isContactIntegrationEnabled(),
                'deals' => Config::isDealIntegrationEnabled(),
            ],
        ];
    }

    private function requireContactMapping(array $chat): array
    {
        $mapping = $this->mappings()->getChatMappings($this->siteId(), $chat)['contact'];
        if (!$mapping) {
            throw new IntegrationException('Link a contact first.', 'LINK_CONTACT_FIRST');
        }

        return $mapping;
    }

    private function formatChatMapping(array $item, bool $canUnlink, string $companyId): array
    {
        return [
            'easyBusyCompanyId' => (string)$item['EASYBUSY_COMPANY_ID'],
            'chatId' => (string)$item['CHAT_ID'],
            'accountId' => (string)($item['ACCOUNT_ID'] ?? ''),
            'provider' => (string)($item['PROVIDER'] ?? ''),
            'externalContactId' => (string)($item['EXTERNAL_CONTACT_ID'] ?? ''),
            'title' => (string)($item['CHAT_TITLE'] ?? ''),
            'avatarUrl' => (string)($item['AVATAR_URL'] ?? ''),
            'lastMessageAt' => $item['LAST_MESSAGE_AT'] instanceof \Bitrix\Main\Type\DateTime
                ? $item['LAST_MESSAGE_AT']->format(DATE_ATOM)
                : null,
            'canUnlink' => $canUnlink,
            'linkBroken' => $companyId !== '' && (string)$item['EASYBUSY_COMPANY_ID'] !== $companyId,
            'linkSource' => (string)($item['LINK_SOURCE'] ?? 'manual'),
        ];
    }

    private function formatNormalizedChat(array $chat): array
    {
        return [
            'easyBusyCompanyId' => $chat['companyId'],
            'chatId' => $chat['chatId'],
            'accountId' => $chat['accountId'],
            'provider' => $chat['provider'],
            'externalContactId' => $chat['externalContactId'],
            'title' => $chat['title'],
            'avatarUrl' => $chat['avatarUrl'],
            'lastMessageAt' => $chat['lastMessageAt'] instanceof \Bitrix\Main\Type\DateTime
                ? $chat['lastMessageAt']->format(DATE_ATOM)
                : null,
        ];
    }

    private function assertContactEnabled(): void
    {
        if (!Config::isContactIntegrationEnabled()) {
            throw new IntegrationException('Contact integration is disabled.', 'CONTACT_INTEGRATION_DISABLED');
        }
    }

    private function assertDealEnabled(): void
    {
        if (!Config::isDealIntegrationEnabled()) {
            throw new IntegrationException('Deal integration is disabled.', 'DEAL_INTEGRATION_DISABLED');
        }
    }

    private function execute(string $operation, callable $callback, array $chat = []): ?array
    {
        $startedAt = microtime(true);
        try {
            $this->accessPolicy()->assertAllowed($this->userId());
            $result = $callback();
            Logger::write($operation, [
                'userId' => $this->userId(),
                'companyId' => $chat['easyBusyCompanyId'] ?? $chat['companyId'] ?? null,
                'chatId' => $chat['chatId'] ?? null,
                'result' => 'success',
                'durationMs' => (int)((microtime(true) - $startedAt) * 1000),
            ]);

            return $result;
        } catch (IntegrationException $error) {
            Logger::write($operation, [
                'userId' => $this->userId(),
                'companyId' => $chat['easyBusyCompanyId'] ?? $chat['companyId'] ?? null,
                'chatId' => $chat['chatId'] ?? null,
                'result' => 'error',
                'errorCode' => $error->getErrorCode(),
                'durationMs' => (int)((microtime(true) - $startedAt) * 1000),
            ]);
            $this->addError(new Error(
                $error->getMessage(),
                $error->getErrorCode(),
                ['retryable' => $error->isRetryable()] + $error->getDetails()
            ));

            return null;
        } catch (\Throwable $error) {
            Logger::write($operation, [
                'userId' => $this->userId(),
                'result' => 'error',
                'errorCode' => 'INTERNAL_ERROR',
                'exceptionClass' => get_class($error),
                'exceptionLocation' => basename($error->getFile()) . ':' . $error->getLine(),
                'durationMs' => (int)((microtime(true) - $startedAt) * 1000),
            ]);
            $this->addError(new Error('The CRM operation could not be completed.', 'INTERNAL_ERROR', ['retryable' => true]));

            return null;
        }
    }

    private function userId(): int
    {
        return (int)$this->getCurrentUser()->getId();
    }

    private function siteId(): string
    {
        if (defined('SITE_ID') && SITE_ID !== '') {
            return SITE_ID;
        }

        return (string)(Application::getInstance()->getContext()->getSite() ?: 's1');
    }

    private function crm(): CrmService
    {
        return new CrmService($this->userId());
    }

    private function mappings(): MappingService
    {
        return new MappingService();
    }

    private function auth(): BoxAuthService
    {
        return new BoxAuthService();
    }

    private function phoneLinks(): PhoneLinkService
    {
        return new PhoneLinkService($this->siteId(), $this->userId());
    }

    private function accessPolicy(): AccessPolicyService
    {
        return new AccessPolicyService();
    }
}
