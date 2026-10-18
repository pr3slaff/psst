<?php

return [
    'endpoint' => env('PSST_ENDPOINT'), // Full URL, e.g. https://psst.pr3slaff.eu/api/v1/ingest
    'key' => env('PSST_KEY'),
    'mode' => env('PSST_MODE', 'sync'),
    'fallback_to_queue' => env('PSST_FALLBACK_TO_QUEUE', false),
    'queue_connection' => env('PSST_QUEUE_CONNECTION'),
    'queue' => env('PSST_QUEUE', 'psst'),
    'health_key' => env('PSST_HEALTH_KEY'),
    'metric_cache_store' => env('PSST_METRIC_CACHE_STORE'),
    'metric_cache_ttl' => 60,
    'redact_fields' => ['password', 'password_confirmation', 'token', 'access_token', 'refresh_token', 'authorization', 'secret', 'cookie', 'api_key'],
    // Optional callable receiving only a safe reason code and event ID.
    'diagnostics' => null,
];
