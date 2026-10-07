<?php

$allowedOrigins = array_filter(array_map('trim', explode(',', (string) env(
    'FRONTEND_ORIGINS',
    'http://localhost:5173,http://localhost:8080,https://app.lacaksmbbot.com'
))));

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    'allowed_origins' => array_values($allowedOrigins),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 600,
    'supports_credentials' => true,
];
