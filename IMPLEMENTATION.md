# Package implementation evidence

Completed locally on 2026-10-18. Scope: `packages/psst/` only. Independent MIT Composer library; no Filament dependency. The owner subsequently authorized standalone publication as public pr3slaff/psst with release v1.0.0. Publication/install evidence is tracked in the monorepo docs/VERIFICATION.md.

## Actual compatibility matrix

Every combination below passed the same **15 tests / 99 assertions** in a real Orchestra Testbench Laravel application, including a real delayed loopback HTTP response for the one-second transport limit.

| Laravel | Testbench | PHPUnit | PHP 8.3.30 | PHP 8.4.26 | PHP 8.5.11 |
| --- | --- | --- | --- | --- | --- |
| 12.70.0 | 10.12.0 | 12.5.40 | pass | pass | pass |
| 13.37.0 | 11.3.0 | 12.5.40 | pass | pass | pass |

The final committed `composer.lock` baseline is Laravel 13.37.0 / Testbench 11.3.0 / PHPUnit 12.5.40 / Guzzle 8.3.0. The Laravel 12 run resolved Guzzle 7.16.0. The Composer manifest permits Laravel 12/13 and PHP ^8.3. `composer validate --strict` passed. Composer reported no security vulnerability advisories for both actual resolved dependency sets. A test workflow for a future standalone package repository records the same six combinations; that hosted workflow has not been executed here.

## Coverage exercised

- Four levels and stable UUID acknowledgement, recursive case-insensitive masking, Unicode content.
- Exactly one synchronous attempt; silent transport and diagnostic failures; force overrides queue mode.
- Real loopback HTTP server sleeps three seconds while SDK returns a failed result at approximately one second; fake request options separately assert total timeout/connect timeout one second and redirects disabled.
- Permanent 401/403/422 versus transient 429/5xx; numeric and HTTP-date Retry-After; exponential retry release; expired job deletion.
- Actual Laravel database queue serialization and insertion, no inline HTTP; sync queue rejected; failed immediate fallback disabled by default and explicit opt-in keeps normalized payload.
- Real SQLite transactions: queue absent before commit, present after commit, rollback discards it. Dropping the jobs table before commit proves post-commit enqueue failure cannot escape the business commit.
- Message 4000-character ceiling, 64-KiB JSON ceiling, depth limit, unsupported objects without invoking JsonSerializable, non-finite numbers, self-referential arrays and explosively shared arrays bounded by global traversal limits.
- Default logger receives only safe reason/event ID; custom diagnostic recursion is bounded.
- One authenticated health route, disabled-without-key 404, wrong-key 401, boot-only response.
- Metric cache hits preserve actual computation time; per-metric callback errors retain last good result as stale; scalar validation, type and maximum 20 registered metrics.
- Canonical ingest fixture reproduced exactly except current timestamp; health response keys match shared fixture. These optional monorepo fixture checks do not require shared files when the package is split.

## Reproduce on this Windows workspace

From this package directory, install the final lock with:

```powershell
php C:/ProgramData/ComposerSetup/bin/composer.phar install --no-interaction --prefer-dist
php vendor/bin/phpunit
& C:/Users/presl/.codex/tools/psst/php84/php.exe vendor/bin/phpunit
& C:/Users/presl/.codex/tools/psst/php85/php.exe vendor/bin/phpunit
```

Resolve the lower framework line using the Composer PHAR directly (Windows batch wrappers can consume caret constraints):

```powershell
php C:/ProgramData/ComposerSetup/bin/composer.phar update --with 'orchestra/testbench:^10.0' --with 'laravel/framework:^12.0' -W --no-interaction --prefer-dist
```

Run the same three PHP commands, then restore the supported latest baseline:

```powershell
php C:/ProgramData/ComposerSetup/bin/composer.phar update --with 'orchestra/testbench:^11.0' --with 'laravel/framework:^13.0' -W --no-interaction --prefer-dist
```

## Operational boundaries

Redis/SQS/Beanstalkd production services and supervisor workers have not been externally provisioned or exercised here; the actual database driver is exercised locally. Durable backend integration is owned by the root integration task; these tests acknowledge mock responses and do not claim external persistence or FCM delivery. No public staging URL, SMTP, Firebase, phone or package registry was used. Ingestion remains one second; ten-second backend health polling is a separate concern. A blocking metric callback can exceed that backend limit by approved design. Persistent/shared cache and real asynchronous queue infrastructure are application deployment responsibilities.

Queue mode accepts only database, redis, sqs and beanstalkd drivers; sync/null/deferred/background/failover/unknown drivers fail safely. Post-commit queued results indicate registration rather than a guarantee that later enqueue succeeds. All subsequent enqueue errors are safely diagnosed. Default diagnostic warnings and custom callbacks are contained, including recursive failures.
