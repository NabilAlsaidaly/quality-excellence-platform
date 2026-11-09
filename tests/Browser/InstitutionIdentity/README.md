# J01 — real institutional identity browser journey

Local Windows verification of the registered Arabic RTL login/account/recovery/reset/logout pages, backed by a new disposable MySQL8.4 container. It uses actual application guards, middleware, reset broker, signed URLs and Policies. No role/capability or synthetic Policy is installed. Only outbound test notification delivery is intercepted privately.

## Prerequisites and command

Use the already installed PHP8.4, Docker with an existing mysql:8.4.13 image, and Playwright CLI. The runner installs no package, edits no manifest and changes no normal.env/global setting. Supply absolute executable paths from the local inventory:

```powershell
& tests/Browser/InstitutionIdentity/Run.ps1 -Php '<existing php.exe>' -PlaywrightCli '<existing playwright-cli.cmd>'
```

Run from the project directory. The runner refuses an existing artifact namespace rather than overwriting it. `-PrepareOnly` leaves only this owned fixture running for DOM inspection; resume it with `-ContinuePrepared`, or remove only its verified resources with `-CleanupOnly` using the same executable arguments. Do not use these options on the separate institutional demo.

## What executes

1. Verify explicit opt-in, ownership, loopback-only binding, testing environment, exact disposable schema/server UUID/version and source hashes before fixture operations. Migrate an empty new schema normally; never reset/adopt existing data.
2. Create two fictional institutional accounts with the same contact email, independent passwords and memberships, and zero roles/permissions.
3. Use three independent browser contexts for correct/wrong passwords, refresh, cross-institution account rejection, independent logout, denied workspace access and actual CSRF.
4. Request recovery through the real UI; reject tampered/expired/cross-institution links, validate confirmation, reset the correct password, reject token replay and old sessions/password, preserve the other institution.
5. Read persisted password hashes/token/grant state independently in MySQL. Expire only owned session-file timestamps and verify the real session handler rejects them; this is an explicit expiry simulation, not a timed production-lifetime claim.
6. Check native components, RTL and overflow at375/768/1280; retain sanitized phase results, readback, source fingerprints and screenshots privately.

Default completion or failure closes only the owned browser/server and removes the labeled test container/exclusive volume and its credential/session/cache files. Ownership/PID/path checks refuse unrelated resources. No shared Redis flush or development database operation occurs.

Artifacts stay in ignored owner/SYSTEM-only directories under `.local-tools/reports/issue53` and `.local-tools/output/playwright/issue53`. Rendered credential-bearing scripts are transient and never printed; error diagnostics are redacted. Never commit the fixture, recovery links, cookies or private artifacts.

The normal CI suite runs `InstitutionIdentityBrowserHarnessTest` target/exit-code guards. This local browser runner is not silently added to CI; any CI/toolchain amendment needs separate approval. Browser evidence is L3 for the bounded identity candidate, not D-002 ratification or complete P0/P1/P7 acceptance.
