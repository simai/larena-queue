# Tests

The package quality gate runs syntax checks, PHPStan and executable PHP tests.

`DurableQueueLifecycleTest` proves on SQLite:

- canonical idempotent dispatch and conflicting duplicate rejection;
- single execution for duplicates;
- priority ordering;
- heartbeat and completion;
- state recovery through a new store instance;
- expired lease reclaim after simulated worker crash;
- retry exhaustion with sanitized exception reason;
- terminal timeout;
- queued and running cancellation;
- explicit retry and terminal deletion;
- migration rollback and reapply.

MySQL and Root consumer acceptance are intentionally deferred to later goal
batches.
