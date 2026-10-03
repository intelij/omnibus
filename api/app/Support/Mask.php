<?php

namespace App\Support;

/**
 * PII/secrets masking for logs, audit metadata and admin output.
 * Never log raw credentials; show just enough to correlate.
 */
class Mask
{
    /** Keys whose values are masked recursively in metadata arrays. */
    private const SENSITIVE_KEYS = [
        'password', 'password_confirmation', 'token', 'api_key', 'secret',
        'merchant_key', 'merchant_pin', 'integration_key', 'authorization',
        'hash', 'signature', 'cvv', 'pan', 'card_number',
    ];

    public static function email(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '?');
        $local = substr($local, 0, 2).str_repeat('*', max(1, strlen($local) - 2));

        return $local.'@'.$domain;
    }

    public function __toString(): string
    {
        return '';
    }

    public static function phone(string $phone): string
    {
        return str_repeat('*', max(0, strlen($phone) - 4)).substr($phone, -4);
    }

    /** Recursively mask sensitive keys and email/phone-looking values. */
    public static function metadata(?array $data): ?array
    {
        if ($data === null) {
            return null;
        }

        $out = [];
        foreach ($data as $key => $value) {
            $k = strtolower((string) $key);
            if (in_array($k, self::SENSITIVE_KEYS, true)) {
                $out[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $out[$key] = self::metadata($value);
            } elseif (is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL)) {
                $out[$key] = self::email($value);
            } elseif (is_string($value) && preg_match('/^\+?\d{7,15}$/', $value)) {
                $out[$key] = self::phone($value);
            } else {
                $out[$key] = $value;
            }
        }

        return $out;
    }
}
