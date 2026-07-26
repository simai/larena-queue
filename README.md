# Larena Queue

Mandatory system package for governed background execution, load smoothing, retry, timeout, worker supervision and hosting-safe operation scheduling across Larena packages.

The current bounded runtime provides registered, database-backed jobs with
idempotent dispatch, deterministic priority, persisted attempts, expiring
leases, heartbeat, retry, timeout, cooperative cancellation, crash reclaim and
sanitized diagnostics. Laravel applications discover the package provider and
its reversible Queue-owned migrations.

Consumers keep ownership of authorization, Audit policy, payload validation
and business execution. Queue stores bounded payloads and result metadata but
does not store raw secrets or absorb consumer business logic.

This is not a general workflow engine or a production-readiness claim. Signed
web tick, schedules, remote brokers, frontend and process supervision are not
part of this runtime slice.

Canonical specifications are in `simai/larena-specs`.
