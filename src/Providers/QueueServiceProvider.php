<?php

declare(strict_types=1);

namespace Larena\Queue\Providers;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\ServiceProvider;
use Larena\Queue\Commands\QueueWorkCommand;
use Larena\Queue\Contracts\QueueClock;
use Larena\Queue\Contracts\QueueWorker;
use Larena\Queue\Runtime\DurableQueueDispatcher;
use Larena\Queue\Runtime\DurableQueueWorker;
use Larena\Queue\Runtime\JobTypeRegistry;
use Larena\Queue\Runtime\QueueControlService;
use Larena\Queue\Runtime\QueueMetrics;
use Larena\Queue\Runtime\SystemQueueClock;
use Larena\Queue\Storage\DatabaseQueueStore;

final class QueueServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/larena-queue.php', 'larena-queue');
        $this->app->singleton(JobTypeRegistry::class);
        $this->app->singleton(QueueClock::class, SystemQueueClock::class);
        $this->app->scoped(
            DatabaseQueueStore::class,
            static fn (Application $app): DatabaseQueueStore => new DatabaseQueueStore(
                $app->make(ConnectionInterface::class),
            ),
        );
        $this->app->singleton(QueueMetrics::class, static fn (Application $app): QueueMetrics => QueueMetrics::fromContainer($app));
        $this->registerMetricCatalog();
        $this->app->scoped(DurableQueueDispatcher::class);
        $this->app->scoped(DurableQueueWorker::class);
        $this->app->alias(DurableQueueWorker::class, QueueWorker::class);
        $this->app->scoped(QueueControlService::class);
    }

    /** Declares queue.job and queue.wait in larena/monitoring's catalog when monitoring is installed. */
    private function registerMetricCatalog(): void
    {
        if (!class_exists(QueueMetrics::CATALOG_CLASS)) {
            return;
        }
        $this->callAfterResolving(QueueMetrics::CATALOG_CLASS, static function (object $catalog): void {
            $register = [$catalog, 'register'];
            if (is_callable($register)) {
                $register(QueueMetrics::PACKAGE, QueueMetrics::catalogPath());
            }
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([QueueWorkCommand::class]);
        }
    }
}
