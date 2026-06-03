<?php

declare(strict_types=1);

namespace Larena\Queue\Contracts;

interface QueueRuntime
{
    public function decide(JobDescriptor $descriptor, QueueJob $job): QueueDecision;
}
