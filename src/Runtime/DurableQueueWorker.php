<?php

declare(strict_types=1);

namespace Larena\Queue\Runtime;

use DateInterval;
use DateTimeImmutable;
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
        private QueueMetrics $metrics = new QueueMetrics(),
    ) {
    }

    public function runNext(string $workerRef): ?QueueJobSnapshot
    {
        $lease = $this->store->claim($workerRef, $this->clock->now());
        if ($lease === null) {
            return null;
        }
        $started = hrtime(true);
        $this->metrics->wait($lease->job->jobType, self::waitedMs($lease->job, $this->clock->now()));
        $outcome = 'error';

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
                $outcome = 'ok';
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
            $outcome = 'rejected';
            $this->store->cancelLease($lease, $this->clock->now());
        } catch (QueueExecutionTimedOut) {
            $outcome = 'timeout';
            $this->store->fail(
                $lease,
                'execution_timed_out',
                true,
                true,
                [],
                $this->clock->now(),
            );
        } catch (QueueOperationFailed $exception) {
            $outcome = $exception->reasonCode === 'job_type_not_registered' ? 'rejected' : 'error';
            $this->store->fail(
                $lease,
                $exception->reasonCode,
                false,
                false,
                [],
                $this->clock->now(),
            );
        } catch (Throwable) {
            $outcome = 'error';
            $this->store->fail(
                $lease,
                'handler_exception',
                true,
                false,
                [],
                $this->clock->now(),
            );
        } finally {
            $this->metrics->job($lease->job->jobType, $outcome, (hrtime(true) - $started) / 1_000_000);
        }

        return $this->store->require($lease->job->id);
    }

    /** From the moment the job became available (available_at, or created_at when later) until it was claimed. */
    private static function waitedMs(QueueJobSnapshot $job, DateTimeImmutable $now): float
    {
        $from = max($job->availableAt, $job->createdAt);

        return max(0.0, ((float) $now->format('U.u') - (float) $from->format('U.u')) * 1000);
    }
}
