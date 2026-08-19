<?php

declare(strict_types=1);

namespace App\Services\Tasks;

use App\Jobs\Tasks\ProcessTaskJob;
use App\Models\Task;

class TaskDispatcher
{
    public function dispatch(Task $task): void
    {
        ProcessTaskJob::dispatch($task->getKey(), $task->priority)
            ->onConnection('redis')
            ->onQueue(config('tasks.queues.'.$task->priority->value));
    }
}
