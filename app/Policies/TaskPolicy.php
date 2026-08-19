<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Task;
use App\Models\User;

class TaskPolicy
{
    public function view(User $user, Task $task): bool
    {
        return (string) $user->getKey() === (string) $task->user_id;
    }

    public function cancel(User $user, Task $task): bool
    {
        return $this->view($user, $task);
    }

    public function retry(User $user, Task $task): bool
    {
        return $this->view($user, $task);
    }
}
