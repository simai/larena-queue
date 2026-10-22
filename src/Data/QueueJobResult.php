<?php

declare(strict_types=1);

namespace Larena\Queue\Data;

use InvalidArgumentException;

final readonly class QueueJobResult
{
    /** The longest a handler may ask the queue to wait before it runs the job again. */
    public const int MAX_DEFERRAL_SECONDS = 3600;

    /**
     * @param array<string, scalar|null> $metadata
     * @param int|null $deferSeconds set only by {@see retryLater()}: run again after this many seconds
     */
    private function __construct(
        public bool $succeeded,
        public bool $retryable,
        public ?string $reasonCode,
        public array $metadata,
        public ?int $deferSeconds = null,
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

    /**
     * «Not yet»: what the job waits for is not ready (a list still being built, a remote still busy). The queue
     * runs the job again after the delay (1 s to {@see MAX_DEFERRAL_SECONDS}, clamped) without spending an attempt;
     * it counts the deferrals instead and fails the job with job_deferral_limit_reached past the descriptor's
     * limit ({@see \Larena\Queue\Contracts\DeferrableJobDescriptor}). The reason code is kept on the job meanwhile.
     *
     * @param array<string, scalar|null> $metadata
     */
    public static function retryLater(int $delaySeconds, string $reasonCode, array $metadata = []): self
    {
        return new self(false, true, $reasonCode, $metadata, max(1, min(self::MAX_DEFERRAL_SECONDS, $delaySeconds)));
    }

    public function isDeferral(): bool
    {
        return $this->deferSeconds !== null;
    }
}
