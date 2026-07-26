<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Larena\Queue\Contracts\QueueJobHandler;
use Larena\Queue\Data\DispatchRequest;
use Larena\Queue\Data\QueueExecutionContext;
use Larena\Queue\Data\QueueJobResult;
use Larena\Queue\Enums\JobStatus;
use Larena\Queue\Enums\QueuePriority;
use Larena\Queue\Exceptions\QueueOperationFailed;
use Larena\Queue\Runtime\DurableQueueDispatcher;
use Larena\Queue\Runtime\DurableQueueWorker;
use Larena\Queue\Runtime\ImmutableJobDescriptor;
use Larena\Queue\Runtime\JobTypeRegistry;
use Larena\Queue\Runtime\QueueControlService;
use Larena\Queue\Storage\DatabaseQueueStore;
use Larena\Queue\Tests\Support\FrozenQueueClock;

require_once dirname(__DIR__, 2).'/vendor/autoload.php';

function durable_queue_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function durable_queue_throws(callable $operation, string $reason): void
{
    try {
        $operation();
    } catch (QueueOperationFailed $exception) {
        durable_queue_assert(
            $exception->reasonCode === $reason,
            "Expected {$reason}, got {$exception->reasonCode}.",
        );

        return;
    }

    throw new RuntimeException("Expected Queue failure {$reason}.");
}

function durable_queue_descriptor(
    string $type,
    QueuePriority $priority = QueuePriority::Normal,
    int $attempts = 3,
    int $timeout = 60,
    int $lease = 10,
): ImmutableJobDescriptor {
    return new ImmutableJobDescriptor(
        jobType: $type,
        operationRef: $type,
        handlerRef: 'test:'.$type,
        timeoutSeconds: $timeout,
        maxAttempts: $attempts,
        retryDelaySeconds: 0,
        leaseSeconds: $lease,
        priority: $priority,
        auditPolicy: 'consumer_owned',
        payloadSchemaRef: 'schema://tests/'.$type,
    );
}

function durable_queue_handler(callable $callback): QueueJobHandler
{
    return new class($callback) implements QueueJobHandler {
        public function __construct(private $callback)
        {
        }

        public function handle(QueueExecutionContext $context, array $payload): QueueJobResult
        {
            return ($this->callback)($context, $payload);
        }
    };
}

$databasePath = sys_get_temp_dir().'/larena-queue-'.bin2hex(random_bytes(8)).'.sqlite';
touch($databasePath);
$capsule = new Capsule();
$capsule->addConnection([
    'driver' => 'sqlite',
    'database' => $databasePath,
    'foreign_key_constraints' => true,
]);
$capsule->setAsGlobal();
$capsule->bootEloquent();
$container = new Application(dirname(__DIR__, 2));
$container->instance('db', $capsule->getDatabaseManager());
$container->instance('db.schema', $capsule->getConnection()->getSchemaBuilder());
Facade::setFacadeApplication($container);
$migration = require dirname(__DIR__, 2).'/database/migrations/2026_07_26_000000_create_larena_queue_tables.php';
$migration->up();

$clock = new FrozenQueueClock(new DateTimeImmutable('2026-07-26T00:00:00Z'));
$store = new DatabaseQueueStore($capsule->getConnection());
$registry = new JobTypeRegistry();
$dispatcher = new DurableQueueDispatcher($registry, $store, $clock);
$worker = new DurableQueueWorker($registry, $store, $clock);
$control = new QueueControlService($store, $clock);
$executed = [];

try {
    $registry->register(
        durable_queue_descriptor('tests.success'),
        durable_queue_handler(static function (QueueExecutionContext $context, array $payload) use (&$executed): QueueJobResult {
            $context->heartbeat();
            $executed[] = $payload['label'];

            return QueueJobResult::success(['artifact_count' => 1]);
        }),
    );
    $first = $dispatcher->dispatch(new DispatchRequest(
        'tests.success',
        ['label' => 'first', 'nested' => ['b' => 2, 'a' => 1]],
        'success-1',
        'corr-success-1',
    ));
    $duplicate = $dispatcher->dispatch(new DispatchRequest(
        'tests.success',
        ['nested' => ['a' => 1, 'b' => 2], 'label' => 'first'],
        'success-1',
        'corr-success-1',
    ));
    durable_queue_assert(!$first->duplicate && $duplicate->duplicate, 'Duplicate dispatch must reuse the first job.');
    durable_queue_assert($first->jobId === $duplicate->jobId, 'Duplicate dispatch must retain job identity.');
    durable_queue_throws(
        fn () => $dispatcher->dispatch(new DispatchRequest(
            'tests.success',
            ['label' => 'changed'],
            'success-1',
            'corr-success-1',
        )),
        'idempotency_conflict',
    );
    $completed = $worker->runNext('worker-success');
    durable_queue_assert($completed?->status === JobStatus::Completed, 'Successful job must complete.');
    durable_queue_assert($completed->result === ['artifact_count' => 1], 'Sanitized result metadata must persist.');
    durable_queue_assert($executed === ['first'], 'Duplicate dispatch must execute once.');

    $reopenedStore = new DatabaseQueueStore($capsule->getConnection());
    durable_queue_assert(
        $reopenedStore->require($first->jobId)->status === JobStatus::Completed,
        'Job state must survive a new store instance.',
    );

    $registry->register(
        durable_queue_descriptor('tests.low', QueuePriority::Low),
        durable_queue_handler(static fn (): QueueJobResult => QueueJobResult::success()),
    );
    $registry->register(
        durable_queue_descriptor('tests.high', QueuePriority::High),
        durable_queue_handler(static fn (): QueueJobResult => QueueJobResult::success()),
    );
    $low = $dispatcher->dispatch(new DispatchRequest('tests.low', [], 'low-1', 'corr-low'));
    $high = $dispatcher->dispatch(new DispatchRequest('tests.high', [], 'high-1', 'corr-high'));
    $priorityLease = $store->claim('priority-worker', $clock->now());
    durable_queue_assert($priorityLease?->job->id === $high->jobId, 'Higher priority job must be claimed first.');
    $store->complete($priorityLease, QueueJobResult::success(), $clock->now());
    $control->cancel($low->jobId);

    $registry->register(
        durable_queue_descriptor('tests.crash', attempts: 2, lease: 5),
        durable_queue_handler(static fn (): QueueJobResult => QueueJobResult::success(['reclaimed' => true])),
    );
    $crash = $dispatcher->dispatch(new DispatchRequest('tests.crash', [], 'crash-1', 'corr-crash'));
    $crashedLease = $store->claim('crashed-worker', $clock->now());
    durable_queue_assert($crashedLease?->job->id === $crash->jobId, 'Crash fixture must be leased.');
    $clock->advanceSeconds(6);
    durable_queue_assert($control->reclaimExpired() === 1, 'Expired crash lease must be reclaimed.');
    durable_queue_assert($control->status($crash->jobId)->status === JobStatus::Retrying, 'Reclaimed job must retry.');
    $reclaimed = $worker->runNext('replacement-worker');
    durable_queue_assert(
        $reclaimed?->status === JobStatus::Completed && $reclaimed->attempts === 2,
        'Replacement worker must complete the second attempt.',
    );

    $registry->register(
        durable_queue_descriptor('tests.exhaust', attempts: 2),
        durable_queue_handler(static function (): QueueJobResult {
            throw new RuntimeException('raw exception must not persist');
        }),
    );
    $exhaust = $dispatcher->dispatch(new DispatchRequest('tests.exhaust', [], 'exhaust-1', 'corr-exhaust'));
    durable_queue_assert($worker->runNext('retry-worker-1')?->status === JobStatus::Retrying, 'First exception must retry.');
    $exhausted = $worker->runNext('retry-worker-2');
    durable_queue_assert($exhausted?->status === JobStatus::Failed, 'Retry exhaustion must fail.');
    durable_queue_assert($exhausted->failureReason === 'handler_exception', 'Raw exception must be sanitized.');

    $registry->register(
        durable_queue_descriptor('tests.timeout', attempts: 1, timeout: 2, lease: 2),
        durable_queue_handler(static function () use ($clock): QueueJobResult {
            $clock->advanceSeconds(3);

            return QueueJobResult::success();
        }),
    );
    $timeout = $dispatcher->dispatch(new DispatchRequest('tests.timeout', [], 'timeout-1', 'corr-timeout'));
    $timedOut = $worker->runNext('timeout-worker');
    durable_queue_assert(
        $timedOut?->id === $timeout->jobId && $timedOut->status === JobStatus::TimedOut,
        'Exhausted timeout must be terminal and explicit.',
    );

    $cancellationJobId = null;
    $registry->register(
        durable_queue_descriptor('tests.cancel-running'),
        durable_queue_handler(static function (QueueExecutionContext $context) use (&$cancellationJobId, $control): QueueJobResult {
            $control->cancel((string) $cancellationJobId);
            $context->checkpoint();

            return QueueJobResult::success();
        }),
    );
    $cancellation = $dispatcher->dispatch(new DispatchRequest(
        'tests.cancel-running',
        [],
        'cancel-running-1',
        'corr-cancel-running',
    ));
    $cancellationJobId = $cancellation->jobId;
    durable_queue_assert(
        $worker->runNext('cancel-worker')?->status === JobStatus::Cancelled,
        'Running cancellation must be observed at a checkpoint.',
    );
    durable_queue_assert(
        $control->retry($cancellation->jobId)->status === JobStatus::Queued,
        'Terminal cancellation may be explicitly retried.',
    );
    $control->cancel($cancellation->jobId);
    $control->delete($cancellation->jobId);
    durable_queue_throws(fn () => $control->status($cancellation->jobId), 'job_not_found');

    $migration->down();
    durable_queue_assert(
        !$capsule->getConnection()->getSchemaBuilder()->hasTable('larena_queue_jobs'),
        'Migration rollback must remove Queue tables.',
    );
    $migration->up();
    durable_queue_assert(
        $capsule->getConnection()->getSchemaBuilder()->hasTable('larena_queue_jobs'),
        'Migration reapply must recreate Queue tables.',
    );
} finally {
    try {
        $migration->down();
    } catch (Throwable) {
    }
    Facade::clearResolvedInstances();
    @unlink($databasePath);
}

echo "DurableQueueLifecycleTest passed.\n";
