<?php

declare(strict_types=1);

namespace App\Services\Tasks\Processors;

use App\Contracts\Tasks\TaskProcessorInterface;
use App\Models\Task;
use App\Services\Tasks\TaskCancellationService;
use InvalidArgumentException;

class BulkNotificationTaskProcessor implements TaskProcessorInterface
{
    public function __construct(private readonly TaskCancellationService $cancellation) {}

    public function process(Task $task): void
    {
        $template = $task->payload['notification_template'] ?? null;
        $recipients = $task->payload['recipient_ids'] ?? null;

        if (! is_string($template) || ! is_array($recipients)) {
            throw new InvalidArgumentException('Bulk notifications require a template and recipient_ids.');
        }

        $batchSize = max(1, (int) config('tasks.batch_sizes.bulk_notification', 100));
        foreach (array_chunk($recipients, $batchSize) as $batch) {
            $this->cancellation->throwIfCancelled($task);
            if ($batch === []) {
                throw new InvalidArgumentException('Notification batches cannot be empty.');
            }
            $this->simulateWork();
        }

        $this->cancellation->throwIfCancelled($task);
    }

    private function simulateWork(): void
    {
        $delay = max(0, (int) config('tasks.processing_delay_ms', 0));
        if ($delay > 0) {
            usleep($delay * 1000);
        }
    }
}
