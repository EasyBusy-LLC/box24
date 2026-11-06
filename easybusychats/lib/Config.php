<?php

namespace EasyBusyChats;

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;

final class Config
{
    public const MODULE_ID = 'easybusychats';
    public const OPTION_PLUGIN_URL = 'plugin_url';
    public const OPTION_PLUGIN_ORIGIN = 'plugin_origin';
    public const OPTION_DEFAULT_LANGUAGE = 'default_language';
    public const OPTION_CONTACT_ENABLED = 'contact_integration_enabled';
    public const OPTION_DEAL_ENABLED = 'deal_integration_enabled';
    public const OPTION_DEBUG_ENABLED = 'debug_enabled';
    public const OPTION_REQUEST_TIMEOUT = 'request_timeout';
    public const OPTION_API_BASE_URL = 'api_base_url';

    public static function getDefaults(): array
    {
        return [
            self::OPTION_PLUGIN_URL => 'https://plugins.easy-busy.chat/bitrix24/',
            self::OPTION_PLUGIN_ORIGIN => 'https://plugins.easy-busy.chat',
            self::OPTION_DEFAULT_LANGUAGE => 'ru-RU',
            self::OPTION_CONTACT_ENABLED => 'Y',
            self::OPTION_DEAL_ENABLED => 'Y',
            self::OPTION_DEBUG_ENABLED => 'N',
            self::OPTION_REQUEST_TIMEOUT => '15000',
            self::OPTION_API_BASE_URL => 'https://api.easy-busy.chat/chats/v1',
        ];
    }

    public static function getIframeUrl(): string
    {
        $environmentUrl = trim((string)getenv('EASYBUSYCHATS_IFRAME_URL'));
        if ($environmentUrl !== '' && self::isValidPluginUrl($environmentUrl)) {
            return $environmentUrl;
        }

        $url = trim(Option::get(
            self::MODULE_ID,
            self::OPTION_PLUGIN_URL,
            self::getDefaults()[self::OPTION_PLUGIN_URL]
        ));

        return self::isValidPluginUrl($url) ? $url : '';
    }

    public static function getPluginOrigin(): string
    {
        $environmentOrigin = trim((string)getenv('EASYBUSYCHATS_PLUGIN_ORIGIN'));
        if (self::isValidOrigin($environmentOrigin)) {
            return rtrim($environmentOrigin, '/');
        }

        $configuredOrigin = trim(Option::get(self::MODULE_ID, self::OPTION_PLUGIN_ORIGIN, ''));
        if (self::isValidOrigin($configuredOrigin)) {
            return rtrim($configuredOrigin, '/');
        }

        $url = self::getIframeUrl();
        if (preg_match('~^https?://~i', $url)) {
            $parts = parse_url($url);
            if (is_array($parts) && isset($parts['scheme'], $parts['host'])) {
                return strtolower($parts['scheme']) . '://' . $parts['host']
                    . (isset($parts['port']) ? ':' . (int)$parts['port'] : '');
            }
        }

        return self::getCurrentOrigin();
    }

    public static function getDefaultLanguage(): string
    {
        $language = trim(Option::get(
            self::MODULE_ID,
            self::OPTION_DEFAULT_LANGUAGE,
            self::getDefaults()[self::OPTION_DEFAULT_LANGUAGE]
        ));

        return in_array($language, ['ru-RU', 'en-US', 'uk-UA'], true) ? $language : 'ru-RU';
    }

    public static function getCurrentLanguage(): string
    {
        $languageId = defined('LANGUAGE_ID') ? strtolower((string)LANGUAGE_ID) : '';
        $languages = [
            'ru' => 'ru-RU',
            'en' => 'en-US',
            'uk' => 'uk-UA',
            'ua' => 'uk-UA',
        ];

        return $languages[$languageId] ?? self::getDefaultLanguage();
    }

    public static function isContactIntegrationEnabled(): bool
    {
        return Option::get(self::MODULE_ID, self::OPTION_CONTACT_ENABLED, 'Y') === 'Y';
    }

    public static function isDealIntegrationEnabled(): bool
    {
        return Option::get(self::MODULE_ID, self::OPTION_DEAL_ENABLED, 'Y') === 'Y';
    }

    public static function isDebugEnabled(): bool
    {
        return Option::get(self::MODULE_ID, self::OPTION_DEBUG_ENABLED, 'N') === 'Y';
    }

    public static function getRequestTimeout(): int
    {
        $timeout = (int)Option::get(self::MODULE_ID, self::OPTION_REQUEST_TIMEOUT, '15000');

        return max(3000, min(60000, $timeout));
    }

    public static function getEasyBusyApiBaseUrl(): string
    {
        $environmentUrl = trim((string)getenv('EASYBUSYCHATS_API_BASE_URL'));
        $url = $environmentUrl !== ''
            ? $environmentUrl
            : trim(Option::get(self::MODULE_ID, self::OPTION_API_BASE_URL, self::getDefaults()[self::OPTION_API_BASE_URL]));
        $url = rtrim($url, '/');
        if (!self::isValidEasyBusyApiBaseUrl($url)) {
            return '';
        }

        return $url;
    }

    public static function isValidEasyBusyApiBaseUrl(string $url): bool
    {
        $parts = parse_url(rtrim(trim($url), '/'));

        return is_array($parts)
            && strtolower((string)($parts['scheme'] ?? '')) === 'https'
            && !empty($parts['host'])
            && !isset($parts['user'], $parts['pass'], $parts['query'], $parts['fragment']);
    }

    public static function isValidPluginUrl(string $url): bool
    {
        if ($url === '' || preg_match('/[\x00-\x1F\x7F]/', $url)) {
            return false;
        }

        if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
            return true;
        }

        $parts = parse_url($url);

        return is_array($parts)
            && in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true)
            && !empty($parts['host'])
            && !isset($parts['user'], $parts['pass']);
    }

    public static function isValidOrigin(string $origin): bool
    {
        if ($origin === '') {
            return false;
        }

        $parts = parse_url($origin);

        return is_array($parts)
            && in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true)
            && !empty($parts['host'])
            && empty($parts['path'])
            && !isset($parts['query'], $parts['fragment'], $parts['user'], $parts['pass']);
    }

    public static function getCurrentOrigin(): string
    {
        $request = Application::getInstance()->getContext()->getRequest();
        $server = $request->getServer();
        $forwardedProto = strtolower(trim(explode(',', (string)$server->get('HTTP_X_FORWARDED_PROTO'))[0]));
        $scheme = in_array($forwardedProto, ['http', 'https'], true)
            ? $forwardedProto
            : ($request->isHttps() ? 'https' : 'http');
        $forwardedHost = trim(explode(',', (string)$server->get('HTTP_X_FORWARDED_HOST'))[0]);
        $host = $forwardedHost !== '' ? $forwardedHost : (string)$server->get('HTTP_HOST');
        if ($host === '') {
            $host = (string)$request->getHttpHost();
        }
        $host = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', $host);

        return $host !== '' ? $scheme . '://' . $host : '';
    }
}
