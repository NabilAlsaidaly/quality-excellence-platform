# Phase 03A schema and infrastructure design

Authority: accepted ADR-001/002/003, Issue #11 with its Redis/unit clarification, and owner-supplied Phase 03A instructions. SRS v3.0 was accessible through connected Drive; relevant BR-001..005, FR-ID-001..012, DATA-01..10 and security/reliability/performance requirements were reviewed. No copy of the private source is committed. Business policies still OPEN remain deferred.

## Audit before implementation

Base: `56e51ee` (latest origin/main), assigned branch `feat/11-tenant-schema-foundation`, initially clean and contains that base. Framework tables: users, password_reset_tokens, sessions, cache, cache_locks, jobs, job_batches, failed_jobs plus migration bookkeeping. Users has BIGINT id and unique email; sessions.user_id is indexed but has no FK. No tenant tables exist. Keep existing migrations, User password hashing/hidden attributes and authentication compatibility unchanged; add relationships only. Baseline: 5 tests, 12 assertions, passed using isolated SQLite. Existing Redis PhpRedis connections use DB 0/default and DB 1/cache; cache/queue default to database, sessions remain database, Redis queue after_commit is false.

## Minimal schema plan

| Table | Columns and keys | Relationships / deletion |
| --- | --- | --- |
| organizations | unsigned BIGINT id PK; unique CHAR(26) public_id ULID; required name; timestamps | Tenant root; no organization_id or unapproved status |
| organization_memberships | unsigned BIGINT id PK, user_id, organization_id; timestamps; unique (user_id, organization_id); organization_id index | Restrictive FKs to global users and organizations; no membership states/permissions |
| organizational_units | unsigned BIGINT id PK, unique CHAR(26) public_id, organization_id, nullable parent_id, required name, timestamps | Restrictive organization FK; unique (organization_id,id) candidate key and index (organization_id,parent_id); composite parent FK references (organization_id,id), RESTRICT on update/delete |

Use three new migrations, never rewrite applied users migrations. Use native HasUlids with uniqueIds restricted to public_id, preserving incrementing integer PKs, a small shared model concern guarding persisted public_id updates, and public_id route keys for the two externally addressable models. No public membership API is introduced. Only name is mass assignable on organization/unit; membership foreign keys must be set explicitly via trusted relationships/association. Factories provide synthetic fixtures only.

MySQL CHECK cannot reference an AUTO_INCREMENT column, so direct self-parent prevention will use narrow INSERT/UPDATE triggers on MySQL. SQLite tests retain composite FKs and model self-parent checks; MySQL tests prove actual trigger/constraint enforcement, including raw SQL. Multi-level cycles and safe parent changes remain a future approved Action, not a claim of FK protection. Model event guards do not protect raw SQL/bulk updates; no such public write surface is introduced.

## Isolation and authorization boundary

Global-table allowlist for this phase: users, password_reset_tokens, sessions, cache, cache_locks, jobs, job_batches, failed_jobs and migrations (framework infrastructure). Organizations is the tenant root. organization_memberships and organizational_units are tenant-owned and carry organization_id. Parent integrity is guaranteed structurally; query authorization, tenant context resolution, membership lifecycle, role matrix, jobs/files/download isolation and HTTP IDOR protections remain unimplemented. No global scope, membership record, ULID or platform identity confers access.

## Redis and verification plan

Reuse PhpRedis; configure cache/queue via .env.example, keep database sessions, set Redis after_commit=true, prefix by project/environment and document tenant keys/TTL/invalidation without caching business data. Pin official Redis 8.2.11-alpine in local loopback-only Compose and CI. General tests stay SQLite/array/sync. Separate opt-in MySQL config permits destructive migrations only for exact disposable database `quality_platform_ci`, APP_ENV=testing, actual MySQL connection and no cached config/DB URL. Real Redis tests use unique per-run prefixes and individual key cleanup, never flush, and must fail on connection failure. CI preserves MySQL, UI Issue #4 scope guard, formatting/build/framework tests and adds independent MySQL/Redis steps. No packages, lockfile updates, UI edits, deployment or production operations.
