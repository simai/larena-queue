<?php

declare(strict_types=1);

namespace Larena\Queue\Runtime;

use DateTimeImmutable;
use DateTimeZone;
use Larena\Queue\Contracts\QueueClock;

final class SystemQueueClock implements QueueClock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
