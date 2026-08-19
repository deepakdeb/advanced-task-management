<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTaskRequest;
use App\Http\Requests\TaskFilterRequest;
use App\Http\Resources\TaskResource;
use App\Models\Task;
use App\Services\Tasks\TaskCancellationService;
use App\Services\Tasks\TaskDispatcher;
use App\Services\Tasks\TaskLogService;
use App\Services\Tasks\TaskRetryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class TaskController extends Controller
{
    public function index(TaskFilterRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();
        $query = $request->user()->tasks()->latest('created_at');

        foreach (['status', 'type', 'priority'] as $field) {
            if (isset($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }

        if (isset($filters['from'])) {
            $query->where('created_at', '>=', $filters['from']);
        }
        if (isset($filters['to'])) {
            $query->where('created_at', '<=', $filters['to']);
        }

        return TaskResource::collection($query
            ->orderBy($filters['sort'] ?? 'created_at', $filters['direction'] ?? 'desc')
            ->paginate((int) ($filters['per_page'] ?? 15)));
    }

    public function store(StoreTaskRequest $request, TaskLogService $logs, TaskDispatcher $dispatcher): JsonResponse
    {
        $task = DB::transaction(function () use ($request, $logs): Task {
            $task = $request->user()->tasks()->create([
                'type' => $request->validated('type'),
                'title' => $request->validated('title'),
                'payload' => $request->validated('payload'),
                'priority' => $request->validated('priority', TaskPriority::NORMAL->value),
            ]);
            $task->forceFill(['status' => TaskStatus::PENDING])->save();
            $logs->record($task, 'created');

            return $task;
        });

        DB::afterCommit(function () use ($task, $dispatcher, $logs): void {
            $dispatcher->dispatch($task);
            $logs->record($task->fresh(), 'queued');
        });

        return (new TaskResource($task))->response()->setStatusCode(201);
    }

    public function show(Task $task): TaskResource
    {
        $this->authorize('view', $task);

        return new TaskResource($task);
    }

    public function cancel(Task $task, TaskCancellationService $cancellation): JsonResponse
    {
        $this->authorize('cancel', $task);

        if (! $cancellation->cancel($task)) {
            return response()->json(['message' => 'The task cannot be cancelled in its current state.'], 409);
        }

        return response()->json(['message' => 'Task cancelled.']);
    }

    public function retry(Task $task, TaskRetryService $retry, TaskDispatcher $dispatcher, TaskLogService $logs): JsonResponse
    {
        $this->authorize('retry', $task);

        if (! $retry->retry($task)) {
            return response()->json(['message' => 'Only failed tasks can be retried.'], 409);
        }

        $task->refresh();
        DB::afterCommit(function () use ($task, $dispatcher, $logs): void {
            $dispatcher->dispatch($task);
            $logs->record($task->fresh(), 'retry_dispatched');
        });

        return response()->json(new TaskResource($task));
    }
}
