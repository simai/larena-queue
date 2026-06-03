<?php

declare(strict_types=1);

namespace Larena\Queue\Contracts;

use Larena\Queue\Enums\RuntimeProfile;

interface QueueDecision
{
    public function allowed(): bool;

    public function profile(): RuntimeProfile;

    public function reason(): string;

    public function requiresAudit(): bool;
}
