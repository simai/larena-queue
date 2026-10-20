<?php

declare(strict_types=1);

// Stand-ins for larena/monitoring's Metrics contract and MetricCatalog, which queue does not depend on.
// They are declared only when the real package is absent, so the queue tests can observe what reaches them.

namespace Larena\Monitoring\Contracts {
    if (!interface_exists(Metrics::class)) {
        interface Metrics
        {
            /** @param array<string, string> $tags */
            public function timing(string $id, float $ms, array $tags = []): void;
        }
    }
}

namespace Larena\Monitoring\Runtime {
    if (!class_exists(MetricCatalog::class)) {
        final class MetricCatalog
        {
            /** @var array<string, string> */
            public array $sources = [];

            public function register(string $package, string $yamlPath): void
            {
                $this->sources[$package] = $yamlPath;
            }
        }
    }
}
