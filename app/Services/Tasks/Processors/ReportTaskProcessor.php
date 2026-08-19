<?php

declare(strict_types=1);

namespace App\Services\Tasks\Processors;

use App\Contracts\Tasks\TaskProcessorInterface;
use App\Models\Task;
use App\Services\Tasks\TaskCancellationService;
use InvalidArgumentException;

class ReportTaskProcessor implements TaskProcessorInterface
{
    public function __construct(private readonly TaskCancellationService $cancellation) {}

    public function process(Task $task): void
    {
        $reportId = $task->payload['report_id'] ?? null;
        $format = $task->payload['format'] ?? null;

        if (! is_string($reportId) || ! is_string($format)) {
            throw new InvalidArgumentException('A report requires report_id and format.');
        }

        $batchSize = max(1, (int) config('tasks.batch_sizes.report', 100));
        $sections = max(1, (int) ($task->payload['sections'] ?? 3));

        for ($offset = 0; $offset < $sections; $offset += $batchSize) {
            $this->cancellation->throwIfCancelled($task);
            min($batchSize, $sections - $offset);
        }

        $this->cancellation->throwIfCancelled($task);
    }
}
