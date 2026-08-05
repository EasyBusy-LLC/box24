<?php

namespace EasyBusyChats\Service;

use EasyBusyChats\Exception\IntegrationException;

final class BoxJwtService
{
    private const LIFETIME_SECONDS = 300;

    public function createAssertion(
        string $clientSecret,
        string $email,
        ?int $issuedAt = null
    ): string {
        $email = trim($email);
        if ($clientSecret === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new IntegrationException('The Box authentication identity is incomplete.', 'BOX_AUTH_IDENTITY_INVALID');
        }

        $issuedAt ??= time();
        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $payload = [
            'email' => mb_substr($email, 0, 320),
            'exp' => $issuedAt + self::LIFETIME_SECONDS,
        ];

        $segments = [
            $this->base64UrlEncode($this->jsonEncode($header)),
            $this->base64UrlEncode($this->jsonEncode($payload)),
        ];
        $signature = hash_hmac('sha256', implode('.', $segments), $clientSecret, true);
        $segments[] = $this->base64UrlEncode($signature);

        return implode('.', $segments);
    }

    private function jsonEncode(array $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (!is_string($json)) {
            throw new IntegrationException('Could not create Box authentication assertion.', 'BOX_JWT_CREATE_FAILED');
        }

        return $json;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

}
