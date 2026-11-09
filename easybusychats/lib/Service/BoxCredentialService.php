<?php

namespace EasyBusyChats\Service;

use Bitrix\Main\Type\DateTime;
use EasyBusyChats\Config;
use EasyBusyChats\Exception\IntegrationException;
use EasyBusyChats\Model\BoxCredentialTable;

final class BoxCredentialService
{
    private const CREDENTIAL_ID = 1;

    public function getStatus(): array
    {
        try {
            $credentials = $this->getCredentials();
        } catch (IntegrationException $error) {
            if ($error->getErrorCode() !== 'BOX_AUTH_NOT_CONFIGURED') {
                throw $error;
            }

            return ['configured' => false];
        }

        return [
            'configured' => true,
            'clientId' => $credentials['clientId'],
            'source' => $credentials['source'],
        ];
    }

    public function getCredentials(): array
    {
        $environmentClientId = Config::env('EASYBUSYCHATS_CLIENT_ID');
        $environmentSecret = Config::env('EASYBUSYCHATS_CLIENT_SECRET');
        if ($environmentClientId !== '' || $environmentSecret !== '') {
            $this->validate($environmentClientId, $environmentSecret);

            return [
                'clientId' => $environmentClientId,
                'clientSecret' => $environmentSecret,
                'source' => 'environment',
            ];
        }

        $row = BoxCredentialTable::getByPrimary(self::CREDENTIAL_ID, [
            'select' => ['CLIENT_ID', 'CLIENT_SECRET_ENCRYPTED'],
        ])->fetch();
        if (!$row) {
            throw new IntegrationException(
                'EasyBusy Box authentication is not configured by an administrator.',
                'BOX_AUTH_NOT_CONFIGURED'
            );
        }

        $clientId = trim((string)$row['CLIENT_ID']);
        $clientSecret = trim((string)$row['CLIENT_SECRET_ENCRYPTED']);
        if ($clientSecret === '') {
            throw new IntegrationException(
                'The EasyBusy Client Secret could not be decrypted.',
                'BOX_CLIENT_SECRET_DECRYPT_FAILED'
            );
        }
        $this->validate($clientId, $clientSecret);

        return [
            'clientId' => $clientId,
            'clientSecret' => $clientSecret,
            'source' => 'database',
        ];
    }

    public function configure(string $clientId, string $clientSecret, int $updatedBy): array
    {
        if ($updatedBy <= 0) {
            throw new IntegrationException('Invalid administrator context.', 'INVALID_USER_CONTEXT');
        }

        $existing = BoxCredentialTable::getByPrimary(self::CREDENTIAL_ID, [
            'select' => ['ID', 'CLIENT_SECRET_ENCRYPTED', 'CREATED_AT'],
        ])->fetch();
        $clientId = trim($clientId);
        $clientSecret = trim($clientSecret);
        if ($clientSecret === '' && $existing) {
            $clientSecret = trim((string)$existing['CLIENT_SECRET_ENCRYPTED']);
        }
        $this->validate($clientId, $clientSecret);

        $now = new DateTime();
        $fields = [
            'CLIENT_ID' => $clientId,
            'CLIENT_SECRET_ENCRYPTED' => $clientSecret,
            'CREATED_AT' => $existing['CREATED_AT'] ?? $now,
            'UPDATED_AT' => $now,
            'UPDATED_BY' => $updatedBy,
        ];
        $result = $existing
            ? BoxCredentialTable::update(self::CREDENTIAL_ID, $fields)
            : BoxCredentialTable::add(['ID' => self::CREDENTIAL_ID] + $fields);
        if (!$result->isSuccess()) {
            throw new IntegrationException(
                'Could not save EasyBusy Box credentials.',
                'BOX_CREDENTIAL_SAVE_FAILED',
                true
            );
        }

        return $this->getStatus();
    }

    private function validate(string $clientId, string $clientSecret): void
    {
        if ($clientId === '' || strlen($clientId) > 191 || !preg_match('/^[A-Za-z0-9._:@\-]+$/', $clientId)) {
            throw new IntegrationException('Enter a valid EasyBusy Client ID.', 'BOX_CLIENT_ID_INVALID');
        }
        if (strlen($clientSecret) < 32 || strlen($clientSecret) > 4096 || preg_match('/[\x00-\x20\x7F]/', $clientSecret)) {
            throw new IntegrationException('Enter a valid EasyBusy Client Secret.', 'BOX_CLIENT_SECRET_INVALID');
        }
    }
}
