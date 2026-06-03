<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Enums/QueuePriority.php';
require_once __DIR__ . '/../../src/Contracts/JobDescriptor.php';

use Larena\Queue\Contracts\JobDescriptor;
use Larena\Queue\Enums\QueuePriority;

function requireSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message);
    }
}

$descriptor = new class implements JobDescriptor {
    public function name(): string
    {
        return 'visibility.reindex';
    }

    public function sourcePackage(): string
    {
        return 'larena/visibility';
    }

    public function timeoutSeconds(): int
    {
        return 120;
    }

    public function maxAttempts(): int
    {
        return 3;
    }

    public function idempotencyStrategy(): string
    {
        return 'scope-and-content-hash';
    }

    public function priority(): QueuePriority
    {
        return QueuePriority::Normal;
    }

    public function auditCategory(): string
    {
        return 'queue.job';
    }

    public function payloadSchema(): array
    {
        return [
            'site_id' => 'string',
            'content_hash' => 'string',
        ];
    }
};

requireSame('visibility.reindex', $descriptor->name(), 'descriptor name is required');
requireSame('larena/visibility', $descriptor->sourcePackage(), 'source package is required');
requireSame(120, $descriptor->timeoutSeconds(), 'timeout is required');
requireSame(3, $descriptor->maxAttempts(), 'retry policy is required');
requireSame('scope-and-content-hash', $descriptor->idempotencyStrategy(), 'idempotency strategy is required');
requireSame(QueuePriority::Normal, $descriptor->priority(), 'priority class is required');
requireSame('queue.job', $descriptor->auditCategory(), 'audit category is required');
requireSame([
    'site_id' => 'string',
    'content_hash' => 'string',
], $descriptor->payloadSchema(), 'payload schema is required');

echo "Job descriptor contract test passed.\n";
