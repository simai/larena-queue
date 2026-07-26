<?php

declare(strict_types=1);

namespace Larena\Queue\Runtime;

use DateInterval;
use Larena\Queue\Contracts\QueueClock;
use Larena\Queue\Contracts\QueueWorker;
use Larena\Queue\Data\QueueExecutionContext;
use Larena\Queue\Data\QueueJobSnapshot;
use Larena\Queue\Exceptions\QueueExecutionCancelled;
use Larena\Queue\Exceptions\QueueExecutionTimedOut;
use Larena\Queue\Exceptions\QueueOperationFailed;
use Larena\Queue\Storage\DatabaseQueueStore;
use Throwable;

final readonly class DurableQueueWorker implements QueueWorker
{
    public function __construct(
        private JobTypeRegistry $registry,
        private DatabaseQueueStore $store,
        private QueueClock $clock,
    ) {
    }

    public function runNext(string $workerRef): ?QueueJobSnapshot
    {
        $lease = $this->store->claim($workerRef, $this->clock->now());
        if ($lease === null) {
            return null;
        }

        $deadline = $this->clock->now()->add(
            new DateInterval('PT'.$lease->job->timeoutSeconds.'S'),
        );
        $context = new QueueExecutionContext(
            jobId: $lease->job->id,
            correlationId: $lease->job->correlationId,
            attempt: $lease->job->attempts,
            lease: $lease,
            deadline: $deadline,
            clock: $this->clock,
            store: $this->store,
        );

        try {
            $context->checkpoint();
            $result = $this->registry->handler($lease->job->jobType)
                ->handle($context, $lease->job->payload);
            $context->checkpoint();
            if ($result->succeeded) {
                $this->store->complete($lease, $result, $this->clock->now());
            } else {
                $this->store->fail(
                    $lease,
                    $result->reasonCode ?? 'operation_failed',
                    $result->retryable,
                    false,
                    $result->metadata,
                    $this->clock->now(),
                );
            }
        } catch (QueueExecutionCancelled) {
            $this->store->cancelLease($lease, $this->clock->now());
        } catch (QueueExecutionTimedOut) {
            $this->store->fail(
                $lease,
                'execution_timed_out',
                true,
                true,
                [],
                $this->clock->now(),
            );
        } catch (QueueOperationFailed $exception) {
            $this->store->fail(
                $lease,
                $exception->reasonCode,
                false,
                false,
                [],
                $this->clock->now(),
            );
        } catch (Throwable) {
            $this->store->fail(
                $lease,
                'handler_exception',
                true,
                false,
                [],
                $this->clock->now(),
            );
        }

        return $this->store->require($lease->job->id);
    }
}
