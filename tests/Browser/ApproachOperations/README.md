# J03 native Approach acceptance harness

This is **TEST_ONLY** `J03_LOCAL_APPROACH_001`, authorized by Issue #57. It exercises the installed Filament 5 / Livewire 4 institutional workspace and the actual `ApproachPolicy`. It neither replaces the Policy nor registers an application route, provider, grant command or normal-environment permission. D-002 operational ratification and L4 customer acceptance remain outstanding.

## Prerequisites

- Existing project dependencies and built Filament assets; PHP 8.4 with MySQL/SQLite extensions, Docker Desktop, the already installed `mysql:8.4.13` image and Playwright CLI/browser. The runner installs no package or browser and pulls no image.
- Windows PowerShell, a real local user identity for owner/SYSTEM ACLs, and an available `127.0.0.4` loopback port. Use the existing PHP executable and Playwright CLI paths as explicit arguments.
- A fresh `.local-tools/reports/issue57/runtime` namespace and a fresh `.local-tools/output/playwright/issue57` evidence directory. Existing artifacts are refused, never adopted or overwritten. Archive retained evidence explicitly before another run.

```powershell
$php = 'C:\path\to\existing\php.exe'
$cli = 'C:\path\to\existing\playwright-cli.cmd'
& tests/Browser/ApproachOperations/Run.ps1 -Mode Prepare -Php $php
& tests/Browser/ApproachOperations/Run.ps1 -Mode Browser -Php $php -PlaywrightCli $cli
```

`Prepare` creates a uniquely labeled, owned MySQL container with a private volume and random loopback-only SQL port. The actual database name, server UUID, version and labels are verified. Setup requires an **empty** `quality_platform_ci` schema. It never connects to, migrates, resets or adopts `quality_platform` or another existing demo. Migration setup uses the owned operator; HTTP uses a database-scoped DML-only account afterward. Existing trigger migrations still require the deployment privileges documented in the schema contract; no global setting is changed.

`Browser` starts an owned loopback PHP router in `APP_ENV=testing`. Its manifest, passwords, application key, cookies, caches and compiled views remain in the private ignored namespace. Source fingerprints prevent continuing after relevant code changes. The trusted router binds the fixture to the exact Request, database connection/PDO and UUID; HTTP inputs cannot enable that scope. Only institutional paths, the exact installed Livewire script/update endpoint and bounded public assets are served. Runtime session and authorization contracts are unchanged.

The three browser phases exercise Quality login → account → dashboard → assigned-only bank → native create modal → metadata edit; contributor login → two persisted draft revisions → draft/audit history; negative account/tenant/resource/CSRF requests; contributor revocation and Quality expiry simulation with their existing cookies; logout. Native controls and RTL/layout are checked at 1280px and 375px. Screenshots remain private. SQL readback separately verifies the resource, seven explicit initial resource assignments, metadata audit, draft authorship, four revocations, expiry observation and preserved history. Expiry in the browser is a disclosed fixture clock-boundary simulation; the MySQL suite separately tests a real expiry during a two-process lock wait.

Early shared-panel 403 responses are HTTP evidence, **not fabricated database audit events**. Action denials produce bounded tenant audit where the actor/context remains verifiable. Policy visibility queries do not write denial events. Review, publication, approval, general transfer and delegation remain unavailable.

On completion/failure the full Browser command closes its own named browser, verifies the PHP PID executable/router/port before stopping it, verifies container labels/volume exclusivity before removal, and removes only its private credentials/session/cache files. Redacted results, source receipts and screenshots are retained. `-InspectOnly` is an explicitly interactive diagnostic option that leaves these owned resources running; close its named CLI session and run the following after finishing:

```powershell
& tests/Browser/ApproachOperations/Run.ps1 -Mode Cleanup -Php $php
```

Do not use global Docker/browser/process cleanup. This runner does not own the earlier institutional demo or coworker #37 resources.

For database regressions, use a **separate fresh Prepare lifecycle**, without an active browser fixture:

```powershell
& tests/Browser/ApproachOperations/Run.ps1 -Mode MySql -Php $php
# Optional focused run: -Filter 'ApproachAssignment(Integrity|Concurrency)Test'
& tests/Browser/ApproachOperations/Run.ps1 -Mode Cleanup -Php $php
```

The existing PHPUnit suite deliberately rebuilds its disposable schema. Never run it against an active browser fixture. A populated assignment/event rollback must fail before any protected table is dropped; test cleanup is restricted to owned fixtures and is not a production retention policy. Atomic CREATE prevents handled DDL failures leaving a half-created new table; a process crash between successful DDL and migration journaling still requires operator reconciliation. No automatic adoption or destructive repair is provided.
