<?php

declare(strict_types=1);

namespace Larena\Queue\Enums;

enum QueueDecisionStatus: string
{
    case Accepted = 'accepted';
    case Deferred = 'deferred';
    case Rejected = 'rejected';
    case MissingDescriptor = 'missing_descriptor';
    case UnsafeRuntimeProfile = 'unsafe_runtime_profile';
    case BlockedByPolicy = 'blocked_by_policy';

    public function permitsDispatch(): bool
    {
        return $this === self::Accepted;
    }
}
