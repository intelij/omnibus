<?php

return [
    'base_domain' => env('APP_DOMAIN', 'transithub.test'),

    'default_booking_rules' => [
        'seat_hold_minutes' => 10,
        'pending_payment_minutes' => 30,
        'max_seats_per_booking' => 10,
        'booking_cutoff_minutes' => 60,
        'min_booking_age_hours' => 0,
        'open_ticket_multiplier' => 1.10,
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
        'card' => [
            'provider' => env('CARD_PROVIDER', 'paynow'),
            'merchant_id' => env('PAYNOW_MERCHANT_ID'),
            'merchant_key' => env('PAYNOW_MERCHANT_KEY'),
            'return_url' => env('PAYNOW_RETURN_URL'),
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
