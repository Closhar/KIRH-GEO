<?php

return [
    'fcm' => ['credentials_path' => env('FCM_CREDENTIALS_PATH')],
    'apns' => ['certificate_path' => env('APNS_CERTIFICATE_PATH'), 'key_path' => env('APNS_KEY_PATH'),
        'key_password' => env('APNS_KEY_PASSWORD', ''), 'topic' => env('APNS_TOPIC'), 'sandbox' => env('APNS_SANDBOX', false)],
];
