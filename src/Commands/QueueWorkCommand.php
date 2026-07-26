<?php

declare(strict_types=1);

namespace Larena\Queue\Commands;

use Illuminate\Console\Command;
use Larena\Queue\Contracts\QueueWorker;

final class QueueWorkCommand extends Command
{
    protected $signature = 'larena:queue-work
        {--max-jobs=1 : Maximum jobs to process before exiting (1-100)}
        {--worker-ref= : Optional non-secret worker reference}';

    protected $description = 'Process a bounded number of durable Larena Queue jobs';

    public function __construct(private readonly QueueWorker $worker)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $maxJobs = filter_var(
            $this->option('max-jobs'),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 100]],
        );
        if (!is_int($maxJobs)) {
            $this->error('The --max-jobs option must be an integer from 1 to 100.');

            return self::INVALID;
        }

        $workerRef = $this->option('worker-ref');
        if (!is_string($workerRef) || $workerRef === '') {
            $workerRef = 'larena-'.bin2hex(random_bytes(12));
        }
        if (strlen($workerRef) > 128) {
            $this->error('The --worker-ref option must contain at most 128 bytes.');

            return self::INVALID;
        }

        $processed = 0;
        while ($processed < $maxJobs) {
            if ($this->worker->runNext($workerRef) === null) {
                break;
            }

            $processed++;
        }

        $this->line('Processed jobs: '.$processed);

        return self::SUCCESS;
    }
}
