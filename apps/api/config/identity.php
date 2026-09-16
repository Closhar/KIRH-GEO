<?php

return [
    'mail_actions_enabled' => env('IDENTITY_MAIL_ACTIONS_ENABLED', false),
    'action_url' => env('IDENTITY_ACTION_URL'),
    'reset_ttl_minutes' => 30,
    'verify_ttl_minutes' => 60,
];
