<?php

namespace App\Support;

class Phone
{
    /**
     * Normalize to E.164. Indian mobiles (10-digit, 0-prefixed, 91/+91/0091) -> +91XXXXXXXXXX.
     * Other numbers are kept only if written with + / 00 (international). Returns null if unusable.
     */
    public static function normalize(?string $raw): ?string
    {
        $raw = trim((string) $raw);
        $international = str_starts_with($raw, '+') || str_starts_with($raw, '00');
        $digits = preg_replace('/\D/', '', $raw);
        if (str_starts_with($raw, '00')) {
            $digits = substr($digits, 2);
        }

        return match (true) {
            (bool) preg_match('/^[6-9]\d{9}$/', $digits) && ! $international => '+91'.$digits,
            (bool) preg_match('/^0[6-9]\d{9}$/', $digits) && ! $international => '+91'.substr($digits, 1),
            (bool) preg_match('/^91[6-9]\d{9}$/', $digits) => '+'.$digits,
            $international && strlen($digits) >= 8 && strlen($digits) <= 15 => '+'.$digits,
            default => null,
        };
    }
}
