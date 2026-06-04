<?php

declare(strict_types=1);

$tests = [
    __DIR__ . '/../tests/Unit/JobDescriptorContractTest.php',
    __DIR__ . '/../tests/Unit/QueueRuntimeFailsClosedTest.php',
];

foreach ($tests as $test) {
    require $test;
}

echo "Larena Queue contract tests passed.\n";
