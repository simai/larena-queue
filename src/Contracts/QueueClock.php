<?php

declare(strict_types=1);

namespace Larena\Queue\Contracts;

use DateTimeImmutable;

interface QueueClock
{
    public function now(): DateTimeImmutable;
}
