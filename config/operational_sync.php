<?php

return [
    // Enable only after the outbox migration and sync enrollment are provisioned.
    'management_enabled' => (bool) env('OPERATIONAL_SYNC_MANAGEMENT_ENABLED', false),
    'connector_enabled' => (bool) env('OPERATIONAL_SYNC_CONNECTOR_ENABLED', false),
    'allow_insecure_ip_http' => (bool) env('OPERATIONAL_SYNC_ALLOW_INSECURE_IP_HTTP', false),
    'platform_api_url' => env('OPERATIONAL_SYNC_PLATFORM_API_URL'),
    'version' => env('MEDIFLOW_VERSION', 'unknown'),
];
