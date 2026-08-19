<?php

declare(strict_types=1);

namespace App\Services\Tasks;

use App\Exceptions\Tasks\TaskCancelledException;
use App\Models\Task;
use Throwable;

class TaskExecutionService
{
    public function __construct(
        private readonly TaskStateService $states,
        private readonly TaskLockService $locks,
        private readonly TaskProcessorRegistry $processors,
        private readonly TaskLogService $logs,
        private readonly TaskCancellationService $cancellation,
    ) {}

    public function execute(Task $task): void
    {
        $this->locks->run($task, function () use ($task): void {
            $startedTask = $this->states->start($task->getKey());

            if ($startedTask === null) {
                return;
            }

            $this->logs->record($startedTask, 'processing_started', metadata: [
                'attempt' => $startedTask->attempts,
            ]);

            try {
                $this->processors->resolve($startedTask->type)->process($startedTask);
                $this->cancellation->throwIfCancelled($startedTask);
            } catch (TaskCancelledException $exception) {
                $this->logs->record($startedTask->fresh(), 'cancelled', $exception->getMessage());

                return;
            } catch (Throwable $exception) {
                $message = $this->states->safeMessage($exception);
                $maxAttempts = (int) config('tasks.max_attempts');

                if ($startedTask->attempts < $maxAttempts && $this->isRetryable($exception)) {
                    $this->states->requeue($startedTask->getKey(), $message);
                    $this->logs->record($startedTask->fresh(), 'processing_failed', $message, [
                        'retryable' => true,
                    ]);
                } else {
                    $this->states->fail($startedTask->getKey(), $message);
                    $this->logs->record($startedTask->fresh(), 'processing_failed', $message, [
                        'retryable' => false,
                    ]);
                }

                throw $exception;
            }

            if ($this->states->complete($startedTask->getKey())) {
                $this->logs->record($startedTask->fresh(), 'processing_completed');
            }
        });
    }

    public function fail(Task $task, ?Throwable $exception): void
    {
        $message = $exception === null ? 'Task processing failed.' : $this->states->safeMessage($exception);

        if ($this->states->fail($task->getKey(), $message)) {
            $this->logs->record($task->fresh(), 'processing_failed', $message, [
                'retryable' => false,
            ]);
        }
    }

    private function isRetryable(Throwable $exception): bool
    {
        foreach ((array) config('tasks.retryable_exceptions', []) as $class) {
            if ($exception instanceof $class) {
                return true;
            }
        }

        return false;
    }
}
