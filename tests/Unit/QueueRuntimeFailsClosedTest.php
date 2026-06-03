<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Enums/JobStatus.php';
require_once __DIR__ . '/../../src/Enums/QueuePriority.php';
require_once __DIR__ . '/../../src/Enums/RuntimeProfile.php';
require_once __DIR__ . '/../../src/Contracts/JobDescriptor.php';
require_once __DIR__ . '/../../src/Contracts/QueueJob.php';
require_once __DIR__ . '/../../src/Contracts/QueueDecision.php';
require_once __DIR__ . '/../../src/Contracts/QueueRuntime.php';

use Larena\Queue\Contracts\JobDescriptor;
use Larena\Queue\Contracts\QueueDecision;
use Larena\Queue\Contracts\QueueJob;
use Larena\Queue\Contracts\QueueRuntime;
use Larena\Queue\Enums\JobStatus;
use Larena\Queue\Enums\QueuePriority;
use Larena\Queue\Enums\RuntimeProfile;

function requireTrue(bool $actual, string $message): void
{
    if ($actual !== true) {
        throw new RuntimeException($message);
    }
}

function requireSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message);
    }
}

$statusFailedClosed = false;

try {
    JobStatus::from('lost');
} catch (ValueError) {
    $statusFailedClosed = true;
}

requireTrue($statusFailedClosed, 'unknown job status must fail closed');

$profileFailedClosed = false;

try {
    RuntimeProfile::from('run_anyway');
} catch (ValueError) {
    $profileFailedClosed = true;
}

requireTrue($profileFailedClosed, 'unknown runtime profile must fail closed');

$descriptor = new class implements JobDescriptor {
    public function name(): string
    {
        return 'storage.export';
    }

    public function sourcePackage(): string
    {
        return 'larena/storage';
    }

    public function timeoutSeconds(): int
    {
        return 60;
    }

    public function maxAttempts(): int
    {
        return 2;
    }

    public function idempotencyStrategy(): string
    {
        return 'explicit-key';
    }

    public function priority(): QueuePriority
    {
        return QueuePriority::Low;
    }

    public function auditCategory(): string
    {
        return 'queue.job';
    }

    public function payloadSchema(): array
    {
        return ['export_id' => 'string'];
    }
};

$job = new class implements QueueJob {
    public function jobId(): string
    {
        return 'job-1';
    }

    public function descriptorName(): string
    {
        return 'storage.export';
    }

    public function status(): JobStatus
    {
        return JobStatus::Declared;
    }

    public function idempotencyKey(): string
    {
        return 'export-1';
    }

    public function payload(): array
    {
        return ['export_id' => 'export-1'];
    }
};

$runtime = new class implements QueueRuntime {
    public function decide(JobDescriptor $descriptor, QueueJob $job): QueueDecision
    {
        return new class implements QueueDecision {
            public function allowed(): bool
            {
                return false;
            }

            public function profile(): RuntimeProfile
            {
                return RuntimeProfile::Rejected;
            }

            public function reason(): string
            {
                return 'runtime profile not selected';
            }

            public function requiresAudit(): bool
            {
                return true;
            }
        };
    }
};

$decision = $runtime->decide($descriptor, $job);

requireSame(false, $decision->allowed(), 'runtime must reject unsupported execution');
requireSame(RuntimeProfile::Rejected, $decision->profile(), 'rejected profile is required');
requireSame('runtime profile not selected', $decision->reason(), 'explainable decision reason is required');
requireSame(true, $decision->requiresAudit(), 'rejected decision must require audit');

echo "Queue runtime fail-closed test passed.\n";
