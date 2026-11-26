<?php

declare(strict_types=1);

return [
    'graph_base_url' => rtrim((string) env('WHATSAPP_GRAPH_BASE_URL', 'https://graph.facebook.com'), '/'),
    'graph_version' => trim((string) env('WHATSAPP_GRAPH_VERSION', 'v21.0'), '/'),
    'verify_token' => (string) env('WHATSAPP_VERIFY_TOKEN', ''),
    'app_secret' => (string) env('WHATSAPP_APP_SECRET', ''),

    'otp_ttl_seconds' => (int) env('ATTENDANCE_OTP_TTL_SECONDS', 600),
    'otp_max_attempts' => (int) env('ATTENDANCE_OTP_MAX_ATTEMPTS', 5),
    'otp_rate_limit_per_hour' => (int) env('WHATSAPP_OTP_RATE_LIMIT_PER_HOUR', 5),

    'location_ttl_seconds' => (int) env('ATTENDANCE_LOCATION_TTL_SECONDS', 120),
    'selection_ttl_seconds' => (int) env('WHATSAPP_SELECTION_TTL_SECONDS', 600),
];
