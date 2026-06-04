<?php

declare(strict_types=1);

namespace Larena\Queue\Enums;

enum QueuePriority: string
{
    case Critical = 'critical';
    case High = 'high';
    case Normal = 'normal';
    case Low = 'low';
    case Maintenance = 'maintenance';
}
