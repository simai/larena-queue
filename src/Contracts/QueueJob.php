<?php

declare(strict_types=1);

namespace Larena\Queue\Contracts;

use Larena\Queue\Enums\JobStatus;
use Larena\Queue\Enums\QueuePriority;

interface QueueJob
{
    public function id(): string;

    public function descriptor(): JobDescriptor;

    public function status(): JobStatus;

    public function priority(): QueuePriority;

    public function correlationId(): string;
}
