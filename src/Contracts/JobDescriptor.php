<?php

declare(strict_types=1);

namespace Larena\Queue\Contracts;

use Larena\Queue\Enums\QueuePriority;

interface JobDescriptor
{
    public function name(): string;

    public function sourcePackage(): string;

    public function timeoutSeconds(): int;

    public function maxAttempts(): int;

    public function idempotencyStrategy(): string;

    public function priority(): QueuePriority;

    public function auditCategory(): string;

    /**
     * @return array<string, string>
     */
    public function payloadSchema(): array;
}
