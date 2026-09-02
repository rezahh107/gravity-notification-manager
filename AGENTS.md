# AGENTS.md

> Repository-wide instructions for coding agents working on `rezahh107/gravity-notification-manager`.

## 1. Read First

Before any material implementation change, read in this order:

1. `docs/PRODUCT_IDENTITY.md`
2. `docs/TARGET_ARCHITECTURE.md`
3. `docs/MIGRATION_PLAN.md`
4. `docs/OWNER_PREFERENCE_PROFILE.md`
5. `docs/UI_UX_REFERENCE.md` when the Work Unit touches admin/UI/UX
6. `docs/SALVAGE_REFERENCE.md`
7. this `AGENTS.md`
8. the current task / Work Unit instruction
9. relevant target source and tests

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
applicable project contracts/constraints
    ↓
docs/OWNER_PREFERENCE_PROFILE.md
    ↓
docs/UI_UX_REFERENCE.md (UI scope only)
    ↓
docs/SALVAGE_REFERENCE.md
    ↓
AGENTS.md
    ↓
current source/tests
```

Do not treat legacy runtime behavior, README history, old class names, old comments, preference-repository visibility, or UI reference code as architecture authority.

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

## 3. Greenfield Implementation Method

The new core is written from the closed target architecture and current official contracts, not from legacy implementation shape.

### 3.1 Required Work Unit sequence

For every version-sensitive or material implementation Work Unit:

```text
TARGET_ARCHITECTURE.md
        ↓
Current Contract Snapshot
        ↓
design target responsibility from scratch
        ↓
implement greenfield
        ↓
target-focused tests
        ↓
Post-Implementation Legacy Differential Review
        ↓
validate any useful legacy finding against current official contract
        ↓
incorporate only current-valid material value
        ↓
re-run affected tests
        ↓
exact-target / exact-Head qualification
```

Legacy is deliberately reviewed **after the initial greenfield implementation and tests** so old architecture does not anchor the new design.

### 3.2 Current Contract Snapshot

Before implementing a version-sensitive integration, record the current relevant baseline and official sources, for example:

```text
Repository base/head:
WordPress:
PHP:
Gravity Forms:
Gravity Flow:
GravityView:
Elementor:
IPPanel/Bale contract version or current docs:
Official documentation inspected:
Inspection date:
```

Only include products relevant to the Work Unit.

Do not assume a remembered version/API remains current.

Prefer current **stable, officially supported, non-deprecated** APIs compatible with the actual project baseline. Newest beta/preview/experimental is not automatically preferred.

### 3.3 Legacy review boundary

Immutable legacy source:

```text
tag: legacy-source-pre-greenfield-2026-09-02
commit: 7556f86ecc65f37d34d9563ce2087f16235bbca5
mode: READ_ONLY_REFERENCE
```

Do not create an in-tree `legacy/` copy.

For normal greenfield component Work Units, do **not** inspect corresponding legacy implementation before the first implementation/test pass.

Post-implementation review asks only:

```text
Did the old implementation handle a material edge case or low-level behavior the new implementation missed?
```

If yes:

1. validate the finding against current official behavior/target contract;
2. incorporate the smallest useful current-valid behavior;
3. re-run relevant tests.

Legacy may prove how the old plugin behaved. It cannot prove current correctness or target authority.

Exceptions: cutover, data migration, retirement, or exact legacy-state inventory Work Units may inspect legacy first because their target is the legacy boundary itself.

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
16. GravityView + Elementor provide central case-level Attention Required presentation; they do not own workflow or delivery state.
17. Gravity Forms Entry Detail retains a small operational status/manual-Retry fallback.
18. Never intentionally leave legacy and greenfield real senders active for the same logical notification scope.
19. GNM admin UI uses a small purpose-built information architecture; it is not a duplicate case-management dashboard.
20. Production admin UI does not depend on the experimental customizable WordPress Widget Dashboard API while that API remains experimental.

## 5. Owner Preference Overlay

`docs/OWNER_PREFERENCE_PROFILE.md` applies the accepted Owner preferences relevant to this repository.

Key project effects:

- correctness and mandatory behavior are hard gates;
- after correctness, prefer minimum sufficient complexity;
- native/platform primitives are preferred when sufficient;
- automation must reduce error/time/repetition while preserving control and observable outcomes;
- material decisions are frozen after approval;
- qualification must match exact evidence;
- UI must expose status/failure/next action clearly;
- consequential rule changes receive proportional governance, not maximum formalism.

### 5.1 Complexity Justification Gate

Before adding a new infrastructure layer, persistent store, abstraction, dependency, framework, scheduler, queue, or broad UI subsystem beyond the closed target, record:

```text
Proposed machinery:
Target requirement:
Named failure prevented / material benefit:
Native/platform alternative checked:
Why simpler alternative is insufficient:
Runtime/maintenance cost:
Decision:
```

If there is no named failure prevented or material benefit, prefer the simpler design.

This gate cannot self-authorize an architecture reopen.

## 6. Provider Rules

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

IPPanel is the initial primary provider. Implement against the new contract first, then perform the bounded legacy differential review defined in `SALVAGE_REFERENCE.md`.

## 7. Recipient Rules

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

## 8. Operational Status and Retry

Operational delivery state belongs to Gravity Notification Manager.

Central case-level Attention Required presentation uses GravityView + Elementor where practical.

Gravity Forms Entry Detail remains independently usable for status and Retry.

Retry actions must:

- require appropriate capability;
- verify nonce;
- sanitize identifiers;
- execute immediately/synchronously;
- record a new attempt;
- never schedule background work.

Important states must not rely on color alone.

## 9. Admin UI / UX Contract

Read `docs/UI_UX_REFERENCE.md` for any Work Unit touching admin presentation.

Approved information architecture:

```text
Gravity Notification Manager
├─ Overview
├─ Notification Points
├─ Settings
└─ Help & Diagnostics
```

The visual/interaction direction is:

```text
EDIS UX grammar
+
current stable WordPress Design System
+
GNM-specific simplified IA
```

### 9.1 Stable WordPress design system first

When supported by the actual WordPress baseline, prefer current stable WordPress design-system primitives/tokens, including the `wp-theme` design-token stylesheet and stable public theming/component APIs.

Do not hard-code a parallel design system where stable WordPress semantic tokens satisfy the need.

### 9.2 Experimental Dashboard boundary

The experimental customizable Widget Dashboard is **not** a production dependency unless a future official stable extension API is explicitly adopted.

GNM may look like the modern Dashboard generation without depending on its experimental rendering/registration contract.

### 9.3 EDIS reference boundary

Use EDIS as a visual/UX reference for:

- clear panels/cards;
- summary stats;
- semantic badges;
- definition lists;
- clear action hierarchy;
- empty states;
- responsive grid behavior;
- RTL/LTR handling;
- reduced-motion care.

Do not copy EDIS export/job/preflight/diagnostic-heavy information architecture.

### 9.4 React boundary

GNM is not a SPA by default.

Use PHP/native admin rendering when sufficient. Use React/WordPress component packages only when interaction complexity creates a material usability benefit.

Do not introduce React solely for modern appearance.

Admin assets should load only on relevant GNM screens whenever practical.

## 10. WordPress / Gravity Forms / Gravity Flow Standards

Use current official supported APIs and extension points.

Prefer WordPress APIs for HTTP, user metadata, capabilities, nonces, sanitization, escaping, options, scripts/styles and related platform primitives.

Prefer WordPress HTTP API over raw cURL unless a proven requirement prevents it.

Before implementing a material Gravity Forms / Gravity Flow integration contract, verify current official API/documentation. Do not reconstruct undocumented behavior from memory or legacy code.

Use native Gravity Forms Feed settings, merge tags, and conditional logic before writing custom equivalents.

## 11. PHP and Dependency Discipline

Current repository Composer requirement is PHP `>=8.2` until the greenfield package metadata is intentionally replaced after a Current Contract Snapshot.

Use strict types where consistent with target code.

Prefer explicit types, focused classes, narrow interfaces, dependency injection when it materially improves testability, and early validation at boundaries.

Avoid speculative abstractions.

Do not manually edit `composer.lock`.

Do not add queue/scheduler packages for notification execution.

## 12. Testing

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
- absence of dual legacy/new real senders;
- Point Manager configuration verification;
- important admin status states remain understandable without color alone.

Run relevant Composer scripts when available:

```bash
composer lint
composer stan
composer test
composer phpcompat
```

If a check cannot run because the environment or required product fixtures are unavailable, report `NOT_EXECUTED_ENVIRONMENT_UNAVAILABLE`. Never report an unexecuted check as passing.

## 13. Security / Privacy Boundary

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

Diagnostic surfaces are side-effect-free by default.

A real test-send, if provided, must be an explicit user action and must make the external-send consequence clear.

## 14. Change Scope

Implement the smallest coherent Work Unit.

Do not mix unrelated cleanup, broad namespace churn, repository-wide formatting, unrelated dependency updates, or adjacent architecture redesign into a bounded Work Unit.

For any legacy differential review that materially changes code, report:

```text
Legacy asset/tag/path:
New implementation already present:
Useful legacy finding:
Current official contract checked:
Exact behavior incorporated:
Legacy behavior intentionally NOT incorporated:
Tests added/updated:
Result:
```

## 15. Exact Artifact Qualification

Before declaring a Work Unit/PR qualified:

- identify exact base SHA;
- identify exact final Head SHA;
- run the checks required for the claim against that exact target where feasible;
- distinguish unit/static/synthetic evidence from integrated/runtime proof;
- report skipped/unavailable checks truthfully.

Do not infer final-Head PASS from an earlier Head.

## 16. Documentation

README is human-oriented and points to authoritative docs; it does not duplicate the full architecture.

If implementation evidence requires a legitimate architecture reopen, update only the smallest affected decision and name the contradiction. Do not silently let code drift away from documents.

Preference/UI reference updates do not automatically reopen runtime architecture.

## 17. Pull Requests and Reporting

Unless the task explicitly says otherwise:

- use a dedicated branch;
- keep one migration Work Unit per PR where practical;
- do not merge or approve your own PR;
- report exact base SHA and final Head SHA;
- list changed files;
- list executed checks and exact outcomes;
- list checks not executed;
- identify residual risks/gaps;
- include Current Contract Snapshot for version-sensitive Work Units;
- include Legacy Differential Review result after implementation where applicable.

Do not claim implementation, validation, PR creation, CI success, deployment, or migration completion without direct evidence.

## 18. Stop Conditions

Stop the affected Work Unit and report instead of improvising if:

- required starting SHA does not match;
- current official Gravity Forms / Gravity Flow / WordPress behavior contradicts a locked architecture decision;
- implementation requires background delivery despite the closed synchronous baseline;
- migration would require old and new real senders simultaneously;
- a legacy Rule cannot be migrated without guessing;
- a provider fails the Provider Contract;
- a proposed dependency/framework requires experimental APIs for a production-critical path without explicit Owner reopen/approval;
- tests reveal a contradiction requiring architecture reopening.

## 19. Definition of Done

For a Work Unit, done means:

```text
Current Contract Snapshot completed where applicable
+
requested greenfield change implemented
+
target-focused tests run
+
post-implementation Legacy Differential Review completed where applicable
+
current-valid useful findings incorporated or explicitly rejected
+
affected tests re-run
+
product identity preserved
+
architecture invariants preserved
+
exact-target qualification reported truthfully
+
documentation synchronized
+
no unauthorized scope expansion
```

The desired end state remains intentionally small:

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
+ modern WordPress-native GNM admin UI
+ GravityView/Elementor Attention presentation
```

Do not rebuild the complexity this migration is intended to remove.