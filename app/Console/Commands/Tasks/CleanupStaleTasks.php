<?php

declare(strict_types=1);

namespace App\Console\Commands\Tasks;

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Services\Tasks\TaskLockService;
use App\Services\Tasks\TaskLogService;
use App\Services\Tasks\TaskStateService;
use Illuminate\Console\Command;

class CleanupStaleTasks extends Command
{
    protected $signature = 'tasks:cleanup-stale';

    protected $description = 'Mark stale processing tasks as failed.';

    public function handle(TaskLockService $locks, TaskStateService $states, TaskLogService $logs): int
    {
        $cutoff = now()->subSeconds((int) config('tasks.stale_timeout', 1800));
        $count = 0;

        Task::query()
            ->where('status', TaskStatus::PROCESSING)
            ->where('started_at', '<', $cutoff)
            ->each(function (Task $task) use ($locks, $states, $logs, &$count): void {
                $locks->run($task, function () use ($task, $states, $logs, &$count): void {
                    $current = $task->fresh();
                    if ($current === null || $current->status !== TaskStatus::PROCESSING) {
                        return;
                    }

                    if ($states->fail($current->getKey(), 'Task exceeded the processing timeout.')) {
                        $logs->record($current->fresh(), 'stale', 'Task exceeded the processing timeout.');
                        $count++;
                    }
                });
            });

        $this->info("Marked {$count} stale task(s) as failed.");

        return self::SUCCESS;
    }
}
