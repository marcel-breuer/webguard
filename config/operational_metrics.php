<?php

declare(strict_types=1);

return [
    'redis_connection' => 'cache',
    'retention_minutes' => 120,
    'slow_query_ms' => 500,
    'slow_api_request_ms' => 1000,
    'queue_lag_warning_ms' => 60_000,
    'queue_backlog_warning' => 100,
    'slow_scheduled_task_ms' => 5000,
];
