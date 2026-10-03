<?php

return [
    'base_domain' => env('APP_DOMAIN', 'transithub.test'),

    // Public web app — used for payment return URLs and email links.
    'frontend_url' => env('FRONTEND_URL', 'http://localhost:3100'),

    // Production transport hardening (config-cache safe).
    'force_https' => env('FORCE_HTTPS', false),
    'force_hsts' => env('FORCE_HSTS', false),
    'hsts_max_age' => (int) env('HSTS_MAX_AGE', 31536000),

    'default_booking_rules' => [
        'seat_hold_minutes' => 10,
        'pending_payment_minutes' => 30,
        'max_seats_per_booking' => 10,
        'booking_cutoff_minutes' => 60,
        'min_booking_age_hours' => 0,
        'open_ticket_multiplier' => 1.10,
        'pii_retention_days' => 730,
        'cash_seat_policy' => 'hold', // 'hold' keeps seats held until payment confirmed
        'passenger_fields' => ['id_document' => false, 'date_of_birth' => false, 'gender' => false],
        'cancellation_policy' => [
            ['min_hours_before' => 48, 'percent' => 100],
            ['min_hours_before' => 24, 'percent' => 50],
            ['min_hours_before' => 0, 'percent' => 0],
        ],
    ],

    'plans' => [
        'starter' => [
            'advanced_reports' => false, 'custom_domain' => false,
            'sms_notifications' => false, 'multiple_branches' => false, 'api_access' => false,
        ],
        'professional' => [
            'advanced_reports' => true, 'custom_domain' => false,
            'sms_notifications' => true, 'multiple_branches' => false, 'api_access' => false,
        ],
        'enterprise' => [
            'advanced_reports' => true, 'custom_domain' => true,
            'sms_notifications' => true, 'multiple_branches' => true, 'api_access' => true,
        ],
    ],

    'payment_gateways' => [
        // Card via Paynow hosted checkout (paynow.co.zw). Credentials are
        // integration_id/integration_key per Paynow docs.
        'card' => [
            'provider' => 'paynow',
            'integration_id' => env('PAYNOW_INTEGRATION_ID'),
            'integration_key' => env('PAYNOW_INTEGRATION_KEY'),
            'test_mode' => env('PAYNOW_TEST_MODE', true),
            'initiate_endpoint' => env('PAYNOW_INITIATE_ENDPOINT', 'https://www.paynow.co.zw/interface/initiatetransaction'),
            // Browser return after checkout; defaults to the public booking page.
            'return_url' => env('PAYNOW_RETURN_URL'),
            // Server-to-server result (webhook). Defaults to our webhook route.
            'result_url' => env('PAYNOW_RESULT_URL'),
        ],
        'ecocash' => [
            'merchant_code' => env('ECOCASH_MERCHANT_CODE'),
            'merchant_pin' => env('ECOCASH_MERCHANT_PIN'),
            'merchant_number' => env('ECOCASH_MERCHANT_NUMBER'),
            'webhook_secret' => env('ECOCASH_WEBHOOK_SECRET'),
            'endpoint' => env('ECOCASH_ENDPOINT'),
        ],
        'inbucks' => [
            'api_key' => env('INBUCKS_API_KEY'),
            'webhook_secret' => env('INBUCKS_WEBHOOK_SECRET'),
            'endpoint' => env('INBUCKS_ENDPOINT'),
        ],
        'cash' => [],
    ],

    'reference_alphabet' => 'ABCDEFGHJKMNPQRSTUVWXYZ23456789', // Crockford-ish, no O/0/I/1
];
