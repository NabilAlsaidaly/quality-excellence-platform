# Independent Document workspace browser fixture — TEST_ONLY

This owned loopback harness exercises actual institutional login, real `DocumentPolicy`, native Filament5/Livewire4 and MySQL8.4. It assigns no J03/Approach permissions and never replaces the authentic User or Policy. The distinct `document_fixture` panel is registered only by the new test support provider before the owned kernel boots. Its admission adapter validates the actual institutional guard/provider/session/password/tenant/member and current Document Policy. Normal providers/routes/guards/User remain unchanged.

Prerequisites: existing Docker Desktop/MySQL8.4.13 image, installed PHP8.4 with PDO MySQL, Composer dev dependencies, existing Playwright CLI/Chromium and built Vite assets. Install no new package for this fixture. Use explicit paths to installed executables:

```powershell
$php = 'C:\path\to\existing\php.exe'
$cli = 'C:\path\to\existing\playwright-cli.cmd'
& tests/Browser/DocumentWorkspace/Run.ps1 -Mode Prepare -RunName browser-01 -Php $php
& tests/Browser/DocumentWorkspace/Run.ps1 -Mode Browser -RunName browser-01 -Php $php -PlaywrightCli $cli
```

Prepare creates a fresh namespaced labeled container, exclusive disposable volume and random loopback SQL port. The actual schema `quality_platform_ci`, UUID, MySQL8.4 version, labels and private canonical paths are checked before migration. It refuses existing runtime/evidence directories and populated schemas. Never point it at development `quality_platform`, an existing demo, or another application's service. Original trigger migration privileges still apply; the owned migration operator uses its private credential. HTTP has only SELECT/INSERT/UPDATE/DELETE on the disposable schema. No SET GLOBAL or normal.env/global PHP change.

Private state resides under `.local-tools/reports/issue62/documents/browser-01`; screenshots under `.local-tools/output/playwright/issue62/documents/browser-01`, both ignored and owner-only. Credentials are randomly generated and injected through a private temporary script, not Git, command arguments or printed logs. The router permits only exact loopback host/port, institutional routes, the exact installed Livewire script/update and bounded public assets. It disables local-disk serving and exposes no file upload/download or production scanner. All bytes are harmless server-generated synthetic streams. The fixture ID header is a readiness identifier, never an input that authorizes access.

The journey covers editor login/account -> independent Document bank -> native create -> metadata edit -> synthetic immutable append -> reload/version/metadata history; independent reader and forbidden actors/tenants; actual issuer revocation and old-session refusal; disclosed test-clock expiry and actual Action denial; original logout. Screenshots1280/768/375 check RTL and page overflow. Independent SQL/file readback verifies original metadata, new snapshot, two versions/authorship/hash, six initial resource grants/audits, zero Approach permissions/assignments and lifecycle history. A panel visibility403 does not fabricate a denial audit; the explicitly executed expired-create Action supplies verifiable expiration evidence. Real expiry during actual SQL waits is tested separately by the MySQL suite.

Browser completion closes only its named browser and verifies the owned PHP PID executable/router/port, container issue/session labels and volume exclusivity before cleanup. Generated logs/readback/screenshots remain. `-InspectOnly` is an explicitly interactive diagnostic mode that leaves only these owned resources running. After finishing that mode, or after a setup failure before browser cleanup, run:

```powershell
& tests/Browser/DocumentWorkspace/Run.ps1 -Mode Cleanup -RunName browser-01 -Php $php
```

Never use global Docker/browser/process cleanup. Preserve failed evidence and select a fresh `browser-02` lifecycle for a corrected run; do not overwrite/adopt an old run. PHPUnit database regressions use a separate owned service and must never run against this active browser fixture. No operational grants, D002/D007 ratification, L4/security acceptance, merge or deployment follows from a passing fixture; T17 remains deferred.
