<?php

return [
    'device_online_after_seconds' => (int) env('DEVICE_ONLINE_AFTER_SECONDS', 90),
    'device_degraded_after_seconds' => (int) env('DEVICE_DEGRADED_AFTER_SECONDS', 600),
    'gateway_shared_secret' => env('GATEWAY_SHARED_SECRET'),
    'gateway_clock_skew_seconds' => (int) env('GATEWAY_CLOCK_SKEW_SECONDS', 60),
    'gateway_health_url' => env('GATEWAY_HEALTH_URL'),
    'network_violation_notification_url' => env('GATEWAY_NOTIFICATION_URL'),
    'cloudflare_tunnel_probe_url' => env('CLOUDFLARE_TUNNEL_PROBE_URL'),
    'location_retention_days' => max(1, (int) env('DEVICE_LOCATION_RETENTION_DAYS', 90)),
    'telegram_update_receipt_retention_days' => max(1, (int) env('TELEGRAM_UPDATE_RECEIPT_RETENTION_DAYS', 7)),
    'release_certificate_sha256' => strtolower((string) env('SMB_RELEASE_CERT_SHA256', '')),
];
