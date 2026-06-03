<?php

declare(strict_types=1);

namespace Larena\Queue\Enums;

enum JobStatus: string
{
    case Declared = 'declared';
    case Queued = 'queued';
    case Delayed = 'delayed';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Blocked = 'blocked';
}
