<?php

return [
    'sandbox_enabled' => env('BILLING_SANDBOX_ENABLED', false),
    'sandbox_webhook_secret' => env('BILLING_SANDBOX_WEBHOOK_SECRET'),
    'trial_days' => (int) env('BILLING_TRIAL_DAYS', 7),
    'trial_plan_id' => env('BILLING_TRIAL_PLAN_ID'),
    'trial_campaign' => 'initial',
    'renewals_enabled' => env('BILLING_RENEWALS_ENABLED', false),
    'stores' => [
        'apple' => ['enabled' => false, 'bundle_id' => env('APPLE_BUNDLE_ID'), 'issuer_id' => env('APPLE_ISSUER_ID'), 'key_id' => env('APPLE_KEY_ID')],
        'google' => ['enabled' => false, 'package_name' => env('GOOGLE_PLAY_PACKAGE_NAME')],
    ],
    'yookassa' => [
        // Enable only after merchant/fiscalization onboarding and sandbox acceptance.
        'enabled' => env('YOOKASSA_ENABLED', false),
        'shop_id' => env('YOOKASSA_SHOP_ID'),
        'secret' => env('YOOKASSA_SECRET'),
        'return_url' => env('YOOKASSA_RETURN_URL'),
    ],
];
