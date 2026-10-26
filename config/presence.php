<?php

return [
    // Current device state only; expired rows are removed by presence:prune.
    'enabled' => (bool) env('PRESENCE_ENABLED', true),
    'idle_seconds' => 300,
    'heartbeat_seconds' => 90,
    'max_devices' => 20,
    'batch_size' => 500,
];
