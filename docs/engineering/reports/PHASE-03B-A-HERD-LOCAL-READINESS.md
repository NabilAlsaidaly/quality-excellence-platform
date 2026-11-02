# PHASE-03B-A — Herd local readiness and authorization decision preview

Date: 2026-11-02 (Asia/Damascus). Contract: Issue #16 and owner-provided phase3B.md. Public report: no credentials, APP_KEY, private source excerpts or personal filesystem identifiers are included. Host changes below were actually executed on Windows, not inferred from a remote container.

## 1. Executive Summary

Prepared the existing repository for real Herd browsing at **http://quality-excellence-platform.test** with PHP 8.4, a dedicated MySQL 8.4.13 development database and Redis cache/queues, retaining database-backed sessions. The independent phpMyAdmin.test mapping/configuration and HTTP availability were preserved. Applied six existing migrations to a verified new empty application database; both units triggers and tenant FKs verified. Actual HTTP invalid/valid login, CSRF rejection, persisted database session, logout and unauthenticated redirects passed using a temporary synthetic account that was deleted afterward. No role, authorization, UI, dependency or business-schema implementation was added.

General tests 18/68, dedicated MySQL 21/91 and Redis 4/19 (tests/assertions) passed. A running user-owned Vite process prevented root npm ci twice; a separate locked install succeeded, missing dependency files were restored without replacing identical locked binaries, and the real root Vite build passed. The limitation is retained explicitly below. Draft PR/publication evidence is recorded after creation, without claiming a merge or production-security approval.

## 2. Scope and Approved References

Reviewed current AGENTS.md, CONTRIBUTING.md, README, accepted ADR-001/002/003, architecture foundation/decision ledger/tenant-schema note, local/Redis setup, Phase 03A report including trigger-recovery review, environment example and all three PHPUnit configurations. Consulted Issues #12/#13/#16. Connected Drive supplied SRS v3.0 (source modification 2026-10-31); reviewed relevant identity/isolation/security and open-decision material without copying its private text. Technical reference books named by the task were not available in the repository and are not claimed as reviewed.

Official Herd linking/CLI guidance, MySQL image documentation, Filament 5 user access and Spatie v8 prerequisites/Teams/cache documentation informed the runbook and **PROPOSED — NOT OWNER APPROVED** preview. These sources do not approve business policy. No Spatie install/resolution/migrations/roles, tenant switching, Actions/Policies, domain jobs, workers, notifications or production operation.

## 3. Local Environment Audit

| Item | Actual observation |
| --- | --- |
| Host | Windows 11 Pro, version 10.0.26300; actual project workspace on host |
| Git baseline | main and pre-created Issue #16 branch both at merged Phase 03A commit be6cd80; ancestry check passed |
| Existing work | One pre-existing untracked generated PHP/LSP file under storage/framework; preserved and excluded from this task's commits |
| Herd | 2.0.4; core NGINX/PHP services active; no Herd Pro assumption |
| Selected PHP | Herd and CLI 8.4.27; site pinned to PHP 8.4 via supported link option |
| Extensions | pdo_mysql, pdo_sqlite, redis 6.1.0, mbstring, openssl, intl, XML/DOM, curl, zip and Composer platform requirements available |
| Composer | 2.10.3 |
| Node/npm | Host default Node 25.1.0; existing workspace Node 24.22.2 LTS used for verified installation/build; npm 11.19.1; no global change |
| Laravel/UI tooling | Laravel 13.39.0, Filament 5.11.0, Livewire 4.4.8; dependencies unchanged |
| Native MySQL | Running MySQL97 service on 3306; phpMyAdmin configured for that local port. No authenticated SQL version/database-list access was available to this task |
| Existing Redis | No listener on expected local ports before setup; existing project Compose fallback used |
| Docker | Desktop initially stopped; started for necessary local services; existing project test images/services inspected |
| Local .env | Existed, APP_ENV=local/debug off/key present; originally SQLite with three framework migrations applied and three tenant migrations pending. Existing key and old SQLite data preserved |
| Existing dev processes | Herd reported a running dev stack and external Vite process. Left them untouched; this task did not start queue workers |

An initial restricted OS/service/network query was denied; permitted read-only host inspection succeeded. This is distinct from unavailable host access: the host was accessible and its environment was actually configured.

## 4. Herd Installation and Site Mapping Verification

No installation/reinstallation was needed. Inspected links first: requested alias was unused. Executed `herd link quality-excellence-platform --isolate=8.4 --no-interaction` in the existing repository; readback confirmed alias -> current project and PHP 8.4. The existing project was not moved or recreated. Ignored APP_URL set to the verified HTTP URL, existing APP_KEY retained. No manual hosts/nginx/PHP configuration or certificate change.

Final real HTTP through Herd (no artisan serve launched by this task): `/` **200**, `/up` **200**, `/admin/login` **200**. `/admin` without login **302** to the Herd login URL. These requests use the named port-80 virtual host. `/up` alone was never used as proof of MySQL/Redis health; both services were verified independently. No HTTPS claim.

## 5. Preservation of Existing phpMyAdmin.test

Existing linked site was inspected before any change. It uses cookie authentication and TCP 127.0.0.1:3306. Saved local mapping/configuration fingerprint for comparison; final mapping and configuration hash matched exactly. HTTP **200** with phpMyAdmin page marker both before and after setup. No reinstall, relink, rename, overwrite, server-list change or unrelated database operation. Neither its private configuration nor its filesystem location is published here.

One root/blank-password read-only local MySQL connection attempt was denied (**1045**). No further password guessing, credential extraction or configuration change followed. Native server database names/data were not listed or modified. This limitation is factual; the independent supported application server below resolves project connectivity without disturbing the existing service.

## 6. MySQL Connection and Schema Status

Because the inspected native service was MySQL97 rather than the required 8.x and its credentials were unavailable, created an independent optional **mysql:8.4.13** Docker service using `compose.mysql-local.yml`. It publishes only **127.0.0.1:13306**, with a persistent project volume and TCP health check. It does not replace native 3306 or reconfigure phpMyAdmin. Initial proposed 3307 failed because Windows reserves 3307–3406; selected a verified free, non-excluded port instead. No Windows exclusion was changed. Initial startup health briefly preceded authenticated TCP readiness; TCP health check corrected and the subsequent connection succeeded.

Generated random local root/runtime secrets into ignored private files without printing values. Verified the new server selected quality_platform, version **8.4.13**, initial application table count **0**, and that the runtime account name was unused. Created a dedicated runtime account restricted to **SELECT, INSERT, UPDATE, DELETE on quality_platform only**; grant readback confirmed it. Existing container operator credentials were used only in the local migration process, not written to application .env. No global logging/trust setting changed: log_bin=1, log_bin_trust_function_creators=0. This is local development only, not a production account prescription.

Ran only the six existing pending additive migrations against that verified new application database. No migrate:fresh, rollback, DROP DATABASE, db:wipe or truncation against application/native data. Readback: **6 Ran**, **12 tables**, **2 units triggers**, memberships user/organization FKs, unit organization FK and composite (organization_id,parent_id) -> (organization_id,id) verified from MySQL metadata. Schema/models/migrations were not edited. Existing trigger failure/cleanup/prerequisites remain as documented in Phase 03A.

Ignored application .env now selects MySQL at 127.0.0.1:13306/quality_platform and runtime DML credentials. Existing key unchanged, debug off, local environment. Post-smoke counts: users/organizations/memberships/units all **0**, proving temporary user cleanup and no seeded real/domain data. Anonymous framework sessions may remain until normal expiry; no unrelated sessions were erased. Native 3306 remains untouched.

## 7. Redis Connection and Configuration Status

Started the already-existing `compose.redis.yml` fallback only after finding no Redis listener. Actual pinned Redis **8.2.11**, healthy, published only **127.0.0.1:6379**. Application .env: CACHE_STORE=redis, QUEUE_CONNECTION=redis, SESSION_DRIVER=database, REDIS_CLIENT=phpredis, DB 0 for queues and DB 1 for cache. Runtime configuration verified `quality:local:` namespace and after_commit=true.

Real ping, UUID probe write/read/individual invalidation with finite TTL passed. No FLUSHALL/FLUSHDB, private content, permission cache, domain payload or worker created. Separate integration suite verified TTL expiry, physical namespace and commit/rollback queue behavior under its testing-only prefix. Development MySQL and Redis services/volumes are intentionally left available for ordinary local browsing; the disposable MySQL integration container is stopped after its tests.

## 8. Filament Local Authentication Smoke Test

Used real HTTP requests through Herd, framework cookies/CSRF and the page's signed Livewire 4 component snapshot/update URL. No route, middleware, User model, panel gate or auth/session design was changed. Test-only helper scripts/artifacts live under ignored .local-tools; no secret/snapshot/cookie artifact is committed.

| Check | Actual result |
| --- | --- |
| Anonymous /admin | PASS: 302 to login |
| /admin/login | PASS: 200, existing Filament form/CSRF present |
| Update request without CSRF | PASS: 419 rejection |
| Incorrect password | PASS: rejected, no auth redirect or admin access |
| Correct synthetic local login | PASS: Livewire authentication redirect to /admin |
| Authenticated /admin | PASS: 200 |
| Session storage | PASS: session row tied to fixture user persisted in MySQL |
| Logout | PASS: 302, subsequent /admin denied/redirected |
| Fixture cleanup | PASS: only created synthetic user/owned authenticated session rows and probe key removed; users count returned to zero |

No roles or memberships granted. Initial HTTP helper attempts incorrectly assumed a pre-v4 update path and one layout's CSRF location; they were treated as failed probes, corrected to the advertised hashed update URI/layout token, and all final checks passed. No application behavior was weakened. This is a local framework shell smoke test, **not** production/tenant authorization approval; Issue #13 remains open. No visual browser/UI design review is claimed.

## 9. Test Commands and Actual Results

Commands were run from the repository root. `P` denotes actual `php -d sys_temp_dir=.local-tools/tmp`; `C` denotes that PHP invocation with the installed Herd Composer PHAR (executable home path intentionally redacted). This workaround changes no host PHP setting. Native HTTP probes disable external proxies and use only the local Herd hosts. Local helper names below identify real executed ignored scripts, not committed application features.

| Command/check | Status / actual evidence |
| --- | --- |
| C validate --strict | PASS, exit 0; composer.json/lock valid; restricted user-cache warning did not invalidate validation |
| C check-platform-reqs | PASS, exit 0; all installed package requirements satisfied |
| P artisan about | PASS, exit 0; actual local URL, mysql/redis/database-session drivers, debug off |
| P artisan migrate:status | PASS, exit 0; six existing migrations Ran on new app MySQL |
| P .local-tools/phase3b-initialize-mysql.php | PASS final, exit 0; new-empty-schema guard, runtime account, additive migrations, metadata readback; earlier pre-ready TCP greeting failed and was retried only after readiness |
| P artisan test | PASS, exit 0; **18 tests, 68 assertions**, genuine in-memory SQLite/isolated driver regression included |
| P vendor/bin/phpunit --configuration phpunit.mysql.xml | PASS, exit 0; **21 tests, 91 assertions** on separate disposable quality_platform_ci service at 33068 with explicit opt-in/operator credentials, never app database |
| P vendor/bin/phpunit --configuration phpunit.redis.xml | PASS, exit 0; **4 tests, 19 assertions**, real Redis/testing UUID namespace |
| P vendor/bin/pint --test | PASS, exit 0 |
| npm.cmd ci in active root install (two attempts) | FAIL, exit -4048 (Windows EPERM/unlink); existing running Vite holds a native dependency binary; no user process killed |
| npm.cmd ci --prefix .local-tools/phase3b-build | PASS, exit 0; exact copied package manifests/lock, 87 packages, no vulnerabilities; existing npm esbuild script-policy warning retained |
| Restore from that matching locked install | PASS; copied 1362 missing/different dependency files, preserved 2307 identical files including locked binaries; no package/version/lockfile change |
| npm.cmd run build at actual project root after restoration | PASS, exit 0; Vite 7.3.7, 61 modules and manifest/CSS/JS generated |
| P .local-tools/phase3b-auth-smoke.php | PASS final, exit 0; **17 runtime/HTTP booleans true**, authenticated session/CSRF/Redis/login/logout, finally cleanup |
| php .local-tools/phase3b-final-schema-check.php | PASS, exit 0; app FK metadata, DML-only grants, zero synthetic/domain rows after cleanup |
| Herd HTTP and phpMyAdmin repeat requests | PASS; 200 homepage/up/login and phpMyAdmin; appropriate admin redirects |
| Compose validation / TCP health | PASS final for local MySQL/Redis; failed reserved port documented and corrected without changing OS reservations |
| git diff --check | PASS; staged check repeated before commit |
| Production auth, owner-approved role/tenant/IDOR tests | NOT RUN/NOT IMPLEMENTED; gated by #12/#13 and intentionally out of scope |

Successful existing suites total **43 tests, 178 assertions**, zero failed/skipped in final runs. The 17 HTTP/runtime booleans are separate smoke evidence, not inflated PHPUnit counts. Fresh root npm ci remains blocked while the user-owned Vite binary is loaded; locked installation and actual root build were verified using the non-destructive alternative. No tests removed/changed and no CI modifications.

Unchanged automatic [Foundation CI run 41447389706](https://github.com/NabilAlsaidaly/quality-excellence-platform/actions/runs/41447389706), commit c95539c, completed **SUCCESS**. Fresh Linux Composer install/validation/platform/audit, MySQL migration repeatability, actual isolated SQLite **18/68**, MySQL **21/91**, Redis **4/19**, Pint, npm ci, Vite build and HTTP checks all passed. The existing Issue #4 scope gate was appropriately skipped for Issue #16; no test suite was skipped. Linux npm ci success does not conceal or replace the disclosed Windows root-install failure. Host Herd/phpMyAdmin/login evidence above is separate from CI.

## 10. Security and Data-Safety Review

All new credentials, .env backup, HTTP cookies/snapshots, helpers and machine-specific audit records remain in ignored .local-tools/.env. Public changes contain only safe local service configuration and sanitized documentation. Existing APP_KEY preserved. No credentials, private SRS excerpts, real data, absolute home paths or local personal identifiers are published. Existing unknown LSP file preserved and not staged. No global DB settings, native MySQL state, phpMyAdmin config, host/nginx/PHP files manually edited, repository protection or deployment settings changed. Normal tests do not alter the main app DB connection; integration destructive work is confined to its separate guarded container/schema. Runtime DML credentials are separate from migration operator.

HTTP remains local development; no HTTPS/production panel readiness claim. No permission decisions placed in Redis. The existing local Filament allowance is not a new bypass and does not resolve production access gates. User-owned background/dev processes were inspected but not stopped or started by this task.

## 11. Files Created or Modified

- compose.mysql-local.yml — optional, pinned, loopback-only local MySQL fallback with private required password, persistent volume and TCP health check.
- docs/setup/herd-windows.md — safe audited setup, preservation, fallback, migrations/credentials, npm-lock and test instructions.
- docs/engineering/PHASE-03B-RBAC-DECISION-PREVIEW.md — explicitly PROPOSED, NOT OWNER APPROVED, compatibility/Teams/Policies/Actions/cache/context preview and owner gates.
- docs/engineering/reports/PHASE-03B-A-HERD-LOCAL-READINESS.md — this permanent factual report.
- README.md, docs/README.md, docs/setup/local-development.md — link the Herd path and new documents.

Local-only modifications: Herd project mapping/isolation, ignored environment/service secrets, new local services/schema/runtime user, restored dependency installation and generated assets. No tracked application/domain/auth/UI/config/test/migration/CI/dependency manifest or lockfile change. No deleted user file.

## 12. Authorization Decisions Still Open

Issues #12/#13 remain open. D-001 exceptional support access, D-002 role/delegation matrix, D-021 invitation/activation/switching, D-022 self-review, D-023 suspension, D-024 enrollment/recovery/MFA, D-010/D-019 panel/UI boundaries and related retention/domain-ownership policies remain owner-gated. The preview is not an approval, package selection or implementation contract. No decision ledger/SRS status changed.

## 13. Known Limitations and Blockers

- Native MySQL97 credentials/database listing were not accessible; no claim of authenticating that server. Its intentional phpMyAdmin site remains intact; an independent supported app server solves the task without replacing it.
- Clean root npm ci is blocked by user-owned Vite's loaded native binary until that process can be intentionally stopped by its owner. The successful locked alternate installation/root build and restored dependencies are verified; no background process was killed to hide the limitation.
- Host default Node is 25; verified builds use the existing Node 24 tool locally. No global upgrade is included.
- Existing dev/queue processes may have boot-time configuration from before .env changes; the owner should intentionally restart owned dev processes before using them for future work. Herd HTTP/new CLI invocations were verified against current settings. No domain work is queued by this task.
- Local services require Docker Desktop running and retain their project data volume/secrets. Never remove volumes or regenerate secrets to resolve an existing-data issue. Trigger migration privileges/definer/cleanup risks remain as documented in Phase 03A; no global workaround.
- No production authorization/tenant workflow is approved or implemented. Optional HTTPS and visual UI/security rollout review remain separate tasks.

## 14. Git Branch and Commits

Repository: https://github.com/NabilAlsaidaly/quality-excellence-platform. Default/base main: merged Phase 03A commit `be6cd80ee0f40a4c4412531b0d6a6cc36c227f53`. Pre-created assigned branch **chore/16-herd-local-readiness**, tracking the matching remote and containing current main. Implementation/docs commit `c95539c8ec165adaaca4feb379de2c0c2f0a152a` pushed successfully. Report-only follow-up commits are available in branch git log. No main write, force push, contributor branch change or merge. Unknown untracked storage file remains preserved and excluded; task changes were committed explicitly by path.

## 15. Draft Pull Request URL

Verified [Draft PR #17](https://github.com/NabilAlsaidaly/quality-excellence-platform/pull/17), open/draft/unmerged, targeting main from chore/16-herd-local-readiness. Changed-file readback confirms the permanent report, proposed preview and only seven approved documentation/local-service files. CI configuration is unchanged; the successful automatic run is recorded in section 9, independently from host execution. Final report-only publication/readback retains this PR in draft; no merge.

## 16. Final Engineering Verdict

**Local environment is verified and delivered for engineering-lead review**, with the disclosed root npm ci/Vite lock limitation and native-service credential boundary. Herd URL, project MySQL 8.4/Redis, local framework login/session/CSRF and existing independent test suites are verified. Permanent report and proposed authorization preview are committed/pushed and included in verified Draft PR #17. This verdict approves neither owner policy nor production panel/tenant authorization. Next technical step: lead review of this local-readiness PR, then obtain #12 owner decisions and define the #13 authentication/context/panel-gate implementation contract. No merge performed; stop for lead review.
