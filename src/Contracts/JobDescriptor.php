<?php

declare(strict_types=1);

namespace Larena\Queue\Contracts;

use Larena\Queue\Enums\QueuePriority;
use Larena\Queue\Enums\RuntimeProfile;

interface JobDescriptor
{
    public function jobType(): string;

    public function operationRef(): string;

    public function handlerRef(): string;

    public function timeoutSeconds(): int;

    public function maxAttempts(): int;

    public function idempotencyKeyStrategy(): string;

    public function priority(): QueuePriority;

    public function auditPolicy(): string;

    public function payloadSchemaRef(): string;

    public function allowsSecretReferences(): bool;

    /**
     * @return list<RuntimeProfile>
     */
    public function allowedRuntimeProfiles(): array;
}
