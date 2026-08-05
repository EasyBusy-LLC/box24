<?php

namespace EasyBusyChats\Service;

use Bitrix\Main\Diag\Debug;
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
        $safe = ['operation' => $operation, 'timestamp' => gmdate('c')];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $context)) {
                $safe[$key] = is_scalar($context[$key]) || $context[$key] === null
                    ? $context[$key]
                    : '[complex]';
            }
        }

        Debug::writeToFile($safe, '', '/local/logs/easybusychats.log');
    }
}
