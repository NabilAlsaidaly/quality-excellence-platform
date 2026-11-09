# Authority and publication boundary

Issue [#51](https://github.com/NabilAlsaidaly/quality-excellence-platform/issues/51); branch `feat/mvp-completion-2026-11`; verified main/base `3cfea15e908d19042254a0670ac8c83185d738df`.

The complete private Master Contract v2 and Execution Authorization v3 govern execution. V3 explicitly supersedes historical branch-creation instructions, repository snapshots and progress-state vocabulary; other v2 requirements remain binding. Reuse the existing branch without resetting or recreating it.

On 2026-11-09 the engineering lead explicitly approved the revised publication package: publish reviewed non-confidential code/tests and sanitized engineering records, use a public summary instead of the complete contract, and preserve both authoritative originals privately outside Git. The exception is applied in MASTER-CONTRACT.md. It approves no business decision, privilege, migration, protected-source change, merge or deployment.

Use v3 phase states: NOT_STARTED, IN_PROGRESS, IMPLEMENTED_AWAITING_REVIEW, BLOCKED_OWNER_DECISION, BLOCKED_TECHNICAL_DEPENDENCY, COMPLETED_VERIFIED. Preserve the L1–L4 acceptance model. No overall phase checkmark before all required acceptance, independent review, merge and exact-main post-merge CI.

Current file scope: delivery Markdown, report51, the three new app/Domain/Maturity definitions and their new unit test. No existing product source, Policy, registry, provider, route, schema, manifest, theme or CI changes. Issue #37 remains exclusively owned; Issue #50 test capabilities cannot be activated. Issue48/PR49 is merged and its presentation must be preserved.

Full original/private reconciliation evidence and internal handovers are intentionally excluded. Publication review must inspect the exact staged list and content; uncertain material stays private.
