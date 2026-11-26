<?php

declare(strict_types=1);

namespace App\Domains\WhatsApp\Services;

final class PayloadRedactor
{
    /** @var list<string> */
    private const SENSITIVE_KEYS = [
        'access_token',
        'access_token_encrypted',
        'app_secret',
        'verify_token',
        'password',
        'otp',
        'code',
    ];

    /** @var list<string> */
    private const PHONE_KEYS = [
        'from',
        'to',
        'wa_id',
        'recipient_id',
        'display_phone_number',
        'phone_e164',
    ];

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function redact(array $payload): array
    {
        $result = [];

        foreach ($payload as $key => $value) {
            $normalizedKey = is_string($key) ? strtolower($key) : '';

            if (in_array($normalizedKey, self::SENSITIVE_KEYS, true)) {
                $result[$key] = '[REDACTED]';

                continue;
            }

            if (in_array($normalizedKey, self::PHONE_KEYS, true) && is_string($value)) {
                $result[$key] = PhoneNumberNormalizer::mask($value);

                continue;
            }

            if ($normalizedKey === 'name' && is_string($value)) {
                $result[$key] = '[REDACTED_NAME]';

                continue;
            }

            if (is_array($value)) {
                $result[$key] = $this->redact($value);

                continue;
            }

            $result[$key] = $value;
        }

        return $result;
    }
}
