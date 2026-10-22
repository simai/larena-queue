<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Larena\Monitoring\Contracts\Metrics;
use Larena\Queue\Contracts\DeferrableJobDescriptor;
use Larena\Queue\Contracts\QueueJobHandler;
use Larena\Queue\Data\DispatchRequest;
use Larena\Queue\Data\QueueExecutionContext;
use Larena\Queue\Data\QueueJobResult;
use Larena\Queue\Enums\JobStatus;
use Larena\Queue\Enums\QueuePriority;
use Larena\Queue\Runtime\DurableQueueDispatcher;
use Larena\Queue\Runtime\DurableQueueWorker;
use Larena\Queue\Runtime\ImmutableJobDescriptor;
use Larena\Queue\Runtime\JobTypeRegistry;
use Larena\Queue\Runtime\QueueControlService;
use Larena\Queue\Runtime\QueueMetrics;
use Larena\Queue\Storage\DatabaseQueueStore;
use Larena\Queue\Tests\Support\FrozenQueueClock;

require_once dirname(__DIR__, 2).'/vendor/autoload.php';
require_once dirname(__DIR__).'/Support/MonitoringStubs.php';

function queue_deferral_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

// «Not yet» is a result of its own: clamped to 1 s … 1 hour, retryable, with its reason code.
$later = QueueJobResult::retryLater(60, 'tests_not_ready', ['waiting' => 'list']);
queue_deferral_assert($later->isDeferral() && $later->deferSeconds === 60 && !$later->succeeded && $later->retryable
    && $later->reasonCode === 'tests_not_ready' && $later->metadata === ['waiting' => 'list'], 'retryLater keeps its delay and reason.');
queue_deferral_assert(QueueJobResult::retryLater(99999, 'tests_not_ready')->deferSeconds === QueueJobResult::MAX_DEFERRAL_SECONDS
    && QueueJobResult::retryLater(0, 'tests_not_ready')->deferSeconds === 1, 'The delay is bounded.');
queue_deferral_assert(!QueueJobResult::failure('tests_failed', true)->isDeferral() && !QueueJobResult::success()->isDeferral(),
    'A failure and a success are not deferrals.');
try {
    QueueJobResult::retryLater(5, 'Not A Code');
    throw new LogicException('invalid reason accepted');
} catch (InvalidArgumentException) {
}

$descriptor = static fn (string $type, int $attempts = 1, ?int $maxDeferrals = null): ImmutableJobDescriptor => new ImmutableJobDescriptor(
    jobType: $type,
    operationRef: $type,
    handlerRef: 'test:'.$type,
    timeoutSeconds: 60,
    maxAttempts: $attempts,
    retryDelaySeconds: 5,
    leaseSeconds: 10,
    priority: QueuePriority::Normal,
    auditPolicy: 'consumer_owned',
    payloadSchemaRef: 'schema://tests/'.$type,
    maxDeferrals: $maxDeferrals ?? DeferrableJobDescriptor::DEFAULT_MAX_DEFERRALS,
);
queue_deferral_assert($descriptor('tests.default')->maxDeferrals() === 288, 'The default deferral limit is about a day at five minutes.');
try {
    $descriptor('tests.negative', 1, -1);
    throw new LogicException('negative deferral limit accepted');
} catch (InvalidArgumentException) {
}

$recorded = new class implements Metrics {
    /** @var list<string> */
    public array $jobs = [];

    public function timing(string $id, float $ms, array $tags = []): void
    {
        if ($id === 'queue.job') {
            ksort($tags);
            $this->jobs[] = http_build_query($tags);
        }
    }
};

$databasePath = sys_get_temp_dir().'/larena-queue-deferral-'.bin2hex(random_bytes(8)).'.sqlite';
touch($databasePath);
$capsule = new Capsule();
$capsule->addConnection(['driver' => 'sqlite', 'database' => $databasePath, 'foreign_key_constraints' => true]);
$container = new Application(dirname(__DIR__, 2));
$container->instance('db', $capsule->getDatabaseManager());
$container->instance('db.schema', $capsule->getConnection()->getSchemaBuilder());
Facade::setFacadeApplication($container);
$migrations = [
    require dirname(__DIR__, 2).'/database/migrations/2026_07_26_000000_create_larena_queue_tables.php',
    require dirname(__DIR__, 2).'/database/migrations/2026_10_22_000000_add_larena_queue_job_deferrals.php',
];
foreach ($migrations as $migration) {
    $migration->up();
}

$connection = $capsule->getConnection();
$clock = new FrozenQueueClock(new DateTimeImmutable('2026-10-22T00:00:00Z'));
$store = new DatabaseQueueStore($connection);
$registry = new JobTypeRegistry();
$dispatcher = new DurableQueueDispatcher($registry, $store, $clock);
$worker = new DurableQueueWorker($registry, $store, $clock, new QueueMetrics(static fn (): object => $recorded));
$control = new QueueControlService($store, $clock);
$handler = static fn (callable $callback): QueueJobHandler => new class($callback) implements QueueJobHandler {
    /** @var callable(QueueExecutionContext): QueueJobResult */
    private $callback;

    public function __construct(callable $callback)
    {
        $this->callback = $callback;
    }

    public function handle(QueueExecutionContext $context, array $payload): QueueJobResult
    {
        return ($this->callback)($context);
    }
};
$attemptRows = static fn (string $jobId): int => $connection->table('larena_queue_attempts')->where('job_id', $jobId)->count();

try {
    // ── A job that waits: three «not yet», then done. Its one attempt is never spent on waiting. ──
    $list = new ArrayObject(['ready' => false]);
    $registry->register($descriptor('tests.waits'), $handler(static function () use ($list): QueueJobResult {
        return $list['ready'] === true
            ? QueueJobResult::success(['rows' => 3])
            : QueueJobResult::retryLater(60, 'tests_list_not_ready', ['waiting' => 'list']);
    }));
    $waiting = $dispatcher->dispatch(new DispatchRequest('tests.waits', [], 'waits-1', 'corr-waits'))->jobId;
    for ($round = 1; $round <= 3; $round++) {
        $deferred = $worker->runNext('worker-a');
        queue_deferral_assert($deferred?->status === JobStatus::Deferred && $deferred->failureReason === 'tests_list_not_ready'
            && $deferred->result === ['waiting' => 'list'], "Round {$round}: the job waits with its reason.");
        queue_deferral_assert($deferred->attempts === 0 && $deferred->deferrals === $round && $attemptRows($waiting) === 0,
            "Round {$round}: no attempt is spent, the deferral is counted: ".json_encode([$deferred->attempts, $deferred->deferrals]));
        queue_deferral_assert($deferred->availableAt == $clock->now()->add(new DateInterval('PT60S')), "Round {$round}: it runs again after the delay.");
        queue_deferral_assert(!$deferred->isTerminal(), 'A deferred job is not finished.');
        $clock->advanceSeconds(59);
        queue_deferral_assert($worker->runNext('worker-a') === null, "Round {$round}: not before the delay.");
        $clock->advanceSeconds(1);
    }
    $list['ready'] = true;
    $done = $worker->runNext('worker-a');
    queue_deferral_assert($done?->status === JobStatus::Completed && $done->attempts === 1 && $done->maxAttempts === 1
        && $done->deferrals === 3 && $done->result === ['rows' => 3] && $done->failureReason === null, 'After the waits the one attempt completes it.');
    queue_deferral_assert($attemptRows($waiting) === 1, 'Only the run that did the work is an attempt.');

    // A plain retryable failure still spends its attempt and waits the descriptor's retry delay.
    $registry->register($descriptor('tests.fails', 2), $handler(static fn (): QueueJobResult => QueueJobResult::failure('tests_remote_busy', true)));
    $dispatcher->dispatch(new DispatchRequest('tests.fails', [], 'fails-1', 'corr-fails'));
    $failed = $worker->runNext('worker-a');
    queue_deferral_assert($failed?->status === JobStatus::Retrying && $failed->attempts === 1 && $failed->deferrals === 0
        && $failed->availableAt == $clock->now()->add(new DateInterval('PT5S')), 'A failure keeps its retry semantics.');
    $clock->advanceSeconds(5);
    queue_deferral_assert($worker->runNext('worker-a')?->status === JobStatus::Failed, 'And ends once its attempts are spent.');

    // ── The deferral limit: past it the job fails cleanly; a retry by hand starts the count afresh. ──
    $registry->register($descriptor('tests.limited', 1, 2), $handler(static fn (): QueueJobResult => QueueJobResult::retryLater(10, 'tests_list_not_ready')));
    $limited = $dispatcher->dispatch(new DispatchRequest('tests.limited', [], 'limited-1', 'corr-limited'))->jobId;
    foreach ([1, 2] as $round) {
        queue_deferral_assert($worker->runNext('worker-b')?->deferrals === $round, "Limited round {$round} waits.");
        $clock->advanceSeconds(10);
    }
    $limit = $worker->runNext('worker-b');
    queue_deferral_assert($limit?->status === JobStatus::Failed && $limit->failureReason === 'job_deferral_limit_reached'
        && $limit->isTerminal() && $limit->deferrals === 2 && $limit->attempts === 1, 'Past the limit the job fails: '.json_encode([$limit?->status, $limit?->failureReason]));
    queue_deferral_assert($connection->table('larena_queue_attempts')->where('job_id', $limited)->value('failure_reason') === 'job_deferral_limit_reached',
        'The last attempt records why.');
    $retried = $control->retry($limited);
    queue_deferral_assert($retried->status === JobStatus::Queued && $retried->deferrals === 0 && $retried->attempts === 0, 'A retry starts the deferrals afresh.');
    queue_deferral_assert($worker->runNext('worker-b')?->status === JobStatus::Deferred, 'And the job may wait again.');

    // ── Cancel while deferred: the job ends at once and is never claimed again. ──
    $registry->register($descriptor('tests.cancelled'), $handler(static fn (): QueueJobResult => QueueJobResult::retryLater(30, 'tests_list_not_ready')));
    $cancelled = $dispatcher->dispatch(new DispatchRequest('tests.cancelled', [], 'cancelled-1', 'corr-cancelled'))->jobId;
    queue_deferral_assert($worker->runNext('worker-c')?->status === JobStatus::Deferred, 'The job waits.');
    $stopped = $control->cancel($cancelled);
    queue_deferral_assert($stopped->status === JobStatus::Cancelled && $stopped->isTerminal() && $stopped->failureReason === 'cancel_requested'
        && $stopped->cancelRequestedAt !== null, 'A deferred job is cancelled at once.');
    $clock->advanceSeconds(3600);
    $next = $worker->runNext('worker-c');
    queue_deferral_assert($next === null || $next->id !== $cancelled, 'A cancelled job is not run.');
    queue_deferral_assert($control->status($cancelled)->status === JobStatus::Cancelled, 'It stays cancelled.');
    // A deferral the handler returns after a cancel was asked for is a cancel, not a wait.
    $cancelDuringRun = null;
    $registry->register($descriptor('tests.cancel_running'), $handler(static function () use (&$cancelDuringRun, $control): QueueJobResult {
        $control->cancel((string) $cancelDuringRun);

        return QueueJobResult::retryLater(30, 'tests_list_not_ready');
    }));
    $cancelDuringRun = $dispatcher->dispatch(new DispatchRequest('tests.cancel_running', [], 'cancel-running-1', 'corr-cancel-running'))->jobId;
    queue_deferral_assert($worker->runNext('worker-c')?->status === JobStatus::Cancelled, 'A deferral after a cancel request ends cancelled.');

    // ── Metrics: a deferral is outcome skipped with reason deferred (monitoring's outcome codes); the limit is an error. ──
    $lines = $recorded->jobs;
    queue_deferral_assert(array_slice($lines, 0, 4) === [
        'job_type=tests.waits&outcome=skipped&reason=deferred',
        'job_type=tests.waits&outcome=skipped&reason=deferred',
        'job_type=tests.waits&outcome=skipped&reason=deferred',
        'job_type=tests.waits&outcome=ok',
    ], 'Deferred runs are tagged: '.implode(', ', $lines));
    queue_deferral_assert(in_array('job_type=tests.limited&outcome=error', $lines, true), 'The limit is recorded as an error.');
    foreach ($lines as $line) {
        parse_str($line, $tags);
        queue_deferral_assert(in_array($tags['outcome'] ?? null, ['ok', 'error', 'rejected', 'timeout', 'skipped'], true)
            && preg_match('/^[a-z][a-z0-9_.-]{0,59}$/', (string) ($tags['reason'] ?? 'ok')) === 1, 'Every tag value is a monitoring code: '.$line);
    }

    // The deferral migration is reversible.
    $migrations[1]->down();
    queue_deferral_assert(!$connection->getSchemaBuilder()->hasColumn('larena_queue_jobs', 'deferral_count'), 'down() drops the column.');
    queue_deferral_assert($control->status($waiting)->deferrals === 0, 'A job reads without the column.');
    $migrations[1]->up();
    queue_deferral_assert($connection->getSchemaBuilder()->hasColumn('larena_queue_jobs', 'deferral_count'), 'up() adds it again.');
} finally {
    foreach (array_reverse($migrations) as $migration) {
        try {
            $migration->down();
        } catch (Throwable) {
        }
    }
    Facade::clearResolvedInstances();
    @unlink($databasePath);
}

echo "QueueDeferralTest passed.\n";
