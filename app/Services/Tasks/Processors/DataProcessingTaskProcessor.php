<?php

declare(strict_types=1);

namespace App\Services\Tasks\Processors;

use App\Contracts\Tasks\TaskProcessorInterface;
use App\Models\Task;
use App\Services\Tasks\TaskCancellationService;
use InvalidArgumentException;

class DataProcessingTaskProcessor implements TaskProcessorInterface
{
    public function __construct(private readonly TaskCancellationService $cancellation) {}

    public function process(Task $task): void
    {
        $source = $task->payload['source'] ?? null;
        $operation = $task->payload['operation'] ?? null;

        if (! is_string($source) || ! is_string($operation)) {
            throw new InvalidArgumentException('Data processing requires source and operation.');
        }

        $batchSize = max(1, (int) config('tasks.batch_sizes.data_processing', 500));
        $records = max(1, (int) ($task->payload['records'] ?? 1));

        for ($offset = 0; $offset < $records; $offset += $batchSize) {
            $this->cancellation->throwIfCancelled($task);
            min($batchSize, $records - $offset);
        }

        $this->cancellation->throwIfCancelled($task);
    }
}
