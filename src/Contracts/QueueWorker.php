<?php

declare(strict_types=1);

namespace Larena\Queue\Contracts;

use Larena\Queue\Data\QueueJobSnapshot;

interface QueueWorker
{
    public function runNext(string $workerRef): ?QueueJobSnapshot;
}
