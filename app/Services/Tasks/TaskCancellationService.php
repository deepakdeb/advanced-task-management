<?php

declare(strict_types=1);

namespace App\Services\Tasks;

use App\Enums\TaskStatus;
use App\Exceptions\Tasks\TaskCancelledException;
use App\Models\Task;
use Illuminate\Support\Facades\DB;

class TaskCancellationService
{
    public function __construct(private readonly TaskLogService $logs) {}

    public function cancel(Task $task): bool
    {
        return DB::transaction(function () use ($task): bool {
            $lockedTask = Task::query()->whereKey($task->getKey())->lockForUpdate()->first();

            if ($lockedTask === null || ! in_array($lockedTask->status, [TaskStatus::PENDING, TaskStatus::PROCESSING], true)) {
                return false;
            }

            $this->logs->record($lockedTask, 'cancel_requested');
            $lockedTask->forceFill(['status' => TaskStatus::CANCELLED])->save();
            $this->logs->record($lockedTask, 'cancelled');

            return true;
        });
    }

    public function throwIfCancelled(Task $task): void
    {
        if ($this->isCancelled($task)) {
            throw new TaskCancelledException('Task was cancelled.');
        }
    }

    public function isCancelled(Task $task): bool
    {
        return Task::query()->whereKey($task->getKey())
            ->where('status', TaskStatus::CANCELLED->value)
            ->exists();
    }
}
