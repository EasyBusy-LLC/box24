<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/Service/PhoneNormalizer.php';

use EasyBusyChats\Service\PhoneNormalizer;

$assertSame = static function (mixed $expected, mixed $actual, string $message): void {
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true));
    }
};

$assertSame('380441234567', PhoneNormalizer::normalize('+380441234567'), 'plus prefix');
$assertSame('380441234567', PhoneNormalizer::normalize('380441234567'), 'plain international');
$assertSame('0441234567', PhoneNormalizer::normalize('0441234567'), 'local phone');
$assertSame('380441234567', PhoneNormalizer::normalize('+380 44 123 4567'), 'formatted phone');
$assertSame('12025550182', PhoneNormalizer::normalize('+1 (202) 555-0182'), 'US phone');
$assertSame('00442079460018', PhoneNormalizer::normalize('00442079460018'), 'international access prefix');
$assertSame('', PhoneNormalizer::normalize(null), 'empty phone');
$assertSame('', PhoneNormalizer::normalize('not-a-phone'), 'invalid phone');
$assertSame(true, PhoneNormalizer::isValid('501234567'), 'nine digit minimum');
$assertSame(false, PhoneNormalizer::isValid('50123456'), 'short phone');
$assertSame(12, PhoneNormalizer::longestCommonSuffix('+380441234567', '380441234567'), 'exact normalized match');
$assertSame(10, PhoneNormalizer::longestCommonSuffix('380501234567', '0501234567'), 'ten digit tail');
$assertSame(9, PhoneNormalizer::longestCommonSuffix('380501234567', '48501234567'), 'country prefix tail');
$assertSame(8, PhoneNormalizer::longestCommonSuffix('12025550182', '44925550182'), 'similar international phones below minimum');
$assertSame(0, PhoneNormalizer::longestCommonSuffix('111111111', '222222222'), 'different phones');

echo "phone normalizer tests passed\n";
