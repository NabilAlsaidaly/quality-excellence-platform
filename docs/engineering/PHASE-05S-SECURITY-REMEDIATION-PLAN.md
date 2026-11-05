# Phase 05-S — scoped Symfony security remediation

Issue: [#28](https://github.com/NabilAlsaidaly/quality-excellence-platform/issues/28). Base: `2382adce5cfdb826d3caffb23ed61bec99fcf0a3`; branch: `fix/28-symfony-security-advisories`. This plan is committed before dependency changes.

## Verified baseline (2026-11-05)

- PHP 8.4.27; Composer 2.10.3; Laravel 13.39.0; Filament 5.11.0; Livewire 4.4.8; Spatie Permission 8.3.2.
- `composer validate --strict`: exit 0. Unsuppressed `composer audit --format=json`: exit 1, nine advisories across four packages, all locked at v8.1.10. Composer supplies no severity values for these entries; upstream labels are distinguished in the final report.
- HtmlSanitizer: CVE-2026-118517, GHSA-q8jq-xrf4-v5j8, GHSA-wm2w-4mgj-vw33. HttpFoundation: CVE-2026-118514, GHSA-3h27-hjv7-fqjx, GHSA-xg2q-4rrq-657q, GHSA-5mx8-mwrp-5693. HttpKernel: GHSA-wg5q-m2ww-f3c9. Routing: CVE-2026-118516.
- Each upstream advisory identifies 8.1.11 as the first fixed release in the installed 8.1 line. The [signed 8.1.11 release and changelog](https://github.com/symfony/symfony/releases/tag/v8.1.11) independently list these fixes. Package metadata confirms stable 8.1.11 artifacts and PHP >=8.4.1 compatibility. Existing Laravel/Filament/Livewire constraints permit the patch; no root requirement change is needed.
- Phase05A [PR #27](https://github.com/NabilAlsaidaly/quality-excellence-platform/pull/27) remains Draft and unmerged at `48b390b3689141cc79cddea3e535b21f351a81ff`. Its [final-head CI](https://github.com/NabilAlsaidaly/quality-excellence-platform/actions/runs/42028424164) failed at audit; later checks were skipped. This branch contains no Phase05A or UI contributor commits.

## Implementation and scope

1. Use supported Composer partial-update flags: `--with-all-dependencies --minimal-changes --patch-only --dry-run`, with temporary exact `:8.1.11` arguments for the four affected packages. Select the earliest verified fixed patch, without changing manifest constraints. Inspect the solver output before the actual update.
2. Run the same targeted update without `--dry-run`, respecting existing Composer scripts. Review the entire lockfile package/object diff: expect exactly four upgrades, no additions/removals, no unrelated dependency changes. Stop for lead scope review if a wider patch is necessary. Never suppress audit findings or bypass security blocking.
3. Allowed tracked changes: this plan, `composer.lock`, and `docs/engineering/reports/28.md`. `composer.json` is permitted only if a demonstrated blocking root constraint requires separately justified lead review; current evidence does not require it. Application, tests, schema, CI, config, npm files and owner policies remain outside scope.

## Validation and rollback

- Run strict manifest validation, platform checks, full unsuppressed audit (text/JSON), lockfile install, Artisan version/about/admin routes compared with baseline, Pint, diff/whitelist and complete package-diff review.
- Run the entire existing SQLite suite; guarded MySQL suite only against an isolated disposable MySQL 8.4 `quality_platform_ci` schema with explicit opt-in and verified target; real Redis suite only with its explicit opt-in and UUID cleanup. Preserve application databases, local secrets, existing services and unrelated untracked work. Do not cache local application configuration or change existing environment files/keys.
- Require the existing complete CI workflow on the exact final PR HEAD: clean Composer install, npm ci/build, SQLite/MySQL/Redis, migration/rollback/re-migration, formatting, cached runtime and HTTP checks. Historical counts are not fresh evidence.
- Review compatibility implications: stricter sanitizer URL handling, GET/HEAD matching, trusted proxies/base URL handling, fragment router context, compiled routing and HttpKernel payload formats. Existing authorization and tenant/history/trigger tests must stay green. A deployment operator must rebuild generated routing caches with the patched code; this task performs no deployment.
- Before merge, rollback means closing the Draft PR; the vulnerable base remains unchanged. After a lead-controlled merge, any revert requires a reviewed commit and restores known vulnerabilities, so a forward security correction is preferable. Do not run migrations or alter database settings for this patch.
- Commit a factual report with all advisory links, changed-package table, actual test results, limitations and CI evidence. Push a Draft PR only; no merge/deployment, no Phase05A/UI branch edits. Stop for engineering-lead review.

Official tooling references: [Composer update](https://getcomposer.org/doc/03-cli.md#update-u-upgrade), [Composer audit](https://getcomposer.org/doc/03-cli.md#audit), [Laravel 13 testing](https://laravel.com/docs/13.x/testing), [Filament 5 upgrade guidance](https://filamentphp.com/docs/5.x/upgrade-guide).
