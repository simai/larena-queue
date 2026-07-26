<?php

declare(strict_types=1);

namespace Larena\Queue\Data;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class DispatchRequest
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public string $jobType,
        public array $payload,
        public string $idempotencyKey,
        public string $correlationId,
        public ?DateTimeImmutable $availableAt = null,
    ) {
        foreach ([
            'job_type' => [$jobType, 100, '/\A[a-z][a-z0-9_.-]*\z/D'],
            'idempotency_key' => [$idempotencyKey, 128, '/\A[^\x00-\x1F\x7F]+\z/D'],
            'correlation_id' => [$correlationId, 64, '/\A[a-zA-Z0-9_.:-]+\z/D'],
        ] as $field => [$value, $limit, $pattern]) {
            if ($value === '' || strlen($value) > $limit || preg_match($pattern, $value) !== 1) {
                throw new InvalidArgumentException('Invalid queue '.$field.'.');
            }
        }

        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        if (strlen($json) > 65535) {
            throw new InvalidArgumentException('Queue payload exceeds the bounded size.');
        }
    }
}
