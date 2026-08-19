<?php

declare(strict_types=1);

namespace App\Services\Tasks;

use App\Enums\TaskStatus;
use App\Models\Task;
use Illuminate\Support\Facades\DB;
use Throwable;

class TaskStateService
{
    public function start(string $taskId): ?Task
    {
        return DB::transaction(function () use ($taskId): ?Task {
            $task = Task::query()->whereKey($taskId)->lockForUpdate()->first();

            if ($task === null || $task->status !== TaskStatus::PENDING) {
                return null;
            }

            $task->forceFill([
                'status' => TaskStatus::PROCESSING,
                'attempts' => $task->attempts + 1,
                'started_at' => now(),
                'completed_at' => null,
                'failed_at' => null,
                'error_message' => null,
            ])->save();

            return $task->fresh();
        });
    }

    public function complete(string $taskId): bool
    {
        return DB::transaction(function () use ($taskId): bool {
            $task = Task::query()->whereKey($taskId)->lockForUpdate()->first();

            if ($task === null || $task->status !== TaskStatus::PROCESSING) {
                return false;
            }

            $task->forceFill([
                'status' => TaskStatus::COMPLETED,
                'completed_at' => now(),
            ])->save();

            return true;
        });
    }

    public function fail(string $taskId, string $message): bool
    {
        return DB::transaction(function () use ($taskId, $message): bool {
            $task = Task::query()->whereKey($taskId)->lockForUpdate()->first();

            if ($task === null || in_array($task->status, [TaskStatus::COMPLETED, TaskStatus::CANCELLED], true)) {
                return false;
            }

            $task->forceFill([
                'status' => TaskStatus::FAILED,
                'failed_at' => now(),
                'error_message' => $message,
            ])->save();

            return true;
        });
    }

    public function requeue(string $taskId, string $message): bool
    {
        return DB::transaction(function () use ($taskId, $message): bool {
            $task = Task::query()->whereKey($taskId)->lockForUpdate()->first();

            if ($task === null || $task->status !== TaskStatus::PROCESSING) {
                return false;
            }

            $task->forceFill([
                'status' => TaskStatus::PENDING,
                'error_message' => $message,
            ])->save();

            return true;
        });
    }

    public function safeMessage(Throwable $exception): string
    {
        return mb_substr($exception->getMessage() ?: 'Task processing failed.', 0, 1000);
    }
}
