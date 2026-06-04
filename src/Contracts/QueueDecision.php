<?php

declare(strict_types=1);

namespace Larena\Queue\Contracts;

use Larena\Queue\Enums\QueueDecisionStatus;

interface QueueDecision
{
    public function status(): QueueDecisionStatus;

    public function jobType(): string;

    public function reasonCode(): string;

    public function isDispatchAllowed(): bool;
}
