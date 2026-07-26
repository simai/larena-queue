<?php

declare(strict_types=1);

namespace Larena\Queue\Runtime;

use Larena\Queue\Contracts\QueueClock;
use Larena\Queue\Data\QueueJobSnapshot;
use Larena\Queue\Storage\DatabaseQueueStore;

final readonly class QueueControlService
{
    public function __construct(
        private DatabaseQueueStore $store,
        private QueueClock $clock,
    ) {
    }

    public function status(string $jobId): QueueJobSnapshot
    {
        return $this->store->require($jobId);
    }

    public function cancel(string $jobId): QueueJobSnapshot
    {
        return $this->store->requestCancellation($jobId, $this->clock->now());
    }

    public function retry(string $jobId): QueueJobSnapshot
    {
        return $this->store->retry($jobId, $this->clock->now());
    }

    public function delete(string $jobId): void
    {
        $this->store->delete($jobId);
    }

    public function reclaimExpired(): int
    {
        return $this->store->reclaimExpired($this->clock->now());
    }
}
