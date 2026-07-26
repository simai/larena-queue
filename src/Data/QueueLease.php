<?php

declare(strict_types=1);

namespace Larena\Queue\Data;

use DateTimeImmutable;

final readonly class QueueLease
{
    public function __construct(
        public QueueJobSnapshot $job,
        public string $token,
        public DateTimeImmutable $expiresAt,
    ) {
    }
}
