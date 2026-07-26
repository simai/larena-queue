<?php

declare(strict_types=1);

namespace Larena\Queue\Data;

final readonly class DispatchResult
{
    public function __construct(
        public string $jobId,
        public bool $duplicate,
    ) {
    }
}
