<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_task_uses_ulid_and_enum_casts_and_has_logs(): void
    {
        $user = User::factory()->create();
        $task = $user->tasks()->create([
            'type' => TaskType::REPORT,
            'title' => 'Monthly report',
            'payload' => ['report_id' => 'monthly', 'format' => 'csv'],
            'status' => TaskStatus::PENDING,
            'priority' => TaskPriority::HIGH,
        ]);

        $task->logs()->create(['event' => 'created', 'metadata' => ['source' => 'test']]);
        $reloaded = Task::query()->with('logs')->findOrFail($task->getKey());

        $this->assertSame(26, strlen($reloaded->getKey()));
        $this->assertInstanceOf(TaskType::class, $reloaded->type);
        $this->assertInstanceOf(TaskStatus::class, $reloaded->status);
        $this->assertInstanceOf(TaskPriority::class, $reloaded->priority);
        $this->assertSame(['report_id' => 'monthly', 'format' => 'csv'], $reloaded->payload);
        $this->assertCount(1, $reloaded->logs);
    }
}
