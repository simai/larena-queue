<?php

declare(strict_types=1);

namespace Larena\Queue\Tests\Support;

use DateInterval;
use DateTimeImmutable;
use Larena\Queue\Contracts\QueueClock;

final class FrozenQueueClock implements QueueClock
{
    public function __construct(private DateTimeImmutable $time)
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->time;
    }

    public function advanceSeconds(int $seconds): void
    {
        $this->time = $this->time->add(new DateInterval('PT'.$seconds.'S'));
    }
}
