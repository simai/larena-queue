<?php

declare(strict_types=1);

namespace Larena\Queue\Enums;

enum JobStatus: string
{
    case Created = 'created';
    case Queued = 'queued';
    case Deferred = 'deferred';
    case Leased = 'leased';
    case Running = 'running';
    case Retrying = 'retrying';
    case TimedOut = 'timed_out';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Completed = 'completed';

    public function isTerminal(): bool
    {
        return in_array($this, [self::TimedOut, self::Failed, self::Cancelled, self::Completed], true);
    }
}
