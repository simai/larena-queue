<?php

declare(strict_types=1);

namespace Larena\Queue\Data;

use DateInterval;
use DateTimeImmutable;
use Larena\Queue\Contracts\QueueClock;
use Larena\Queue\Exceptions\QueueExecutionCancelled;
use Larena\Queue\Exceptions\QueueExecutionTimedOut;
use Larena\Queue\Storage\DatabaseQueueStore;

final readonly class QueueExecutionContext
{
    public function __construct(
        public string $jobId,
        public string $correlationId,
        public int $attempt,
        private QueueLease $lease,
        private DateTimeImmutable $deadline,
        private QueueClock $clock,
        private DatabaseQueueStore $store,
    ) {
    }

    public function checkpoint(): void
    {
        if ($this->clock->now() >= $this->deadline) {
            throw new QueueExecutionTimedOut();
        }
        if ($this->store->cancellationRequested($this->lease)) {
            throw new QueueExecutionCancelled();
        }
    }

    public function heartbeat(): void
    {
        $this->checkpoint();
        $now = $this->clock->now();
        $this->store->heartbeat(
            $this->lease,
            $now,
            $now->add(new DateInterval('PT'.$this->lease->job->leaseSeconds.'S')),
        );
    }
}
