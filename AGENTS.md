# AGENTS.md

> Repository-wide instructions for coding agents working on `rezahh107/gravity-notification-manager`.

## 1. Read First

Before any material implementation change, read in this order:

1. `docs/PRODUCT_IDENTITY.md`
2. `docs/TARGET_ARCHITECTURE.md`
3. `docs/MIGRATION_PLAN.md`
4. `docs/SALVAGE_REFERENCE.md`
5. this `AGENTS.md`
6. the current task / work-unit instruction
7. relevant target source and tests

Authority order:

```text
Current explicit Owner/task instruction
    ↓
docs/PRODUCT_IDENTITY.md
    ↓
docs/TARGET_ARCHITECTURE.md
    ↓
docs/MIGRATION_PLAN.md
    ↓
docs/SALVAGE_REFERENCE.md
    ↓
AGENTS.md
    ↓
current source/tests
```

Do not treat legacy runtime behavior, README history, old class names, or old comments as architecture authority.

## 2. Canonical Product Identity

```text
Product Name: Gravity Notification Manager
Repository: rezahh107/gravity-notification-manager
Plugin slug: gravity-notification-manager
Text domain: gravity-notification-manager
Greenfield PHP namespace: GravityNotify
```

All new greenfield code uses this identity.

Do not carry the legacy `GFSMS` namespace or old plugin slug/text domain into new target code unless a narrow migration compatibility shim explicitly requires reading old state.

## 3. Greenfield-by-Default Rule

The new core is written from the closed target architecture, not by refactoring legacy classes in place.

Required sequence:

```text
TARGET_ARCHITECTURE.md
        ↓
design target responsibility from scratch
        ↓
verify current official API/contract
        ↓
need prior implementation knowledge?
        ↓
SALVAGE_REFERENCE.md
        ↓
inspect exact file from immutable legacy tag
        ↓
validate
        ↓
transplant only the smallest still-valid behavior
        ↓
target-focused tests
```

Immutable legacy source:

```text
tag: legacy-source-pre-greenfield-2026-09-02
commit: 7556f86ecc65f37d34d9563ce2087f16235bbca5
mode: READ_ONLY_REFERENCE
```

Do not create an in-tree `legacy/` copy.

Do not evolve these legacy components into the new core:

- `Event_Queue`
- legacy Flow `Listener`
- legacy `Dispatcher` orchestration
- direct `GravityForms_Handler` execution model
- duplicate `Sms_Sender`
- `ProviderFactory`
- legacy event snapshot/state/type model
- heavy exactly-once `LockManager`
- custom Rule engine
- delivery log table as state authority

Legacy runtime files may be modified only for controlled cutover/deactivation, bounded data migration, a narrowly proven compatibility shim, or safe retirement/deletion.

## 4. Closed Architecture Invariants

Preserve all of the following unless the architecture is formally reopened:

1. Gravity Flow Editor owns workflow topology, routing, approvals, assignments, and next-step decisions.
2. Point Manager guides and verifies; it does not silently insert, reorder, or mutate workflow Steps.
3. `GFFeedAddOn` Feed configuration is the canonical notification Rule mechanism.
4. Gravity Flow workflow-position notifications use the current supported compatible Feed-Step mechanism.
5. One Step/Feed represents one logical notification.
6. Baseline delivery is synchronous.
7. No notification delivery/retry dependency on Action Scheduler, WP-Cron, server cron, custom queues, or background workers.
8. Provider failure must not indefinitely block the business workflow.
9. Availability is preferred over strict exactly-once delivery; best-effort duplicate suppression is sufficient.
10. Bale is a separate channel, not an SMS provider.
11. SMS fallback is capability-aware; unsupported capability is skipped, never auto-translated.
12. Gravity Forms Entry Meta is the baseline delivery-state authority.
13. Manual Retry is explicit, synchronous, capability-protected, and nonce-protected.
14. Staff contact user-meta keys are `wudm_notification_mobile` and `wudm_bale_chat_id`.
15. The plugin has no runtime dependency on WP-Bulk-Import classes and does not depend on `plato_user_mobile`.
16. GravityView + Elementor provide central presentation; they do not own workflow or delivery state.
17. Gravity Forms Entry Detail retains a small operational status/manual-Retry fallback.
18. Never intentionally leave legacy and greenfield real senders active for the same logical notification scope.

## 5. Provider Rules

A new SMS provider is eligible only if it has:

- official/documented API behavior;
- HTTPS transport;
- defined authentication;
- WordPress HTTP API compatibility;
- explicit recipient/message semantics;
- synchronous request/response behavior;
- operationally useful success/error distinction;
- no mandatory background worker for baseline delivery;
- at least one useful declared capability such as `plain` or `pattern`.

Provider-specific behavior stays behind the provider boundary.

IPPanel is the initial primary provider. Reuse only current-valid Edge behavior after validation against current official documentation and target-focused tests.

## 6. Recipient Rules

Target recipient sources may include:

- Gravity Forms Entry field;
- fixed target;
- WordPress user;
- WordPress role;
- Gravity Flow assignee / step assignee where supported;
- fixed Bale target;
- user-based Bale target.

Do not introduce SRWF-specific recipient classes such as Registration Officer, Accountant, or Manager. Those are operational identities represented through WordPress/Gravity Flow users, roles, and assignments.

Missing contact data is not a fatal workflow exception. Record/skip the unavailable destination and continue the valid configured fallback path.

## 7. Operational Status and Retry

Operational delivery state belongs to Gravity Notification Manager.

Central Attention Required presentation should use GravityView + Elementor where practical.

Gravity Forms Entry Detail must remain independently usable for status and Retry.

Retry actions must:

- require appropriate capability;
- verify nonce;
- sanitize identifiers;
- execute immediately/synchronously;
- record a new attempt;
- never schedule background work.

## 8. WordPress / Gravity Forms / Gravity Flow Standards

Use current official supported APIs and extension points.

Prefer WordPress APIs for HTTP, user metadata, capabilities, nonces, sanitization, escaping, and options.

Prefer the WordPress HTTP API over raw cURL unless a proven requirement prevents it.

Before implementing a material Gravity Forms / Gravity Flow integration contract, verify the current official API/documentation. Do not reconstruct undocumented behavior from memory.

Use native Gravity Forms Feed settings, merge tags, and conditional logic before writing custom equivalents.

## 9. PHP and Dependency Discipline

Current repository Composer requirement is PHP `>=8.2` until the greenfield package metadata is intentionally replaced.

Use strict types where consistent with the target codebase.

Prefer explicit types, focused classes, narrow interfaces, dependency injection when it materially improves testability, and early validation at boundaries.

Avoid speculative abstractions.

Do not manually edit `composer.lock`.

Do not add queue/scheduler packages for notification execution.

## 10. Testing

Automated tests must never send real SMS or Bale messages.

Use provider fakes/stubs and deterministic fixtures.

Critical target tests include:

- normal Gravity Forms Feed execution;
- Gravity Flow Feed-Step interception/execution;
- native conditional logic;
- recipient resolution;
- provider capability gating;
- primary/secondary synchronous fallback;
- Bale fallback;
- `SUCCESS`, `FAILED`, `AMBIGUOUS`, `SKIPPED` observability;
- Entry Meta persistence;
- Attention Required state;
- manual Retry;
- workflow continuation on delivery failure;
- absence of background notification scheduling;
- absence of dual legacy/new real senders.

Run relevant Composer scripts when available:

```bash
composer lint
composer stan
composer test
composer phpcompat
```

If a check cannot run because the environment or required product fixtures are unavailable, report `NOT_EXECUTED_ENVIRONMENT_UNAVAILABLE`. Never report an unexecuted check as passing.

## 11. Security / Privacy Boundary

This is a personal/internal plugin; security must be proportional, not enterprise-heavy.

Still mandatory where applicable:

- capability checks;
- nonces for state-changing admin actions;
- sanitization;
- escaping;
- no provider credentials in logs;
- no implicit external network request merely from rendering an admin/diagnostic page;
- no public unauthenticated Retry action;
- no production credentials or real destinations in automated tests.

Doctor/diagnostic surfaces are side-effect-free by default.

## 12. Change Scope

Implement the smallest coherent Work Unit.

Do not mix unrelated cleanup, broad namespace churn, repository-wide formatting, unrelated dependency updates, or adjacent architecture redesign into a bounded migration Work Unit.

If legacy was consulted, report exactly:

```text
Legacy asset/tag/path:
Target responsibility:
What behavior was transplanted:
What was intentionally not transplanted:
Current contract checked:
Tests added:
```

## 13. Documentation

README is human-oriented and points to authoritative docs; it does not duplicate the full architecture.

If implementation evidence requires a legitimate architecture reopen, update only the smallest affected decision and name the contradiction. Do not silently let code drift away from the documents.

## 14. Pull Requests and Reporting

Unless the task explicitly says otherwise:

- use a dedicated branch;
- keep one migration Work Unit per PR where practical;
- do not merge or approve your own PR;
- report exact base SHA and final Head SHA;
- list changed files;
- list executed checks and exact outcomes;
- list checks not executed;
- identify residual risks/gaps.

Do not claim implementation, validation, PR creation, CI success, deployment, or migration completion without direct evidence.

## 15. Stop Conditions

Stop the current Work Unit and report instead of improvising if:

- required starting SHA does not match;
- current official Gravity Forms / Gravity Flow behavior contradicts a locked architecture decision;
- implementation requires background delivery despite the closed synchronous baseline;
- a legacy Rule cannot be migrated without guessing;
- a provider fails the Provider Contract;
- migration would require old and new real senders simultaneously;
- an external dependency/version is materially incompatible;
- tests reveal a contradiction requiring architecture reopening.

## 16. Definition of Done

For a Work Unit, done means:

```text
requested change implemented
+
product identity preserved
+
architecture invariants preserved
+
relevant tests/checks executed
+
results reported accurately
+
documentation synchronized
+
no unauthorized scope expansion
```

The desired end state is intentionally small:

```text
Native Gravity Forms Feed
+ Native Gravity Flow Feed Step
+ Recipient Resolver
+ Synchronous Dispatcher
+ Contract-gated SMS Providers
+ Bale fallback
+ Entry Meta state
+ Manual Retry
+ Point guidance/verification
+ GravityView/Elementor presentation
```

Do not rebuild the complexity this migration is intended to remove.
