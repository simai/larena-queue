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

`QueueDeferralTest` proves on SQLite that `QueueJobResult::retryLater()`
reschedules the job after its bounded delay as `deferred` with its reason,
spends no attempt (a one-attempt job waits three times and then completes),
leaves plain retryable failures unchanged, fails with
`job_deferral_limit_reached` past the descriptor's deferral limit, starts the
count afresh on a manual retry, is cancelled at once while deferred (and a
deferral after a cancel request ends cancelled), records `queue.job` as
outcome `skipped` with reason `deferred`, and that the `deferral_count`
migration rolls back and reapplies.

MySQL and Root consumer acceptance are intentionally deferred to later goal
batches.
