# Architecture foundation

## Decision for this phase

Use Laravel's standard directory structure as a pragmatic modular monolith with lightweight domain-driven design. One application and one deployment unit keep transactions, local setup and debugging straightforward. No business modules exist yet.

Conceptual flow: Presentation -> Application -> Domain -> Infrastructure. Presentation (routes, controllers, Blade and any future admin resources) handles transport, validation and rendering; future application actions coordinate use cases, domain rules enforce approved behavior, and infrastructure implements persistence and integrations. Keep business rules out of presentation so they can be tested and reused across HTTP, console and jobs.

Use Laravel dependency injection, Eloquent, migrations, validation and native testing before adding abstractions. Introduce module namespaces and explicit public application entry points only with approved requirements. Avoid direct cross-module internals; review dependencies as modules appear. Add focused tests around approved rules and boundaries. Do not create empty module folders, per-model repository interfaces, CQRS, event buses or microservices.

## Existing structure

`app/` contains framework scaffolding only (controller base, provisional User model and provider). `bootstrap/`, `config/` and `routes/` configure Laravel. `resources/` and `public/` contain the default bootstrap page and Vite assets. `database/` retains only the three Laravel migrations, factory and empty seeder. `tests/` covers framework bootstrap. No authentication routes or business workflows are implemented.

## Deferred, requiring owner/lead decisions

Tenant isolation strategy, organization/membership schema, SaaS identity model, authentication, authorization/RBAC, admin UI, Arabic RTL product UI design, workflow states, document storage/versioning, maturity rules and integrations remain unresolved. Default users/password-reset/session tables are provisional framework infrastructure, not an approved identity design. No D-002, D-017, D-021 or other OPEN/PROPOSED decision is approved by this implementation. Revisit the provisional schema before business migrations.

MySQL 8.x is the target. SQLite bootstrap tests cannot validate MySQL-specific constraints or future tenant isolation. Deployment, tenancy packages and additional platform services are outside this phase.
