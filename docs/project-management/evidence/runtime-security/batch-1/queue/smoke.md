# Queue Batch 1 Smoke Evidence

Date: 2026-06-03

## Smoke Checks

- package metadata exists;
- `module.yaml` exists and points to the contract skeleton batch;
- all launch-record allowed contract files exist;
- all PHP files parse with ServBay PHP 8.2.29;
- unit smoke tests pass;
- `composer validate --strict` passes when Composer is executed through ServBay PHP PATH;
- package-local `validate:larena`, `test`, `lint`, `analyse` and `evidence:check` scripts pass;
- no forbidden runtime directories were created.

## Known Environment Limitation

Composer validation and Composer scripts pass through ServBay PHP 8.2.29. The package still targets PHP `^8.3`, so PHP 8.3+ runtime proof remains required before release readiness.
