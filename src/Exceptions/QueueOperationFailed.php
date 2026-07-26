<?php

declare(strict_types=1);

namespace Larena\Queue\Exceptions;

use RuntimeException;

class QueueOperationFailed extends RuntimeException
{
    public function __construct(public readonly string $reasonCode)
    {
        parent::__construct($reasonCode);
    }
}
