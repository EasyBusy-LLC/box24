<?php

namespace EasyBusyChats\Service;

final class PhoneNormalizer
{
    public const MIN_MATCH_DIGITS = 9;

    public static function normalize(mixed $value): string
    {
        $digits = preg_replace('/\D+/', '', trim((string)$value));

        return is_string($digits) ? substr($digits, 0, 32) : '';
    }

    public static function isValid(string $digits): bool
    {
        return ctype_digit($digits) && strlen($digits) >= self::MIN_MATCH_DIGITS;
    }

    public static function longestCommonSuffix(string $left, string $right): int
    {
        $left = self::normalize($left);
        $right = self::normalize($right);
        $limit = min(strlen($left), strlen($right));
        $length = 0;

        while ($length < $limit
            && $left[strlen($left) - $length - 1] === $right[strlen($right) - $length - 1]
        ) {
            $length++;
        }

        return $length;
    }
}
