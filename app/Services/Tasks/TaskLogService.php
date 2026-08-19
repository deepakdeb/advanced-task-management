<?php

declare(strict_types=1);

namespace App\Services\Tasks;

use App\Models\Task;
use App\Models\TaskLog;

class TaskLogService
{
    public function record(Task $task, string $event, ?string $message = null, array $metadata = []): TaskLog
    {
        return $task->logs()->create([
            'event' => $event,
            'message' => $message,
            'metadata' => $metadata === [] ? null : $metadata,
        ]);
    }
}
