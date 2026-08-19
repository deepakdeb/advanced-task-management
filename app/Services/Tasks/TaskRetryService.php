<?php

declare(strict_types=1);

namespace App\Services\Tasks;

use App\Enums\TaskStatus;
use App\Models\Task;
use Illuminate\Support\Facades\DB;

class TaskRetryService
{
    public function __construct(private readonly TaskLogService $logs) {}

    public function retry(Task $task): bool
    {
        return DB::transaction(function () use ($task): bool {
            $lockedTask = Task::query()->whereKey($task->getKey())->lockForUpdate()->first();

            if ($lockedTask === null || $lockedTask->status !== TaskStatus::FAILED) {
                return false;
            }

            $this->logs->record($lockedTask, 'retry_requested');
            $lockedTask->forceFill([
                'status' => TaskStatus::PENDING,
                'started_at' => null,
                'completed_at' => null,
                'failed_at' => null,
                'error_message' => null,
            ])->save();

            return true;
        });
    }
}
