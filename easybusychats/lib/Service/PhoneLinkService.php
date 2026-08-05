<?php

namespace EasyBusyChats\Service;

use Bitrix\Main\Application;
use EasyBusyChats\Exception\IntegrationException;

final class PhoneLinkService
{
    private EasyBusyApiClient $api;
    private MappingService $mappings;
    private CrmService $crm;
    private PhoneContactMatcher $matcher;
    private BoxAuthService $auth;

    public function __construct(
        private readonly string $siteId,
        private readonly int $userId,
        ?EasyBusyApiClient $api = null,
        ?MappingService $mappings = null,
        ?CrmService $crm = null,
        ?PhoneContactMatcher $matcher = null,
        ?BoxAuthService $auth = null,
        private readonly ?\Closure $contactLoader = null,
        private readonly ?\Closure $runtimeLoader = null
    ) {
        $this->api = $api ?? new EasyBusyApiClient();
        $this->mappings = $mappings ?? new MappingService();
        $this->crm = $crm ?? new CrmService($userId);
        $this->matcher = $matcher ?? new PhoneContactMatcher($this->crm);
        $this->auth = $auth ?? new BoxAuthService();
    }

    public function resolve(array $chat): array
    {
        return $this->withChatLock($chat, function (array $chat): array {
            $existing = $this->mappings->getChatMappings($this->siteId, $chat)['contact'];
            if ($existing) {
                return ['status' => 'already_linked', 'chat' => $chat];
            }

            [$chat, $phone] = $this->loadPhone($chat);
            if ($phone === null) {
                return ['status' => 'phone_missing', 'chat' => $chat, 'candidates' => []];
            }
            if (!PhoneNormalizer::isValid($phone)) {
                return ['status' => 'phone_invalid', 'chat' => $chat, 'candidates' => []];
            }

            $match = $this->matcher->find($phone);
            $candidates = $match['candidates'];
            if ($candidates === []) {
                return ['status' => 'not_found', 'chat' => $chat] + $match;
            }
            if (count($candidates) !== 1 || !empty($match['truncated'])) {
                return ['status' => 'multiple', 'chat' => $chat] + $match;
            }

            $contactId = (int)$candidates[0]['id'];
            $this->crm->getPermissions()->assertCanUpdateContact($contactId);
            $this->mappings->linkContact(
                $this->siteId,
                $chat,
                $contactId,
                $this->userId,
                false,
                'phone_auto'
            );

            return ['status' => 'linked', 'chat' => $chat, 'contactId' => $contactId] + $match;
        });
    }

    public function select(array $chat, int $contactId): array
    {
        if ($contactId <= 0) {
            throw new IntegrationException('Invalid contactId.', 'INVALID_CRM_ID');
        }

        return $this->withChatLock($chat, function (array $chat) use ($contactId): array {
            $existing = $this->mappings->getChatMappings($this->siteId, $chat)['contact'];
            if ($existing) {
                return ['status' => 'already_linked', 'chat' => $chat];
            }

            [$chat, $phone] = $this->loadPhone($chat);
            if ($phone === null) {
                return ['status' => 'phone_missing', 'chat' => $chat, 'candidates' => []];
            }
            if (!PhoneNormalizer::isValid($phone)) {
                return ['status' => 'phone_invalid', 'chat' => $chat, 'candidates' => []];
            }

            $match = $this->matcher->find($phone);
            $selected = null;
            foreach ($match['candidates'] as $candidate) {
                if ((int)$candidate['id'] === $contactId) {
                    $selected = $candidate;
                    break;
                }
            }
            if ($selected === null) {
                throw new IntegrationException(
                    'The selected contact no longer matches the EasyBusy phone number.',
                    'PHONE_CONTACT_SELECTION_INVALID'
                );
            }

            $this->crm->getPermissions()->assertCanUpdateContact($contactId);
            $this->mappings->linkContact(
                $this->siteId,
                $chat,
                $contactId,
                $this->userId,
                false,
                'phone_selected'
            );

            return ['status' => 'linked', 'chat' => $chat, 'contactId' => $contactId] + $match;
        });
    }

    private function loadPhone(array $chat): array
    {
        $runtime = $this->runtimeLoader
            ? ($this->runtimeLoader)($this->siteId, $this->userId)
            : $this->auth->getRuntimeBootstrap($this->siteId, $this->userId);
        $runtimeCompanyId = trim((string)($runtime['companyId'] ?? ''));
        if ($runtimeCompanyId === '' || !hash_equals($runtimeCompanyId, (string)$chat['companyId'])) {
            throw new IntegrationException(
                'This chat does not belong to the EasyBusy company configured for the current user.',
                'EASYBUSY_COMPANY_MISMATCH'
            );
        }
        $token = (string)($runtime['accessToken'] ?? '');
        if ($token === '') {
            throw new IntegrationException('Configure your EasyBusy integration key first.', 'EASYBUSY_TOKEN_NOT_CONFIGURED');
        }

        $contact = $this->contactLoader
            ? ($this->contactLoader)($token, $chat)
            : $this->api->getContact($token, $chat);
        if (!is_array($contact)) {
            return [$chat, null];
        }
        $externalContactId = trim((string)($contact['id'] ?? ''));
        if ($externalContactId !== ''
            && strlen($externalContactId) <= 191
            && preg_match('/^[A-Za-z0-9._:@\-]+$/', $externalContactId)
        ) {
            $chat['externalContactId'] = $externalContactId;
        }

        return [$chat, PhoneNormalizer::normalize($contact['phoneNumber'] ?? '')];
    }

    private function withChatLock(array $chat, \Closure $callback): array
    {
        $lockName = 'eb_phone_' . hash('sha256', implode('|', [
            $this->siteId,
            (string)$chat['companyId'],
            (string)$chat['chatId'],
        ]));
        $connection = Application::getConnection();
        if (!$connection->lock($lockName, 1)) {
            throw new IntegrationException(
                'Phone matching is already in progress for this chat.',
                'PHONE_SEARCH_IN_PROGRESS',
                true
            );
        }

        try {
            return $callback($chat);
        } finally {
            $connection->unlock($lockName);
        }
    }
}
