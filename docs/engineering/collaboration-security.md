# GitHub collaboration security — private repository
Status: **BLOCKED ON OWNER ACTION**. Source: issue #6. Verified 2026-10-31.

## Actual state
- Repository `NabilAlsaidaly/quality-excellence-platform` is private and owned by a personal account.
- GitHub API returned `main.protected = false`.
- GitHub ruleset read returned 403: "Upgrade to GitHub Pro or make this repository public to enable this feature."
- Branch protection read via integration returned 403: "Resource not accessible by integration".
- No repository plan upgrade, branch protection change or collaborator invitation has been made.

## Owner action checklist
1. Keep project private. Use an eligible GitHub plan supporting branch protection on private repositories, or a suitable organization with granular roles and an eligible plan.
2. Configure rules for `main`: require PR, at least one reviewer other than author, CODEOWNERS review when available, required `foundation` status check, no force-push or delete, no unauthorized bypass.
3. Validate using separate contributor permissions: push to `main` rejected, unauthorized merge rejected; accepted PR through checks and owner review only.
4. Enforce distinct GitHub accounts, MFA, and least-privilege access; secrets and production deployment remain with the lead.
5. Then invite collaborator. Until demonstrated protection, **do not grant Write on this personal private repo**.

## Critical limitation
Text files `AGENTS.md`, `CONTRIBUTING.md`, and `CODEOWNERS` without enforced reviewer rules cannot prevent a user with Write privileges from pushing to `main`. A fork of a private personal repository is not a substitute for upstream branch protection if collaborator retains Write. A controlled patch/review handoff without Write access is the temporary alternative.

References:
- https://docs.github.com/en/repositories/configuring-branches-and-merges-in-your-repository/managing-protected-branches/about-protected-branches
- https://docs.github.com/en/repositories/managing-your-repositorys-settings-and-features/repository-access-and-collaboration/permission-levels-for-a-personal-account-repository
