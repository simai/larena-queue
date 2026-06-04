<?php

declare(strict_types=1);

use Larena\Queue\Contracts\JobDescriptor;
use Larena\Queue\Enums\QueuePriority;
use Larena\Queue\Enums\RuntimeProfile;

require_once __DIR__ . '/../../vendor/autoload.php';

$contract = new ReflectionClass(JobDescriptor::class);

foreach ([
    'jobType',
    'operationRef',
    'handlerRef',
    'timeoutSeconds',
    'maxAttempts',
    'idempotencyKeyStrategy',
    'priority',
    'auditPolicy',
    'payloadSchemaRef',
    'allowsSecretReferences',
    'allowedRuntimeProfiles',
] as $method) {
    if (!$contract->hasMethod($method)) {
        fwrite(STDERR, "JobDescriptor is missing {$method}().\n");
        exit(1);
    }
}

if (RuntimeProfile::SignedWebTick->isEmergencyOnly() !== true) {
    fwrite(STDERR, "Signed web tick must be represented as emergency-only.\n");
    exit(1);
}

$expectedCriticalPriority = getenv('LARENA_EXPECTED_CRITICAL_PRIORITY') ?: 'critical';
if (QueuePriority::Critical->value !== $expectedCriticalPriority) {
    fwrite(STDERR, "Critical priority class must remain stable.\n");
    exit(1);
}

echo "JobDescriptorContractTest passed.\n";
