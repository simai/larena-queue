<?php

declare(strict_types=1);

namespace Larena\Queue\Exceptions;

final class QueueExecutionTimedOut extends QueueOperationFailed
{
    public function __construct()
    {
        parent::__construct('execution_timed_out');
    }
}
