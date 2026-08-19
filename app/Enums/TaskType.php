<?php

declare(strict_types=1);

namespace App\Enums;

enum TaskType: string
{
    case REPORT = 'report';
    case BULK_NOTIFICATION = 'bulk_notification';
    case DATA_PROCESSING = 'data_processing';
}
