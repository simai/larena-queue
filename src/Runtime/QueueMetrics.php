<?php

declare(strict_types=1);

namespace Larena\Queue\Runtime;

use Closure;
use Illuminate\Contracts\Container\Container;
use Throwable;

/**
 * Reports queue.job and queue.wait to larena/monitoring when it is installed and bound, and does nothing
 * otherwise. Queue does not depend on larena/monitoring: the Metrics contract is resolved by name from the
 * container, so without monitoring this class is a no-op. Recording never throws into the worker.
 */
final class QueueMetrics
{
    public const string METRICS_CONTRACT = 'Larena\Monitoring\Contracts\Metrics';

    public const string CATALOG_CLASS = 'Larena\Monitoring\Runtime\MetricCatalog';

    public const string PACKAGE = 'larena/queue';

    public const array OUTCOMES = ['ok', 'error', 'timeout', 'rejected'];

    /**
     * The worker's outcome for a run whose handler answered «not yet» (QueueJobResult::retryLater). Monitoring
     * admits only its own outcome codes, so it is recorded as outcome=skipped with reason=deferred.
     */
    public const string DEFERRED = 'deferred';

    /** @var (Closure(): ?object)|null */
    private ?Closure $resolver;

    private ?object $metrics = null;

    private bool $resolved = false;

    /** @param (Closure(): ?object)|null $resolver gives the Metrics implementation, or null when there is none */
    public function __construct(?Closure $resolver = null)
    {
        $this->resolver = $resolver;
    }

    public static function fromContainer(Container $container): self
    {
        return new self(static function () use ($container): ?object {
            if (!interface_exists(self::METRICS_CONTRACT) || !$container->bound(self::METRICS_CONTRACT)) {
                return null;
            }
            return $container->make(self::METRICS_CONTRACT);
        });
    }

    public static function catalogPath(): string
    {
        return dirname(__DIR__, 2).'/monitoring.yaml';
    }

    public function job(string $jobType, string $outcome, float $ms): void
    {
        $this->timing('queue.job', $ms, $outcome === self::DEFERRED
            ? ['job_type' => self::code($jobType), 'outcome' => 'skipped', 'reason' => self::DEFERRED]
            : [
                'job_type' => self::code($jobType),
                'outcome' => in_array($outcome, self::OUTCOMES, true) ? $outcome : 'error',
            ]);
    }

    public function wait(string $jobType, float $ms): void
    {
        $this->timing('queue.wait', max(0.0, $ms), ['job_type' => self::code($jobType)]);
    }

    /**
     * The job type as a tag value code (the larena/monitoring rule ^[a-z][a-z0-9_.-]{0,59}$): lowercase, other
     * characters replaced with '_', cut at 60; 'unnamed' when it still is not a code or looks like an identifier.
     */
    public static function code(string $value): string
    {
        $code = substr((string) preg_replace('/[^a-z0-9_.-]/', '_', strtolower(trim($value))), 0, 60);
        if (preg_match('/^[a-z][a-z0-9_.-]{0,59}$/', $code) !== 1
            || preg_match('/[0-9]{5,}/', $code) === 1
            || preg_match('/(?=[0-9a-f]*[0-9])[0-9a-f]{8,}/', $code) === 1) {
            return 'unnamed';
        }

        return $code;
    }

    /** @param array<string, string> $tags */
    private function timing(string $id, float $ms, array $tags): void
    {
        try {
            $timing = [$this->metrics(), 'timing'];
            if ($timing[0] !== null && is_callable($timing)) {
                $timing($id, $ms, $tags);
            }
        } catch (Throwable) {
            // Monitoring never breaks the worker.
        }
    }

    private function metrics(): ?object
    {
        if (!$this->resolved) {
            $this->resolved = true;
            $this->metrics = $this->resolver !== null ? ($this->resolver)() : null;
        }

        return $this->metrics;
    }
}
