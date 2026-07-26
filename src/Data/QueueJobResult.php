<?php

declare(strict_types=1);

namespace Larena\Queue\Data;

use InvalidArgumentException;

final readonly class QueueJobResult
{
    /**
     * @param array<string, scalar|null> $metadata
     */
    private function __construct(
        public bool $succeeded,
        public bool $retryable,
        public ?string $reasonCode,
        public array $metadata,
    ) {
        if (strlen(json_encode($metadata, JSON_THROW_ON_ERROR)) > 16384) {
            throw new InvalidArgumentException('Queue result metadata exceeds the bounded size.');
        }
        if ($reasonCode !== null && preg_match('/\A[a-z][a-z0-9_.-]{0,99}\z/D', $reasonCode) !== 1) {
            throw new InvalidArgumentException('Queue result reason code is invalid.');
        }
    }

    /**
     * @param array<string, scalar|null> $metadata
     */
    public static function success(array $metadata = []): self
    {
        return new self(true, false, null, $metadata);
    }

    /**
     * @param array<string, scalar|null> $metadata
     */
    public static function failure(string $reasonCode, bool $retryable, array $metadata = []): self
    {
        return new self(false, $retryable, $reasonCode, $metadata);
    }
}
