<?php

declare(strict_types=1);

namespace Larena\Queue\Enums;

enum RuntimeProfile: string
{
    case InlineApproved = 'inline_approved';
    case LocalQueue = 'local_queue';
    case NativeWorker = 'native_worker';
    case Deferred = 'deferred';
    case EmergencyWebTick = 'emergency_web_tick';
    case Rejected = 'rejected';
}
