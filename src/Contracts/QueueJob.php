<?php

declare(strict_types=1);

namespace Larena\Queue\Contracts;

use Larena\Queue\Enums\JobStatus;

interface QueueJob
{
    public function jobId(): string;

    public function descriptorName(): string;

    public function status(): JobStatus;

    public function idempotencyKey(): string;

    /**
     * @return array<string, mixed>
     */
    public function payload(): array;
}
