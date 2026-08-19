<?php

declare(strict_types=1);

namespace App\Jobs\Tasks;

use App\Enums\TaskPriority;
use App\Models\Task;
use App\Services\Tasks\TaskExecutionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ProcessTaskJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries;

    public function __construct(
        public readonly string $taskId,
        ?TaskPriority $priority = null,
    ) {
        $this->tries = (int) config('tasks.max_attempts', 4);

        if ($priority !== null) {
            $this->onQueue(config('tasks.queues.'.$priority->value));
        }
    }

    public function backoff(): array
    {
        return (array) config('tasks.retry_backoff', [10, 30, 120, 300]);
    }

    public function handle(TaskExecutionService $execution): void
    {
        $task = Task::query()->find($this->taskId);

        if ($task !== null) {
            $execution->execute($task);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $task = Task::query()->find($this->taskId);

        if ($task !== null) {
            app(TaskExecutionService::class)->fail($task, $exception);
        }
    }
}
