# Larena Queue

Mandatory system package for governed background execution, load smoothing, retry, timeout, worker supervision and hosting-safe operation scheduling across Larena packages.

The current bounded runtime provides registered, database-backed jobs with
idempotent dispatch, deterministic priority, persisted attempts, expiring
leases, heartbeat, retry, timeout, cooperative cancellation, crash reclaim and
sanitized diagnostics. Laravel applications discover the package provider and
its reversible Queue-owned migrations.

The package also registers `larena:queue-work`. The command processes one job
by default and accepts a bounded `--max-jobs=1..100` option, so an external
process supervisor can invoke it without embedding consumer business logic or
turning Queue into a scheduler.

When `larena/monitoring` is installed, the worker reports `queue.job` (run
duration, tags `job_type` and `outcome` ok / error / timeout / rejected) and
`queue.wait` (from `available_at` to the claim) through its `Metrics` contract,
and the provider declares them by registering `monitoring.yaml` in the metric
catalog. Queue does not depend on monitoring: the contract is resolved from the
container only when bound, otherwise nothing is recorded.

Consumers keep ownership of authorization, Audit policy, payload validation
and business execution. Queue stores bounded payloads and result metadata but
does not store raw secrets or absorb consumer business logic.

This is not a general workflow engine or a production-readiness claim. Signed
web tick, schedules, remote brokers, frontend and process supervision are not
part of this runtime slice.

Canonical specifications are in `simai/larena-specs`.
