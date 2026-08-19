<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Jobs\Tasks\ProcessTaskJob;
use App\Models\Task;
use App\Models\User;
use App\Services\Tasks\Processors\BulkNotificationTaskProcessor;
use App\Services\Tasks\Processors\ReportTaskProcessor;
use App\Services\Tasks\TaskCancellationService;
use App\Services\Tasks\TaskDispatcher;
use App\Services\Tasks\TaskExecutionService;
use App\Services\Tasks\TaskProcessorRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Tests\TestCase;

class TaskArchitectureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('tasks.lock_store', 'array');
    }

    public function test_registry_resolves_each_supported_processor(): void
    {
        $registry = app(TaskProcessorRegistry::class);

        $this->assertSame(TaskType::REPORT, $this->processorType($registry, TaskType::REPORT));
        $this->assertSame(TaskType::BULK_NOTIFICATION, $this->processorType($registry, TaskType::BULK_NOTIFICATION));
        $this->assertSame(TaskType::DATA_PROCESSING, $this->processorType($registry, TaskType::DATA_PROCESSING));
    }

    public function test_task_executes_from_pending_to_completed(): void
    {
        $task = $this->makeTask(TaskType::REPORT, [
            'report_id' => 'monthly',
            'format' => 'csv',
            'sections' => 2,
        ]);

        app(TaskExecutionService::class)->execute($task);
        $task->refresh();

        $this->assertSame(TaskStatus::COMPLETED, $task->status);
        $this->assertSame(1, $task->attempts);
        $this->assertNotNull($task->completed_at);
        $this->assertSame(['processing_started', 'processing_completed'], $task->logs()->pluck('event')->all());
    }

    public function test_invalid_payload_fails_without_retrying_forever(): void
    {
        config()->set('tasks.max_attempts', 4);
        $task = $this->makeTask(TaskType::REPORT, []);

        try {
            app(TaskExecutionService::class)->execute($task);
        } catch (InvalidArgumentException) {
            // Expected permanent processor validation failure.
        }

        $task->refresh();
        $this->assertSame(TaskStatus::FAILED, $task->status);
        $this->assertNotNull($task->failed_at);
        $this->assertSame(1, $task->attempts);
    }

    public function test_dispatcher_maps_priority_to_dedicated_queue(): void
    {
        Queue::fake();
        $task = $this->makeTask(TaskType::REPORT, [
            'report_id' => 'monthly',
            'format' => 'csv',
        ], TaskPriority::CRITICAL);

        app(TaskDispatcher::class)->dispatch($task);

        Queue::assertPushedOn('tasks-critical', ProcessTaskJob::class, function (ProcessTaskJob $job) use ($task): bool {
            return $job->taskId === $task->getKey();
        });
    }

    public function test_job_exposes_configured_retry_policy(): void
    {
        $job = new ProcessTaskJob('01j00000000000000000000000', TaskPriority::LOW);

        $this->assertSame(4, $job->tries);
        $this->assertSame([10, 30, 120, 300], $job->backoff());
        $this->assertSame('tasks-low', $job->queue);
    }

    public function test_duplicate_execution_after_completion_is_a_no_op(): void
    {
        $task = $this->makeTask(TaskType::DATA_PROCESSING, [
            'source' => 'imports',
            'operation' => 'normalize',
            'records' => 2,
        ]);
        $execution = app(TaskExecutionService::class);

        $execution->execute($task);
        $execution->execute($task->fresh());

        $task->refresh();
        $this->assertSame(TaskStatus::COMPLETED, $task->status);
        $this->assertSame(1, $task->attempts);
        $this->assertSame(1, $task->logs()->where('event', 'processing_started')->count());
    }

    public function test_cancelled_task_is_not_executed(): void
    {
        $task = $this->makeTask(TaskType::REPORT, [
            'report_id' => 'monthly',
            'format' => 'csv',
        ]);

        $this->assertTrue(app(TaskCancellationService::class)->cancel($task));
        app(TaskExecutionService::class)->execute($task->fresh());

        $task->refresh();
        $this->assertSame(TaskStatus::CANCELLED, $task->status);
        $this->assertSame(0, $task->attempts);
    }

    public function test_retryable_failure_returns_task_to_pending(): void
    {
        $task = $this->makeTask(TaskType::REPORT, [
            'report_id' => 'monthly',
            'format' => 'csv',
        ]);
        $this->mock(TaskProcessorRegistry::class, function ($mock): void {
            $mock->shouldReceive('resolve')->once()->andThrow(new \RuntimeException('temporary failure'));
        });

        try {
            app(TaskExecutionService::class)->execute($task);
        } catch (\RuntimeException) {
            // The queue retries the thrown transient failure.
        }

        $task->refresh();
        $this->assertSame(TaskStatus::PENDING, $task->status);
        $this->assertSame(1, $task->attempts);
    }

    private function makeTask(TaskType $type, array $payload, TaskPriority $priority = TaskPriority::NORMAL): Task
    {
        return User::factory()->create()->tasks()->create([
            'type' => $type,
            'title' => 'Test task',
            'payload' => $payload,
            'priority' => $priority,
            'status' => TaskStatus::PENDING,
        ]);
    }

    private function processorType(TaskProcessorRegistry $registry, TaskType $type): TaskType
    {
        return match (true) {
            $registry->resolve($type) instanceof ReportTaskProcessor => TaskType::REPORT,
            $registry->resolve($type) instanceof BulkNotificationTaskProcessor => TaskType::BULK_NOTIFICATION,
            default => TaskType::DATA_PROCESSING,
        };
    }
}
