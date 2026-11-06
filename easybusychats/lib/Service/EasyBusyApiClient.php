<?php

namespace EasyBusyChats\Service;

use Bitrix\Main\Web\HttpClient;
use Bitrix\Main\Web\Json;
use EasyBusyChats\Config;
use EasyBusyChats\Exception\IntegrationException;

final class EasyBusyApiClient
{
    public function authenticateBoxUser(string $clientId, string $assertion): array
    {
        $clientId = trim($clientId);
        if ($clientId === '' || $assertion === '') {
            throw new IntegrationException('Box authentication request is incomplete.', 'BOX_AUTH_REQUEST_INVALID');
        }

        [$status, $response] = $this->post('/plugin/box-edition/auth', [
            'clientId' => $clientId,
            'token' => $assertion,
        ]);
        if (in_array($status, [400, 401, 403], true)) {
            $reason = $this->getSafeErrorReason((string)$response);
            throw new IntegrationException(
                'EasyBusy rejected the Bitrix24 Box authentication assertion.' . ($reason !== '' ? ' ' . $reason : ''),
                'BOX_AUTH_REJECTED',
                false,
                ['httpStatus' => $status]
            );
        }
        if ($status < 200 || $status >= 300) {
            throw new IntegrationException(
                'EasyBusy Box authentication is temporarily unavailable.',
                'BOX_AUTH_API_ERROR',
                $status >= 500
            );
        }

        try {
            $payload = Json::decode((string)$response);
        } catch (\Throwable) {
            throw new IntegrationException('EasyBusy returned an invalid authentication response.', 'EASYBUSY_INVALID_RESPONSE', true);
        }
        if (isset($payload['data']) && is_array($payload['data'])) {
            $payload = $payload['data'];
        }
        $accessToken = trim((string)($payload['accessToken'] ?? $payload['token'] ?? ''));
        $companyId = trim((string)($payload['companyId'] ?? ''));
        $companyName = trim((string)($payload['companyName'] ?? ''));
        $email = trim((string)($payload['email'] ?? ''));
        $expiresIn = (int)($payload['expiresIn'] ?? 0);
        if ($accessToken === '' || $companyId === '' || !hash_equals($clientId, $companyId)) {
            throw new IntegrationException('EasyBusy returned an invalid authentication response.', 'EASYBUSY_INVALID_RESPONSE', true);
        }

        return [
            'accessToken' => $accessToken,
            'companyId' => $companyId,
            'companyName' => mb_substr($companyName, 0, 255),
            'email' => mb_substr($email, 0, 320),
            'expiresIn' => max(60, min(86400, $expiresIn > 0 ? $expiresIn : 3600)),
        ];
    }

    public function getContact(string $token, array $chat): ?array
    {
        $provider = strtolower(trim((string)($chat['provider'] ?? '')));
        $companyId = trim((string)($chat['companyId'] ?? ''));
        $accountId = trim((string)($chat['accountId'] ?? ''));
        $contactId = trim((string)($chat['externalContactId'] ?? ''));
        if ($contactId === '') {
            $contactId = trim((string)($chat['chatId'] ?? ''));
        }
        foreach ([$provider, $companyId, $accountId] as $identifier) {
            if ($identifier === '' || strlen($identifier) > 191 || !preg_match('/^[A-Za-z0-9._:@\-]+$/', $identifier)) {
                throw new IntegrationException('Chat identity is incomplete.', 'EASYBUSY_CONTACT_IDENTITY_MISSING');
            }
        }
        if ($contactId === '' || strlen($contactId) > 19 || !ctype_digit($contactId)) {
            throw new IntegrationException(
                'EasyBusy contact ID is unavailable for this chat.',
                'EASYBUSY_CONTACT_ID_MISSING'
            );
        }

        $path = sprintf(
            '/%s/%s/account/%s/contact/%s',
            rawurlencode($provider),
            rawurlencode($companyId),
            rawurlencode($accountId),
            rawurlencode($contactId)
        );
        [$status, $response] = $this->get($path, $token);
        if ($status === 204 || $status === 404) {
            return null;
        }
        if (in_array($status, [401, 403], true)) {
            throw new IntegrationException('The EasyBusy integration key is invalid or revoked.', 'EASYBUSY_TOKEN_INVALID');
        }
        if ($status < 200 || $status >= 300) {
            throw new IntegrationException('EasyBusy contact lookup failed.', 'EASYBUSY_API_ERROR', $status >= 500);
        }

        try {
            $payload = Json::decode((string)$response);
        } catch (\Throwable) {
            throw new IntegrationException('EasyBusy returned an invalid contact.', 'EASYBUSY_INVALID_RESPONSE', true);
        }
        if (isset($payload['data']) && is_array($payload['data'])) {
            $payload = $payload['data'];
        }
        if (!is_array($payload)) {
            throw new IntegrationException('EasyBusy returned an invalid contact.', 'EASYBUSY_INVALID_RESPONSE', true);
        }

        return [
            'id' => mb_substr(trim((string)($payload['id'] ?? $contactId)), 0, 191),
            'phoneNumber' => mb_substr(trim((string)($payload['phoneNumber'] ?? '')), 0, 64),
            'provider' => mb_substr(trim((string)($payload['provider'] ?? $provider)), 0, 64),
            'accountId' => mb_substr(trim((string)($payload['accountId'] ?? $accountId)), 0, 191),
        ];
    }

    private function get(string $path, string $token): array
    {
        $apiBaseUrl = Config::getEasyBusyApiBaseUrl();
        if ($apiBaseUrl === '') {
            throw new IntegrationException('EasyBusy API URL is not configured.', 'EASYBUSY_API_CONFIG_INVALID');
        }
        $timeout = max(3, (int)ceil(Config::getRequestTimeout() / 1000));
        $client = new HttpClient([
            'socketTimeout' => $timeout,
            'streamTimeout' => $timeout,
            'redirect' => false,
            'disableSslVerification' => false,
        ]);
        $client->setHeader('Accept', 'application/json');
        $client->setHeader('Authorization', 'Bearer ' . $token);
        $response = $client->get($apiBaseUrl . $path);
        if ($response === false) {
            throw new IntegrationException('EasyBusy API is unavailable.', 'EASYBUSY_API_UNAVAILABLE', true);
        }

        return [(int)$client->getStatus(), (string)$response];
    }

    private function post(string $path, array $payload): array
    {
        $apiBaseUrl = Config::getEasyBusyApiBaseUrl();
        if ($apiBaseUrl === '') {
            throw new IntegrationException('EasyBusy API URL is not configured.', 'EASYBUSY_API_CONFIG_INVALID');
        }
        $timeout = max(3, (int)ceil(Config::getRequestTimeout() / 1000));
        $client = new HttpClient([
            'socketTimeout' => $timeout,
            'streamTimeout' => $timeout,
            'redirect' => false,
            'disableSslVerification' => false,
        ]);
        $client->setHeader('Accept', 'application/json');
        $client->setHeader('Content-Type', 'application/json');
        $response = $client->post($apiBaseUrl . $path, Json::encode($payload));
        if ($response === false) {
            throw new IntegrationException('EasyBusy API is unavailable.', 'EASYBUSY_API_UNAVAILABLE', true);
        }

        return [(int)$client->getStatus(), (string)$response];
    }

    private function getSafeErrorReason(string $response): string
    {
        try {
            $payload = Json::decode($response);
        } catch (\Throwable) {
            return '';
        }
        if (!is_array($payload)) {
            return '';
        }
        $message = trim((string)($payload['message'] ?? $payload['error'] ?? ''));
        $message = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $message);

        return $message !== '' ? mb_substr($message, 0, 300) : '';
    }
}
