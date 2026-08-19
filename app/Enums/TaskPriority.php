<?php

declare(strict_types=1);

namespace App\Enums;

enum TaskPriority: string
{
    case CRITICAL = 'critical';
    case HIGH = 'high';
    case NORMAL = 'normal';
    case LOW = 'low';
}
