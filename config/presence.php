<?php

return [
    // Enable only after the shared Redis connection has been provisioned.
    'enabled' => (bool) env('PRESENCE_ENABLED', false),
    'connection' => 'presence',
    'prefix' => env('PRESENCE_PREFIX', 'nearby:presence:v1:'),
    'idle_seconds' => 300,
    'heartbeat_seconds' => 90,
    'key_ttl_seconds' => 600,
    'max_devices' => 20,
    'batch_size' => 500,
];
