<?php

declare(strict_types=1);

namespace App\Services\Tasks;

use App\Contracts\Tasks\TaskProcessorInterface;
use App\Enums\TaskType;
use App\Services\Tasks\Processors\BulkNotificationTaskProcessor;
use App\Services\Tasks\Processors\DataProcessingTaskProcessor;
use App\Services\Tasks\Processors\ReportTaskProcessor;
use InvalidArgumentException;

class TaskProcessorRegistry
{
    /** @var array<string, TaskProcessorInterface> */
    private array $processors;

    public function __construct(
        ReportTaskProcessor $report,
        BulkNotificationTaskProcessor $bulkNotification,
        DataProcessingTaskProcessor $dataProcessing,
    ) {
        $this->processors = [
            TaskType::REPORT->value => $report,
            TaskType::BULK_NOTIFICATION->value => $bulkNotification,
            TaskType::DATA_PROCESSING->value => $dataProcessing,
        ];
    }

    public function resolve(TaskType $type): TaskProcessorInterface
    {
        return $this->processors[$type->value]
            ?? throw new InvalidArgumentException("No processor registered for {$type->value}.");
    }
}
