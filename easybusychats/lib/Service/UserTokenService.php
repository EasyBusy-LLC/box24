<?php

namespace EasyBusyChats\Service;

use Bitrix\Main\Application;
use Bitrix\Main\Type\DateTime;
use EasyBusyChats\Config;
use EasyBusyChats\Exception\IntegrationException;
use EasyBusyChats\Model\UserTokenTable;

final class UserTokenService
{
    public function getStatus(string $siteId, int $userId): array
    {
        $this->assertIdentity($siteId, $userId);
        $row = UserTokenTable::getList([
            'select' => [
                'ID', 'TOKEN_FINGERPRINT', 'COMPANY_ID', 'COMPANY_NAME',
                'VALIDATED_AT', 'CREATED_AT', 'UPDATED_AT',
            ],
            'filter' => ['=SITE_ID' => $siteId, '=BITRIX_USER_ID' => $userId],
            'limit' => 1,
        ])->fetch();

        if (!$row) {
            return ['configured' => false];
        }

        return [
            'configured' => true,
            'fingerprint' => (string)$row['TOKEN_FINGERPRINT'],
            'companyId' => (string)$row['COMPANY_ID'],
            'companyName' => (string)$row['COMPANY_NAME'],
            'validatedAt' => $this->formatDate($row['VALIDATED_AT'] ?? null),
            'updatedAt' => $this->formatDate($row['UPDATED_AT'] ?? null),
        ];
    }

    public function save(string $siteId, int $userId, string $rawToken, ?EasyBusyApiClient $client = null): array
    {
        $this->assertIdentity($siteId, $userId);
        $token = $this->normalizeToken($rawToken);
        $company = ($client ?? new EasyBusyApiClient())->validateToken($token);
        $now = new DateTime();
        $existing = UserTokenTable::getList([
            'select' => ['ID', 'CREATED_AT'],
            'filter' => ['=SITE_ID' => $siteId, '=BITRIX_USER_ID' => $userId],
            'limit' => 1,
        ])->fetch();
        $fields = [
            'SITE_ID' => $siteId,
            'BITRIX_USER_ID' => $userId,
            'TOKEN_ENCRYPTED' => $token,
            'TOKEN_FINGERPRINT' => substr(hash('sha256', $token), 0, 16),
            'COMPANY_ID' => $company['id'],
            'COMPANY_NAME' => $company['name'],
            'VALIDATED_AT' => $now,
            'CREATED_AT' => $existing['CREATED_AT'] ?? $now,
            'UPDATED_AT' => $now,
            'UPDATED_BY' => $userId,
        ];

        $connection = Application::getConnection();
        $connection->startTransaction();
        try {
            $result = $existing
                ? UserTokenTable::update((int)$existing['ID'], $fields)
                : UserTokenTable::add($fields);
            if (!$result->isSuccess()) {
                throw new IntegrationException('Could not save the EasyBusy integration key.', 'TOKEN_SAVE_FAILED', true);
            }
            $connection->commitTransaction();
        } catch (IntegrationException $error) {
            $connection->rollbackTransaction();
            throw $error;
        } catch (\Throwable) {
            $connection->rollbackTransaction();
            throw new IntegrationException('Could not save the EasyBusy integration key.', 'TOKEN_SAVE_FAILED', true);
        }

        return $this->getStatus($siteId, $userId);
    }

    public function delete(string $siteId, int $userId): void
    {
        $this->assertIdentity($siteId, $userId);
        try {
            UserTokenTable::deleteByFilter(['=SITE_ID' => $siteId, '=BITRIX_USER_ID' => $userId]);
        } catch (\Throwable) {
            throw new IntegrationException('Could not delete the EasyBusy integration key.', 'TOKEN_DELETE_FAILED', true);
        }
    }

    public function getRuntimeBootstrap(string $siteId, int $userId): array
    {
        $this->assertIdentity($siteId, $userId);
        $row = UserTokenTable::getList([
            'select' => ['TOKEN_ENCRYPTED', 'COMPANY_ID', 'COMPANY_NAME'],
            'filter' => ['=SITE_ID' => $siteId, '=BITRIX_USER_ID' => $userId],
            'limit' => 1,
        ])->fetch();
        $token = trim((string)($row['TOKEN_ENCRYPTED'] ?? ''));
        if (!$row) {
            throw new IntegrationException('Configure your EasyBusy integration key first.', 'EASYBUSY_TOKEN_NOT_CONFIGURED');
        }
        if ($token === '') {
            throw new IntegrationException('The EasyBusy integration key could not be decrypted.', 'TOKEN_DECRYPT_FAILED');
        }

        return [
            'accessToken' => $token,
            'apiBaseUrl' => Config::getEasyBusyApiBaseUrl(),
            'companyId' => (string)$row['COMPANY_ID'],
            'companyName' => (string)$row['COMPANY_NAME'],
        ];
    }

    private function normalizeToken(string $token): string
    {
        $token = trim($token);
        if (strlen($token) < 16 || strlen($token) > 4096 || preg_match('/[\x00-\x20\x7F]/', $token)) {
            throw new IntegrationException('Enter a valid EasyBusy integration key.', 'EASYBUSY_TOKEN_FORMAT_INVALID');
        }

        return $token;
    }

    private function assertIdentity(string $siteId, int $userId): void
    {
        if ($userId <= 0 || $siteId === '' || strlen($siteId) > 10 || !preg_match('/^[A-Za-z0-9_-]+$/', $siteId)) {
            throw new IntegrationException('Invalid Bitrix24 user context.', 'INVALID_USER_CONTEXT');
        }
    }

    private function formatDate(mixed $value): ?string
    {
        return $value instanceof DateTime ? $value->format(DATE_ATOM) : null;
    }
}
