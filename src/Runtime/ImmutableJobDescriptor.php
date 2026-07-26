<?php

declare(strict_types=1);

namespace Larena\Queue\Runtime;

use InvalidArgumentException;
use Larena\Queue\Contracts\JobDescriptor;
use Larena\Queue\Enums\QueuePriority;
use Larena\Queue\Enums\RuntimeProfile;

final readonly class ImmutableJobDescriptor implements JobDescriptor
{
    /**
     * @param list<RuntimeProfile> $runtimeProfiles
     */
    public function __construct(
        private string $jobType,
        private string $operationRef,
        private string $handlerRef,
        private int $timeoutSeconds,
        private int $maxAttempts,
        private int $retryDelaySeconds,
        private int $leaseSeconds,
        private QueuePriority $priority,
        private string $auditPolicy,
        private string $payloadSchemaRef,
        private array $runtimeProfiles = [RuntimeProfile::LarenaDispatcher],
    ) {
        if (
            preg_match('/\A[a-z][a-z0-9_.-]{0,99}\z/D', $jobType) !== 1
            || preg_match('/\A[a-zA-Z][a-zA-Z0-9_.:-]{0,149}\z/D', $handlerRef) !== 1
            || $timeoutSeconds < 1
            || $timeoutSeconds > 86400
            || $maxAttempts < 1
            || $maxAttempts > 100
            || $retryDelaySeconds < 0
            || $retryDelaySeconds > 86400
            || $leaseSeconds < 1
            || $leaseSeconds > $timeoutSeconds
            || $runtimeProfiles === []
        ) {
            throw new InvalidArgumentException('Invalid immutable Queue job descriptor.');
        }
    }

    public function jobType(): string { return $this->jobType; }
    public function operationRef(): string { return $this->operationRef; }
    public function handlerRef(): string { return $this->handlerRef; }
    public function timeoutSeconds(): int { return $this->timeoutSeconds; }
    public function maxAttempts(): int { return $this->maxAttempts; }
    public function retryDelaySeconds(): int { return $this->retryDelaySeconds; }
    public function leaseSeconds(): int { return $this->leaseSeconds; }
    public function idempotencyKeyStrategy(): string { return 'caller_supplied_bounded_key'; }
    public function priority(): QueuePriority { return $this->priority; }
    public function auditPolicy(): string { return $this->auditPolicy; }
    public function payloadSchemaRef(): string { return $this->payloadSchemaRef; }
    public function allowsSecretReferences(): bool { return false; }
    public function allowedRuntimeProfiles(): array { return $this->runtimeProfiles; }
}
