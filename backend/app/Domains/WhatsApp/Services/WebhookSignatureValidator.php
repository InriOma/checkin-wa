<?php

declare(strict_types=1);

namespace App\Domains\WhatsApp\Services;

final class WebhookSignatureValidator
{
    public function isValid(string $rawBody, ?string $signatureHeader): bool
    {
        $secret = (string) config('whatsapp.app_secret');
        if ($secret === '' || $signatureHeader === null || ! str_starts_with($signatureHeader, 'sha256=')) {
            return false;
        }

        $providedHash = substr($signatureHeader, 7);
        if ($providedHash === '') {
            return false;
        }

        $expectedHash = hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($expectedHash, strtolower($providedHash));
    }

    public function isVerifyTokenValid(?string $mode, ?string $token): bool
    {
        $expected = (string) config('whatsapp.verify_token');
        if ($expected === '' || $mode !== 'subscribe' || $token === null) {
            return false;
        }

        return hash_equals($expected, $token);
    }
}
