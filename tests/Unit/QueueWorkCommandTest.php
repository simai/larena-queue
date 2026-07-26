<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Larena\Queue\Commands\QueueWorkCommand;
use Larena\Queue\Contracts\QueueWorker;
use Larena\Queue\Data\QueueJobSnapshot;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Tester\CommandTester;

require_once dirname(__DIR__, 2).'/vendor/autoload.php';

function queue_work_command_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$worker = new class implements QueueWorker {
    public int $calls = 0;

    public ?string $workerRef = null;

    public function runNext(string $workerRef): ?QueueJobSnapshot
    {
        $this->calls++;
        $this->workerRef = $workerRef;

        return null;
    }
};
$command = new QueueWorkCommand($worker);
$command->setLaravel(new Application(dirname(__DIR__, 2)));
$console = new ConsoleApplication();
$console->addCommand($command);
$tester = new CommandTester($console->find('larena:queue-work'));
$exit = $tester->execute([]);

queue_work_command_assert($exit === 0, 'Default bounded worker invocation must succeed.');
queue_work_command_assert($worker->calls === 1, 'Worker command must poll exactly once when the queue is empty.');
queue_work_command_assert(
    is_string($worker->workerRef) && str_starts_with($worker->workerRef, 'larena-'),
    'Worker command must generate a safe opaque worker reference.',
);
queue_work_command_assert(
    str_contains($tester->getDisplay(), 'Processed jobs: 0'),
    'Worker command must emit only a bounded processed-job summary.',
);

$invalid = new CommandTester($console->find('larena:queue-work'));
queue_work_command_assert(
    $invalid->execute(['--max-jobs' => '0']) === 2,
    'Worker command must reject an unbounded or zero max-jobs value.',
);

echo "QueueWorkCommandTest passed.\n";
