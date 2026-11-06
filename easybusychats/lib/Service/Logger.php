<?php

namespace EasyBusyChats\Service;

use Bitrix\Main\Web\Json;
use EasyBusyChats\Config;

final class Logger
{
    public static function write(string $operation, array $context = []): void
    {
        if (!Config::isDebugEnabled()) {
            return;
        }

        $allowed = [
            'userId', 'contactId', 'dealId', 'companyId', 'chatId',
            'result', 'durationMs', 'errorCode', 'requestId',
            'exceptionClass', 'exceptionLocation',
        ];
        $safe = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $context)) {
                $safe[$key] = is_scalar($context[$key]) || $context[$key] === null
                    ? $context[$key]
                    : '[complex]';
            }
        }

        try {
            \CEventLog::Log(
                'DEBUG',
                'EASYBUSYCHATS_OPERATION',
                Config::MODULE_ID,
                $operation,
                Json::encode($safe)
            );
        } catch (\Throwable) {
            // Diagnostics must never fail the operation being logged.
        }
    }
}
