<?php

declare(strict_types=1);

namespace Larena\Queue\Runtime;

use Larena\Queue\Contracts\JobDescriptor;
use Larena\Queue\Contracts\QueueJobHandler;
use Larena\Queue\Exceptions\QueueOperationFailed;

final class JobTypeRegistry
{
    /** @var array<string, array{descriptor: JobDescriptor, handler: QueueJobHandler}> */
    private array $entries = [];

    public function register(JobDescriptor $descriptor, QueueJobHandler $handler): void
    {
        $jobType = $descriptor->jobType();
        if (isset($this->entries[$jobType])) {
            throw new QueueOperationFailed('job_type_already_registered');
        }
        $this->entries[$jobType] = ['descriptor' => $descriptor, 'handler' => $handler];
    }

    public function descriptor(string $jobType): JobDescriptor
    {
        return $this->entries[$jobType]['descriptor']
            ?? throw new QueueOperationFailed('job_type_not_registered');
    }

    public function handler(string $jobType): QueueJobHandler
    {
        return $this->entries[$jobType]['handler']
            ?? throw new QueueOperationFailed('job_type_not_registered');
    }

    public function has(string $jobType): bool
    {
        return isset($this->entries[$jobType]);
    }
}
