<?php

declare(strict_types=1);

namespace Larena\Queue\Contracts;

use Larena\Queue\Data\QueueExecutionContext;
use Larena\Queue\Data\QueueJobResult;

interface QueueJobHandler
{
    /**
     * @param array<string, mixed> $payload
     */
    public function handle(QueueExecutionContext $context, array $payload): QueueJobResult;
}
