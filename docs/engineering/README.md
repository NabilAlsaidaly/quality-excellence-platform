# Engineering governance

## Project purpose
Arabic RTL multi-tenant quality/excellence platform. Organization admins and organizational quality departments have distinct duties; quality departments create approaches, assign owners, review evidence/document changes, and later manage maturity and reference-framework assessments. Saving review-eligible changes triggers quality review automatically; no manual submit button. Maturity has five sequential stages governed by approved human assessments.

## Reference
Complete working specification (SRS + team governance v3.0): [Google Docs reference](https://docs.google.com/document/d/1QAPmgGzxfhe_BhBSwsSyCruajVq4unNli-cairQ_mZs/edit).
The SRS contains a decision register with OPEN items. Do not use those proposals as implicit permissions.

## Workflow
Issue → task branch → focused changes + real tests → engineering completion report → Draft PR → lead review/merge.

## Active blocking risk
Private GitHub repository rulesets endpoint returned 403 (upgrade plan or make public), and branch-protection introspection returned 403 via integration. `main` is **not verified as technically protected**. Keep repo private and resolve GitHub permission/protection strategy before granting contributor Write access.

## Task report template
Each task writes `docs/engineering/reports/<ISSUE-ID>.md` with executive summary, SRS/AC mapping, changed files, test commands/results, DB and security impacts, unresolved decisions, branch, commit and PR link.

## Scope
This is a governance baseline only. Laravel scaffolding, migrations, model design, role definitions, and product implementation will happen in separately approved Issues.
