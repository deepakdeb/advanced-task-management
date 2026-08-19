<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\Tasks\TaskProcessorInterface;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Jobs\Tasks\ProcessTaskJob;
use App\Models\Task;
use App\Models\User;
use App\Services\Tasks\TaskExecutionService;
use App\Services\Tasks\TaskProcessorRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class TaskApiAndLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('tasks.lock_store', 'array');
    }

    public function test_authenticated_user_can_create_and_list_only_owned_tasks(): void
    {
        Queue::fake();
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $this->createTask($owner);
        $this->createTask($other);

        $response = $this->actingAs($owner, 'sanctum')->getJson('/api/tasks?status=pending&sort=created_at&direction=asc');

        $response->assertOk()->assertJsonCount(1, 'data');

        $create = $this->actingAs($owner, 'sanctum')->postJson('/api/tasks', $this->attributes());
        $create->assertCreated()->assertJsonPath('data.status', TaskStatus::PENDING->value);
        Queue::assertPushed(ProcessTaskJob::class);
    }

    public function test_task_payload_is_validated_by_type(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')->postJson('/api/tasks', [
            'type' => TaskType::REPORT->value,
            'title' => 'Invalid report',
            'payload' => ['source' => 'wrong'],
        ])->assertUnprocessable();
    }

    public function test_user_cannot_view_or_cancel_another_users_task(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $task = $this->createTask($owner);

        $this->actingAs($other, 'sanctum')->getJson("/api/tasks/{$task->getKey()}")->assertForbidden();
        $this->actingAs($other, 'sanctum')->postJson("/api/tasks/{$task->getKey()}/cancel")->assertForbidden();
    }

    public function test_failed_task_can_be_retried_and_dispatch_is_logged(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $task = $this->createTask($user, ['status' => TaskStatus::FAILED]);

        $this->actingAs($user, 'sanctum')->postJson("/api/tasks/{$task->getKey()}/retry")->assertOk();

        $task->refresh();
        $this->assertSame(TaskStatus::PENDING, $task->status);
        Queue::assertPushed(ProcessTaskJob::class);
        $this->assertDatabaseHas('task_logs', ['task_id' => $task->getKey(), 'event' => 'retry_dispatched']);
    }

    public function test_cleanup_marks_only_stale_processing_tasks_failed(): void
    {
        $user = User::factory()->create();
        $stale = $this->createTask($user, [
            'status' => TaskStatus::PROCESSING,
            'started_at' => now()->subMinutes(31),
        ]);
        $recent = $this->createTask($user, [
            'status' => TaskStatus::PROCESSING,
            'started_at' => now()->subMinutes(5),
        ]);

        $this->artisan('tasks:cleanup-stale')->assertSuccessful();

        $this->assertSame(TaskStatus::FAILED, $stale->fresh()->status);
        $this->assertSame(TaskStatus::PROCESSING, $recent->fresh()->status);
        $this->assertDatabaseHas('task_logs', ['task_id' => $stale->getKey(), 'event' => 'stale']);
    }

    public function test_task_cancelled_during_processing_cannot_complete(): void
    {
        $user = User::factory()->create();
        $task = $this->createTask($user);
        $taskId = $task->getKey();

        $processor = Mockery::mock(TaskProcessorInterface::class);
        $processor->shouldReceive('process')->once()->andReturnUsing(function () use ($taskId): void {
            Task::query()->whereKey($taskId)->update(['status' => TaskStatus::CANCELLED->value]);
        });
        $this->mock(TaskProcessorRegistry::class, function ($mock) use ($processor): void {
            $mock->shouldReceive('resolve')->once()->andReturn($processor);
        });

        app(TaskExecutionService::class)->execute($task);

        $this->assertSame(TaskStatus::CANCELLED, $task->fresh()->status);
        $this->assertDatabaseMissing('task_logs', ['task_id' => $taskId, 'event' => 'processing_completed']);
    }

    private function attributes(array $overrides = []): array
    {
        return array_merge([
            'type' => TaskType::REPORT,
            'title' => 'Monthly report',
            'payload' => ['report_id' => 'monthly', 'format' => 'csv'],
            'priority' => TaskPriority::NORMAL,
            'status' => TaskStatus::PENDING,
        ], $overrides);
    }

    private function createTask(User $user, array $overrides = []): Task
    {
        $attributes = $this->attributes($overrides);
        $task = $user->tasks()->create(array_diff_key($attributes, array_flip(['status', 'started_at'])));
        $task->forceFill(array_intersect_key($attributes, array_flip(['status', 'started_at'])))->save();

        return $task->fresh();
    }
}
