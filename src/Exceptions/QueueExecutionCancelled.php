<?php

declare(strict_types=1);

namespace Larena\Queue\Exceptions;

final class QueueExecutionCancelled extends QueueOperationFailed
{
    public function __construct()
    {
        parent::__construct('cancel_requested');
    }
}
