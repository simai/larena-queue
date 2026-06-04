# Tests

Status: `passed`

Executed commands:

```bash
PATH=/opt/homebrew/opt/php@8.3/bin:$PATH /Applications/ServBay/package/bin/composer validate --strict
PATH=/opt/homebrew/opt/php@8.3/bin:$PATH /Applications/ServBay/package/bin/composer dump-autoload
PATH=/opt/homebrew/opt/php@8.3/bin:$PATH /Applications/ServBay/package/bin/composer run quality:gate
git diff --check
```

Semantic checks:

- job descriptor contract contains timeout, retry, idempotency, priority, audit,
  payload schema and secret reference policy slots;
- missing descriptor and unsafe runtime profile decisions fail closed;
- signed web tick is represented only as emergency profile;
- lifecycle terminal status behavior is explicit.

Observed results:

- `composer.json is valid`
- Composer autoload files generated successfully.
- `validate-larena-package`: `Larena Queue coding launch context is valid.`
- PHP lint checked scripts, tools, `src` and `tests` with no syntax errors.
- PHPStan analysed scripts, tools, `src` and `tests` with no errors.
- `JobDescriptorContractTest passed.`
- `QueueRuntimeFailsClosedTest passed.`
- Evidence contract passed for the current repository state.
- Scope check passed for launch allowed files and evidence path.
- `git diff --check` passed.
