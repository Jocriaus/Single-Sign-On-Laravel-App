<?php

return [
    'issuer' => rtrim(env('SSO_ISSUER', 'http://localhost:8000'), '/'),
    'access_token_minutes' => 15,
    'id_token_minutes' => 15,
    'refresh_token_hours' => 8,
    'max_app_session_hours' => 8,
    'logout_token_minutes' => 2,
    'lockout' => [
        'max_attempts' => 5,
        'minutes' => 15,
    ],
    'rate_limit' => [
        'login_per_minute' => (int) env('SSO_RATE_LIMIT_LOGIN', 30),
        'token_per_minute' => (int) env('SSO_RATE_LIMIT_TOKEN', 600),
    ],
    'admin_client_secret' => env('SSO_ADMIN_CLIENT_SECRET', ''),
    'api_audit_retention_days' => (int) env('SSO_API_AUDIT_RETENTION_DAYS', 365),
];
