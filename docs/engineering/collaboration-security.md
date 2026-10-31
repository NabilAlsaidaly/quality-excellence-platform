# GitHub collaboration security — current verified state

**Updated 2026-10-31.** Earlier private-repository blocker is superseded by the verified facts below.

## Verified via connected GitHub repository metadata
- Repository: `NabilAlsaidaly/quality-excellence-platform`; **PUBLIC** on GitHub (visibility `public`).
- Default `main` branch metadata: **`protected: true`**.
- `zeko-create` is a repository collaborator with **write** permission.
- GitHub Actions `Foundation CI` completed successfully on `main` after Filament and architecture documentation merges.
- The `main` protection **detail** endpoint returns **403 Resource not accessible by integration**; classic branch protection cannot be independently audited through this connector. Empty `rulesets` does not imply absence of classic protection.

## Controls still requiring owner verification in GitHub Settings
- Require PR before merge; at least one qualified reviewer where the selected workflow supports it; required `foundation` CI check; up-to-date branch rule as appropriate.
- Prevent force-push and branch deletion; inspect and minimize owner/admin bypass.
- Verify with `zeko-create` that direct push to `main` is rejected while task-branch push and PR are allowed.
- Ensure MFA for collaborators; secrets and deployment access remain restricted. Do not put client data, credentials or private contractual documents into a **public** repository.

**Integrity note:** Branch `main.protected=true` proves a rule exists, **not** which individual protections are enabled. Owner-permitted merges without a visible external approval do not demonstrate contributor-side enforcement.

## Process
Use Issue → branch from latest `main` → tests → engineering report → PR → review → merge. Default to no bypass and no direct main edits. Maintain `.github/CODEOWNERS` as a review suggestion only; it is not sufficient without enabled rules.

References:
- https://docs.github.com/en/repositories/configuring-branches-and-merges-in-your-repository/managing-protected-branches/about-protected-branches
- https://docs.github.com/en/repositories/managing-your-repositorys-settings-and-features/repository-access-and-collaboration/permission-levels-for-a-personal-account-repository