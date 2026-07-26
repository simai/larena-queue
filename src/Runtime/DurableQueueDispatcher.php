<?php

declare(strict_types=1);

namespace Larena\Queue\Runtime;

use Larena\Queue\Contracts\QueueClock;
use Larena\Queue\Data\DispatchRequest;
use Larena\Queue\Data\DispatchResult;
use Larena\Queue\Storage\DatabaseQueueStore;

final readonly class DurableQueueDispatcher
{
    public function __construct(
        private JobTypeRegistry $registry,
        private DatabaseQueueStore $store,
        private QueueClock $clock,
    ) {
    }

    public function dispatch(DispatchRequest $request): DispatchResult
    {
        return $this->store->dispatch(
            $this->registry->descriptor($request->jobType),
            $request,
            $this->clock->now(),
        );
    }
}
