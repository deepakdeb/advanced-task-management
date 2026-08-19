<?php

declare(strict_types=1);

namespace App\Services\Tasks;

use App\Models\Task;
use Closure;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

class TaskLockService
{
    public function run(Task $task, Closure $callback): mixed
    {
        /** @var LockProvider $store */
        $store = Cache::store(config('tasks.lock_store'));
        $lock = $store->lock(
            'task-execution:'.$task->executionKey(),
            (int) config('tasks.lock_timeout'),
        );

        try {
            return $lock->get($callback);
        } catch (LockTimeoutException) {
            return null;
        } finally {
            optional($lock)->release();
        }
    }
}
