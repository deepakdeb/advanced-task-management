<?php

declare(strict_types=1);

namespace App\Contracts\Tasks;

use App\Models\Task;

interface TaskProcessorInterface
{
    public function process(Task $task): void;
}
