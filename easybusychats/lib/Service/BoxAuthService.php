<?php

namespace EasyBusyChats\Service;

use Bitrix\Main\UserTable;
use EasyBusyChats\Config;
use EasyBusyChats\Exception\IntegrationException;

final class BoxAuthService
{
    public function __construct(
        private readonly ?BoxCredentialService $credentials = null,
        private readonly ?BoxJwtService $jwt = null,
        private readonly ?EasyBusyApiClient $api = null,
        private readonly ?AccessPolicyService $accessPolicy = null
    ) {
    }

    public function getStatus(): array
    {
        return $this->credentialService()->getStatus();
    }

    public function getRuntimeBootstrap(string $siteId, int $userId): array
    {
        $this->assertIdentity($siteId, $userId);
        $this->policy()->assertAllowed($userId);
        $credentials = $this->credentialService()->getCredentials();
        $user = UserTable::getByPrimary($userId, ['select' => ['ID', 'EMAIL', 'ACTIVE', 'BLOCKED']])->fetch();
        if (!$user || (string)($user['ACTIVE'] ?? 'N') !== 'Y' || (string)($user['BLOCKED'] ?? 'N') === 'Y') {
            throw new IntegrationException('The current Bitrix24 user is unavailable.', 'BOX_USER_INVALID');
        }

        $email = trim((string)($user['EMAIL'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new IntegrationException(
                'Set a valid email for the current Bitrix24 user before opening EasyBusy Chats.',
                'BOX_USER_EMAIL_INVALID'
            );
        }
        $assertion = $this->jwtService()->createAssertion($credentials['clientSecret'], $email);
        $session = $this->apiClient()->authenticateBoxUser($credentials['clientId'], $assertion);
        if ($session['email'] !== '' && strcasecmp($session['email'], $email) !== 0) {
            throw new IntegrationException('EasyBusy returned another user identity.', 'BOX_AUTH_IDENTITY_MISMATCH');
        }

        return [
            'accessToken' => $session['accessToken'],
            'apiBaseUrl' => Config::getEasyBusyApiBaseUrl(),
            'companyId' => $session['companyId'],
            'companyName' => $session['companyName'] !== ''
                ? $session['companyName']
                : Config::getEasyBusyCompanyName(),
            'expiresIn' => $session['expiresIn'],
            'authMode' => 'box-jwt',
        ];
    }

    private function assertIdentity(string $siteId, int $userId): void
    {
        if ($userId <= 0 || $siteId === '' || strlen($siteId) > 10 || !preg_match('/^[A-Za-z0-9_-]+$/', $siteId)) {
            throw new IntegrationException('Invalid Bitrix24 user context.', 'INVALID_USER_CONTEXT');
        }
    }

    private function credentialService(): BoxCredentialService
    {
        return $this->credentials ?? new BoxCredentialService();
    }

    private function jwtService(): BoxJwtService
    {
        return $this->jwt ?? new BoxJwtService();
    }

    private function apiClient(): EasyBusyApiClient
    {
        return $this->api ?? new EasyBusyApiClient();
    }

    private function policy(): AccessPolicyService
    {
        return $this->accessPolicy ?? new AccessPolicyService();
    }
}
