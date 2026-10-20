<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Larena\Monitoring\Contracts\Metrics;
use Larena\Monitoring\Runtime\MetricCatalog;
use Larena\Queue\Contracts\QueueJobHandler;
use Larena\Queue\Data\DispatchRequest;
use Larena\Queue\Data\QueueExecutionContext;
use Larena\Queue\Data\QueueJobResult;
use Larena\Queue\Enums\QueuePriority;
use Larena\Queue\Providers\QueueServiceProvider;
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

function queue_metrics_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$recorded = new class implements Metrics {
    /** @var list<array{string, float, array<string, string>}> */
    public array $records = [];

    public function timing(string $id, float $ms, array $tags = []): void
    {
        $this->records[] = [$id, $ms, $tags];
    }

    public function reset(): void
    {
        $this->records = [];
    }

    /** @return list<string> */
    public function lines(string $id): array
    {
        $lines = [];
        foreach ($this->records as [$recordId, , $tags]) {
            if ($recordId === $id) {
                ksort($tags);
                $lines[] = http_build_query($tags);
            }
        }

        return $lines;
    }
};

// Job type codes follow the monitoring tag value rule.
foreach ([
    'backup.create_complete' => 'backup.create_complete',
    'Storage Export:CSV' => 'storage_export_csv',
    '9.reindex' => 'unnamed',
    'tests.job-1234567' => 'unnamed',
    'отчёт' => 'unnamed',
    str_repeat('b', 70) => str_repeat('b', 60),
] as $type => $code) {
    queue_metrics_assert(QueueMetrics::code($type) === $code, "Job type {$type} must become {$code}.");
}

// Without monitoring (nothing bound) and with a failing implementation, recording is a silent no-op.
$silent = QueueMetrics::fromContainer(new Container());
$silent->job('tests.any', 'ok', 1.0);
$silent->wait('tests.any', 1.0);
$failing = new QueueMetrics(static fn (): object => new class implements Metrics {
    public function timing(string $id, float $ms, array $tags = []): void
    {
        throw new RuntimeException('monitoring is down');
    }
});
$failing->job('tests.any', 'ok', 1.0);
$throwingResolver = new QueueMetrics(static function (): never {
    throw new RuntimeException('container is broken');
});
$throwingResolver->wait('tests.any', 1.0);

// The provider resolves the contract by name and declares queue's monitoring.yaml in the catalog.
$app = new Application(dirname(__DIR__, 2));
$app->instance('config', new Repository());
$app->instance(Metrics::class, $recorded);
$app->register(QueueServiceProvider::class);
$catalog = $app->make(MetricCatalog::class);
queue_metrics_assert(
    ($catalog->sources[QueueMetrics::PACKAGE] ?? null) === QueueMetrics::catalogPath() && is_file(QueueMetrics::catalogPath()),
    'Queue must register its monitoring.yaml in the metric catalog.',
);
$catalogYaml = (string) file_get_contents(QueueMetrics::catalogPath());
queue_metrics_assert(
    str_contains($catalogYaml, "  queue.job:\n") && str_contains($catalogYaml, "  queue.wait:\n"),
    'queue.job and queue.wait must be declared.',
);
$app->make(QueueMetrics::class)->job('tests.provider', 'ok', 2.5);
queue_metrics_assert(
    $recorded->lines('queue.job') === ['job_type=tests.provider&outcome=ok'],
    'The container-resolved recorder must reach the bound Metrics contract.',
);
$recorded->reset();

// The worker records queue.wait and queue.job with the outcome of every run.
$databasePath = sys_get_temp_dir().'/larena-queue-metrics-'.bin2hex(random_bytes(8)).'.sqlite';
touch($databasePath);
$capsule = new Capsule();
$capsule->addConnection(['driver' => 'sqlite', 'database' => $databasePath, 'foreign_key_constraints' => true]);
$container = new Application(dirname(__DIR__, 2));
$container->instance('db', $capsule->getDatabaseManager());
$container->instance('db.schema', $capsule->getConnection()->getSchemaBuilder());
Facade::setFacadeApplication($container);
$migration = require dirname(__DIR__, 2).'/database/migrations/2026_07_26_000000_create_larena_queue_tables.php';
$migration->up();

$clock = new FrozenQueueClock(new DateTimeImmutable('2026-10-20T00:00:00Z'));
$store = new DatabaseQueueStore($capsule->getConnection());
$registry = new JobTypeRegistry();
$dispatcher = new DurableQueueDispatcher($registry, $store, $clock);
$worker = new DurableQueueWorker($registry, $store, $clock, new QueueMetrics(static fn (): object => $recorded));
$control = new QueueControlService($store, $clock);

$descriptor = static fn (string $type, int $timeout = 60): ImmutableJobDescriptor => new ImmutableJobDescriptor(
    jobType: $type,
    operationRef: $type,
    handlerRef: 'test:'.$type,
    timeoutSeconds: $timeout,
    maxAttempts: 1,
    retryDelaySeconds: 0,
    leaseSeconds: min(10, $timeout),
    priority: QueuePriority::Normal,
    auditPolicy: 'consumer_owned',
    payloadSchemaRef: 'schema://tests/'.$type,
);
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

try {
    $cancelId = null;
    $registry->register($descriptor('metrics.ok'), $handler(static fn (): QueueJobResult => QueueJobResult::success()));
    $registry->register($descriptor('metrics.failed'), $handler(static fn (): QueueJobResult => QueueJobResult::failure('remote_unavailable', false)));
    $registry->register($descriptor('metrics.thrown'), $handler(static function (): never {
        throw new RuntimeException('boom /var/www/secret');
    }));
    $registry->register($descriptor('metrics.slow', 2), $handler(static function (QueueExecutionContext $context) use ($clock): QueueJobResult {
        $clock->advanceSeconds(3);
        $context->checkpoint();

        return QueueJobResult::success();
    }));
    $registry->register($descriptor('metrics.cancelled'), $handler(static function (QueueExecutionContext $context) use (&$cancelId, $control): QueueJobResult {
        $control->cancel((string) $cancelId);
        $context->checkpoint();

        return QueueJobResult::success();
    }));

    $dispatcher->dispatch(new DispatchRequest('metrics.ok', [], 'metrics-ok', 'corr-metrics-ok'));
    $clock->advanceSeconds(5);
    $worker->runNext('metrics-worker');
    queue_metrics_assert(
        $recorded->records[0] === ['queue.wait', 5000.0, ['job_type' => 'metrics.ok']],
        'queue.wait must measure from available_at to the claim.',
    );
    queue_metrics_assert($recorded->records[1][0] === 'queue.job' && $recorded->records[1][1] >= 0.0, 'queue.job must be a duration.');

    foreach (['metrics.failed', 'metrics.thrown', 'metrics.slow', 'metrics.cancelled'] as $type) {
        $dispatched = $dispatcher->dispatch(new DispatchRequest($type, [], $type.'-1', 'corr-'.$type));
        if ($type === 'metrics.cancelled') {
            $cancelId = $dispatched->jobId;
        }
        $worker->runNext('metrics-worker');
    }

    queue_metrics_assert($recorded->lines('queue.job') === [
        'job_type=metrics.ok&outcome=ok',
        'job_type=metrics.failed&outcome=error',
        'job_type=metrics.thrown&outcome=error',
        'job_type=metrics.slow&outcome=timeout',
        'job_type=metrics.cancelled&outcome=rejected',
    ], 'queue.job outcomes must be ok, error, timeout and rejected: '.implode(', ', $recorded->lines('queue.job')));
    queue_metrics_assert(count($recorded->lines('queue.wait')) === 5, 'Every claimed job records queue.wait once.');
    queue_metrics_assert(
        !str_contains((string) json_encode($recorded->records), 'secret'),
        'No exception text may reach the metric tags.',
    );
} finally {
    try {
        $migration->down();
    } catch (Throwable) {
    }
    Facade::clearResolvedInstances();
    @unlink($databasePath);
}

echo "QueueMetricsTest passed.\n";
