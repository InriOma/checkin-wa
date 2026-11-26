<?php

declare(strict_types=1);

namespace App\Domains\WhatsApp\Services;

final class PhoneNumberNormalizer
{
    public static function toE164(string $raw): string
    {
        $digits = preg_replace('/\D+/', '', trim($raw)) ?? '';

        if ($digits === '') {
            throw new \InvalidArgumentException('Phone number cannot be empty.');
        }

        if (strlen($digits) < 8 || strlen($digits) > 15) {
            throw new \InvalidArgumentException("Invalid E.164 phone length: {$raw}");
        }

        return '+'.$digits;
    }

    public static function toWhatsAppId(string $e164): string
    {
        return ltrim(self::toE164($e164), '+');
    }

    public static function mask(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        $len = strlen($digits);

        if ($len <= 4) {
            return '****';
        }

        $prefix = substr($digits, 0, min(2, $len - 4));
        $suffix = substr($digits, -4);

        return '+'.$prefix.str_repeat('*', max(2, $len - strlen($prefix) - 4)).$suffix;
    }
}
