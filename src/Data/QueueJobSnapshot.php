<?php

declare(strict_types=1);

namespace Larena\Queue\Data;

use DateTimeImmutable;
use Larena\Queue\Enums\JobStatus;
use Larena\Queue\Enums\QueuePriority;

final readonly class QueueJobSnapshot
{
    /**
     * @param array<string, mixed> $payload
     * @param array<string, scalar|null> $result
     */
    public function __construct(
        public string $id,
        public string $jobType,
        public string $handlerRef,
        public array $payload,
        public string $payloadSha256,
        public string $idempotencyKey,
        public string $correlationId,
        public QueuePriority $priority,
        public JobStatus $status,
        public int $attempts,
        public int $maxAttempts,
        public int $timeoutSeconds,
        public int $retryDelaySeconds,
        public int $leaseSeconds,
        public DateTimeImmutable $availableAt,
        public ?DateTimeImmutable $leaseExpiresAt,
        public ?DateTimeImmutable $heartbeatAt,
        public ?DateTimeImmutable $cancelRequestedAt,
        public ?string $failureReason,
        public array $result,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
    }

    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }
}
