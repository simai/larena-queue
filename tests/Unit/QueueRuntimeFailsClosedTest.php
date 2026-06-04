<?php

declare(strict_types=1);

use Larena\Queue\Enums\JobStatus;
use Larena\Queue\Enums\QueueDecisionStatus;

require_once __DIR__ . '/../../vendor/autoload.php';

if (QueueDecisionStatus::MissingDescriptor->permitsDispatch()) {
    fwrite(STDERR, "Missing descriptor must fail closed.\n");
    exit(1);
}

if (QueueDecisionStatus::UnsafeRuntimeProfile->permitsDispatch()) {
    fwrite(STDERR, "Unsafe runtime profile must fail closed.\n");
    exit(1);
}

if (!JobStatus::Completed->isTerminal()) {
    fwrite(STDERR, "Completed job status must be terminal.\n");
    exit(1);
}

if (JobStatus::Running->isTerminal()) {
    fwrite(STDERR, "Running job status must not be terminal.\n");
    exit(1);
}

echo "QueueRuntimeFailsClosedTest passed.\n";
