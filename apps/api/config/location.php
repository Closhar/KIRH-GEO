<?php

return [
    'history_max_days' => 90,
    'history_default_days' => 7,
    'batch_max_points' => 500,
    'batch_max_bytes' => 524288,
    'offline_max_hours' => 72,
    'future_tolerance_seconds' => 120,
    'current_ttl_seconds' => 86400,
    'realtime_max_age_seconds' => 180,
    'modes' => [
        'idle' => ['capture_seconds' => 600, 'upload_seconds' => 600],
        'normal' => ['capture_seconds' => 60, 'upload_seconds' => 60],
        'live' => ['capture_seconds' => 5, 'upload_seconds' => 5],
        'sport' => ['capture_seconds' => 3, 'upload_seconds' => 15],
        'sos' => ['capture_seconds' => 3, 'upload_seconds' => 3],
    ],
];
