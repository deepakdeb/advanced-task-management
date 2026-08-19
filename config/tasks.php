<?php

declare(strict_types=1);

return [
    'max_attempts' => (int) env('TASK_MAX_ATTEMPTS', 4),
    'stale_timeout' => (int) env('TASK_STALE_TIMEOUT', 1800),
    'lock_timeout' => (int) env('TASK_LOCK_TIMEOUT', 3600),
    'lock_store' => env('TASK_LOCK_STORE', 'redis'),
    'retry_backoff' => [10, 30, 120, 300],
    'retryable_exceptions' => [RuntimeException::class],
    'batch_sizes' => [
        'report' => (int) env('TASK_REPORT_BATCH_SIZE', 100),
        'bulk_notification' => (int) env('TASK_NOTIFICATION_BATCH_SIZE', 100),
        'data_processing' => (int) env('TASK_DATA_BATCH_SIZE', 500),
    ],
    'queues' => [
        'critical' => env('TASK_QUEUE_CRITICAL', 'tasks-critical'),
        'high' => env('TASK_QUEUE_HIGH', 'tasks-high'),
        'normal' => env('TASK_QUEUE_NORMAL', 'tasks-normal'),
        'low' => env('TASK_QUEUE_LOW', 'tasks-low'),
    ],
];
