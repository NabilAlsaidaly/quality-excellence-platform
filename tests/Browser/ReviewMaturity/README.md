# Local review and initial maturity demonstration

Issue62's reviewed `REVIEW_MATURITY_LOCAL_001` scenario is **TEST_ONLY**. These helpers are not normal providers, customer routes or operational grants. They reuse real institutional login/session isolation and the unchanged actual Approach and Document Policies, plus the new finite workflow Policy. No synthetic User or always-allow Policy exists.

Prerequisites: the repository's installed dependencies and built Vite assets, PHP8.4 with the required extensions, Docker with the existing MySQL8.4.13 image, and the already installed Playwright CLI/browser. No package installation, normal `.env`, global PHP/MySQL setting or development database change is needed.

```powershell
$php = 'C:\path\to\existing\php.exe'
$cli = 'C:\path\to\existing\playwright-cli.cmd'
& tests/Browser/ReviewMaturity/Run.ps1 -Mode Prepare -RunName browser-90 -Php $php
& tests/Browser/ReviewMaturity/Run.ps1 -Mode Browser -RunName browser-90 -Php $php -PlaywrightCli $cli
```

`Browser` drives actual login, an assigned native Filament bank, content save, independent correction/approval, editable question/scale settings, five initial stage submissions with exact authorized synthetic DocumentVersions, manual categories, history, in-app notices/read state, tenant/actor negatives and logout. It verifies independent MySQL readback and captures responsive screenshots at375/768/1280. The runner removes only its owned temporary services in `finally`; it preserves private evidence and every unrelated demo/application.

For manual exploration use a **fresh** run name and `-Mode Demo` in place of `Browser`. Demo mode starts the bounded server and leaves it running. It prints the exact loopback login URL. Open the owner-private ignored file `.local-tools/reports/issue62/review-maturity/browser-90/fixture.json` locally to retrieve institution/email/random password for `author` and `reviewer`. Use separate browser profiles or private contexts. Never print that file to logs or copy it to Git/a PR.

After the original account page, navigate on the same local origin to `/institution/mvp-workspace/workflows`. The account's normal navigation is unchanged. The independent reviewer deliberately has no J03 admission. `issuer`, `quality` and `foreign` are negative workflow-admission actors. The test panel also reuses the native DocumentResource under its unchanged real DocumentPolicy. Existing older demos are independent.

Only after manual testing is finished:

```powershell
& tests/Browser/ReviewMaturity/Run.ps1 -Mode Cleanup -RunName browser-90 -Php $php
```

The runner verifies canonical private paths, ownership labels, actual database/schema/server UUID/version and exact server PID/router before acting. It refuses to adopt an existing run or reset a database. HTTP binds127.0.0.6 only; the runtime database account is DML-only. Sessions/cache/files/passwords are private and separate. Source hashes remain frozen while a run is active; edit source only between runs. Never share its live database with PHPUnit.

All document bytes are owned, harmless synthetic fixtures. Links identify versions without serving files or asserting scan cleanliness, institutional document approval or general evidence acceptance. These workflows cover the initial cycle only; reassessment, operational scale placement/grants, real uploads/downloads, licensed references, T17 remediation, hosting and deployment remain excluded. Initial assessment history cannot be replaced.
