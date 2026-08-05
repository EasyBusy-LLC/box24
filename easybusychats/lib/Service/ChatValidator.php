<?php

namespace EasyBusyChats\Service;

use Bitrix\Main\Type\DateTime;
use EasyBusyChats\Exception\IntegrationException;

final class ChatValidator
{
    public static function normalize(array $input): array
    {
        $companyId = self::identifier($input['easyBusyCompanyId'] ?? $input['companyId'] ?? '', 'companyId');
        $chatId = self::identifier($input['chatId'] ?? $input['selectedChatId'] ?? '', 'chatId');
        $accountId = self::optionalIdentifier($input['accountId'] ?? $input['selectedAccountId'] ?? '', 191);
        $provider = self::optionalIdentifier($input['provider'] ?? $input['selectedProvider'] ?? '', 64);
        $externalContactId = self::optionalIdentifier(
            $input['externalContactId'] ?? $input['easyBusyExternalContactId'] ?? '',
            191
        );
        $title = self::text($input['chatTitle'] ?? $input['title'] ?? $input['name'] ?? '', 255);
        $avatarUrl = self::safeUrl($input['avatarUrl'] ?? $input['avatar'] ?? '');
        $lastMessageAt = self::dateTime($input['lastMessageAt'] ?? null);

        return [
            'companyId' => $companyId,
            'chatId' => $chatId,
            'accountId' => $accountId,
            'provider' => strtolower($provider),
            'externalContactId' => $externalContactId,
            'title' => $title,
            'avatarUrl' => $avatarUrl,
            'lastMessageAt' => $lastMessageAt,
            'name' => self::text($input['name'] ?? $title, 255),
            'phone' => self::text($input['phone'] ?? $input['phoneNumber'] ?? '', 64),
            'email' => self::email($input['email'] ?? $input['emailAddress'] ?? ''),
        ];
    }

    public static function positiveId(mixed $value, string $field): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            throw new IntegrationException("Invalid {$field}.", 'INVALID_CRM_ID');
        }

        return (int)$id;
    }

    public static function searchQuery(string $value): string
    {
        $query = trim(preg_replace('/\s+/u', ' ', $value));
        if ($query === '' || mb_strlen($query) < 2 || mb_strlen($query) > 100) {
            throw new IntegrationException('Search query must contain 2 to 100 characters.', 'INVALID_SEARCH_QUERY');
        }

        return $query;
    }

    private static function identifier(mixed $value, string $field): string
    {
        $identifier = trim((string)$value);
        if ($identifier === '' || mb_strlen($identifier) > 191 || !preg_match('/^[A-Za-z0-9._:@\-]+$/', $identifier)) {
            throw new IntegrationException("Invalid {$field}.", 'INVALID_CHAT_PAYLOAD');
        }

        return $identifier;
    }

    private static function optionalIdentifier(mixed $value, int $limit): string
    {
        $identifier = trim((string)$value);
        if ($identifier === '') {
            return '';
        }
        if (mb_strlen($identifier) > $limit || !preg_match('/^[A-Za-z0-9._:@\-]+$/', $identifier)) {
            throw new IntegrationException('Invalid chat metadata.', 'INVALID_CHAT_PAYLOAD');
        }

        return $identifier;
    }

    private static function text(mixed $value, int $limit): string
    {
        $text = trim(strip_tags((string)$value));

        return mb_substr($text, 0, $limit);
    }

    private static function email(mixed $value): string
    {
        $email = trim((string)$value);
        if ($email === '') {
            return '';
        }

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? mb_substr($email, 0, 255) : '';
    }

    private static function safeUrl(mixed $value): string
    {
        $url = trim((string)$value);
        if ($url === '') {
            return '';
        }
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https' || empty($parts['host'])) {
            return '';
        }

        return mb_substr($url, 0, 500);
    }

    private static function dateTime(mixed $value): ?DateTime
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return DateTime::createFromPhp(new \DateTimeImmutable((string)$value));
        } catch (\Throwable) {
            return null;
        }
    }
}
