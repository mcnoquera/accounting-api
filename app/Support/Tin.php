<?php

namespace App\Support;

/**
 * Philippine BIR Taxpayer Identification Number: 9 digits plus a 3- or 5-digit branch code
 * (000 / 00000 for the head office). Accepts any separators; formats as 123-456-789-000.
 */
final class Tin
{
    public static function normalize(?string $value): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $value);

        return match (strlen($digits)) {
            9 => self::format($digits.'000'),
            12, 14 => self::format($digits),
            default => null,
        };
    }

    public static function isValid(?string $value): bool
    {
        if ($value === null || ! preg_match('/^[\d\s\-]+$/', $value)) {
            return false;
        }

        return self::normalize($value) !== null;
    }

    private static function format(string $digits): string
    {
        return substr($digits, 0, 3).'-'.substr($digits, 3, 3).'-'.substr($digits, 6, 3).'-'.substr($digits, 9);
    }
}
