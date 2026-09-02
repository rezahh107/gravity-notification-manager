# AGENTS.md

> Repository-wide instructions for coding agents working on `rezahh107/Gravityflow-SMS-Ippanel`.

## 1. Scope

This file applies to the entire repository unless a future nested `AGENTS.md` explicitly provides more specific instructions for a subdirectory.

Do not create nested `AGENTS.md` files unless a subdirectory genuinely requires different build, test, safety, or architectural rules.

---

## 2. Repository Purpose

This repository contains a personal/internal WordPress notification plugin integrating primarily with:

- Gravity Forms;
- Gravity Flow;
- SMS providers, initially IPPanel;
- Bale Bot API;
- GravityView and Elementor for selected operational presentation.

The refactor target intentionally favors:

1. native Gravity Forms / Gravity Flow mechanisms;
2. synchronous execution;
3. minimal infrastructure;
4. explicit workflow placement;
5. simple operational recovery;
6. maintainability on ordinary shared hosting.

Do not turn this plugin into a general workflow engine, queue platform, event bus, staff directory, or enterprise messaging framework.

---

## 3. Instruction and Architecture Authority

Before making any material code change, read:

1. `docs/TARGET_ARCHITECTURE.md`
2. `docs/MIGRATION_PLAN.md`
3. `docs/SALVAGE_REFERENCE.md`
4. this `AGENTS.md`
5. the current task / issue / implementation prompt
6. relevant target source and tests

The authority order for implementation is:

```text
Current explicit owner/task instruction
    ↓
docs/TARGET_ARCHITECTURE.md
    ↓
docs/MIGRATION_PLAN.md
    ↓
docs/SALVAGE_REFERENCE.md
    ↓
AGENTS.md
    ↓
current repository source/tests
    ↓
README.md and historical documentation
```

`docs/TARGET_ARCHITECTURE.md` is the decision-closed architecture contract.

`docs/MIGRATION_PLAN.md` is the approved greenfield implementation sequence and source-disposition plan.

`docs/SALVAGE_REFERENCE.md` is the authoritative allowlist/manifest for consulting or transplanting legacy implementation knowledge.

If `README.md`, legacy comments, old class names, or existing runtime behavior conflict with the target architecture, do not treat the legacy behavior as architectural authority.

At the inspected baseline, `README.md` still describes queue/Action Scheduler/retry behavior from the legacy implementation. That content is historical until updated during migration.

Do not reopen a closed architectural decision merely because another pattern appears cleaner or more sophisticated.

If direct implementation evidence contradicts a locked decision, stop only the affected work unit and report the contradiction under the Reopen Conditions in `docs/TARGET_ARCHITECTURE.md`.

---

## 4. Required Baseline Discipline

For work tied to the current migration plan, verify the required starting repository state before editing.

Initial greenfield implementation baseline:

```text
Repository: rezahh107/Gravityflow-SMS-Ippanel
Branch: main
SHA: 7556f86ecc65f37d34d9563ce2087f16235bbca5
```

Immutable legacy reference:

```text
Tag: legacy-source-pre-greenfield-2026-09-02
Commit: 7556f86ecc65f37d34d9563ce2087f16235bbca5
Mode: READ_ONLY_REFERENCE
```

Individual implementation prompts or work units may specify a newer exact base/head. Those explicit work-unit identities supersede the implementation baseline, but the immutable legacy tag does not move.

Never silently work from a different starting SHA when an exact SHA is required.

Before modifying code:

- inspect the current branch and HEAD;
- inspect the target files;
- inspect relevant call sites;
- inspect existing tests;
- identify whether the requested work unit depends on a previous migration work unit.

Do not infer that a file is dead merely from its name or from the migration plan. Prove that no target runtime call site still requires it before deletion.

---

## 5. Greenfield-by-Default Implementation Rule

The new core is written from the closed architecture, not by refactoring legacy classes in place.

Required sequence:

```text
TARGET_ARCHITECTURE.md
        ↓
design greenfield responsibility
        ↓
verify current official API/contract
        ↓
need old implementation knowledge?
        ↓
SALVAGE_REFERENCE.md
        ↓
inspect exact file at:
legacy-source-pre-greenfield-2026-09-02
        ↓
validate
        ↓
transplant smallest still-valid behavior
```

### 5.1 Legacy is not a dependency

Do not make new target classes depend on legacy classes merely to save time.

Do not subclass legacy components as the migration strategy.

Do not preserve legacy namespaces, event models, queue abstractions, Rule schemas, locking, or settings structures by inertia.

### 5.2 Allowed legacy modifications

Legacy runtime files may be modified only when a Work Unit specifically requires:

- controlled cutover/deactivation;
- settings/data extraction or migration;
- a narrowly proven compatibility shim;
- safe retirement/deletion.

Do not evolve `Dispatcher`, `Event_Queue`, `Listener`, `GravityForms_Handler`, `Sms_Sender`, `ProviderFactory`, or related legacy architecture into the new core.

### 5.3 Salvage rule

Legacy content is used only under one of the classifications in `docs/SALVAGE_REFERENCE.md`:

```text
REFERENCE_ONLY
TRANSPLANT_CANDIDATE
BEHAVIOR_REFERENCE
DATA_MIGRATION_ONLY
SELECTIVE_CONTENT_REUSE
RETIRE
```

If an asset is not listed there, do not salvage it without first updating the manifest under the authority rules.

Every transplant must be supported by:

- target need;
- current official-contract validation where applicable;
- focused tests.

---

## 6. Closed Architectural Invariants

All implementation work must preserve these invariants unless the architecture is formally reopened.

### 5.1 Workflow authority

Gravity Flow owns workflow topology:

- steps;
- ordering;
- routing;
- approvals;
- assignments;
- next-step decisions.

The plugin's Point Manager may guide and verify configuration, but must not silently insert, reorder, or mutate Gravity Flow workflow topology.

### 5.2 Notification Rule model

`GFFeedAddOn` Feed configuration is the canonical notification Rule mechanism.

Use native Gravity Forms feed settings and native feed conditional logic rather than maintaining a parallel custom Rule engine.

### 5.3 Gravity Flow execution

Workflow-position notifications use the current supported Gravity Flow compatible Feed-Step mechanism.

Do not assume that a custom `GFFeedAddOn` automatically becomes a Flow Step merely because it is a Feed Add-On. Use the supported Gravity Flow feed-step integration contract and verify the current official API when implementing decision-critical integration code.

### 5.4 Logical notification identity

One Step/Feed represents one logical notification.

Multiple recipients or fallback providers do not become separate workflow Steps merely because they are different delivery targets.

### 5.5 Synchronous delivery

The baseline notification execution path is synchronous.

Do not introduce notification delivery or retry dependencies on:

- Action Scheduler;
- WP-Cron;
- server cron;
- custom queues;
- background workers;
- delayed retry schedulers.

### 5.6 Workflow continuity

Provider failure must not indefinitely block the business workflow.

A notification Step records the delivery outcome and completes according to the target contract.

### 5.7 Delivery trade-off

Availability is preferred over strict exactly-once delivery.

Best-effort duplicate suppression is sufficient.

Do not add heavy atomic claiming, distributed locks, a custom delivery database, or exactly-once infrastructure unless the architecture is formally reopened.

### 5.8 Channels

Bale is a separate channel, not an SMS provider.

Target shape:

```text
Notification Rule
    ↓
Recipient Resolver
    ↓
Synchronous Dispatcher
    ├─ SMS → SmsProviderRegistry
    │          ├─ IPPanel
    │          └─ eligible future provider
    └─ Bale → Bale Bot API
```

### 5.9 Provider fallback

Fallback is capability-aware.

If a Rule requires a capability such as `pattern`, a provider that does not support it must be skipped.

Never silently transform:

```text
pattern → plain
```

or invent another semantic conversion just to force fallback.

### 5.10 Delivery state

Gravity Forms Entry Meta is the baseline delivery-state store.

Do not create a new custom delivery table as the new state authority without a formally reopened decision.

### 5.11 Manual Retry

Retry is explicit, manual, synchronous, capability-protected, and nonce-protected.

Manual Retry must not enqueue future work.

### 5.12 Staff contact metadata

Standard WordPress user meta keys are:

```text
wudm_notification_mobile
wudm_bale_chat_id
```

Read them directly via WordPress user-meta APIs.

Do not depend at runtime on WP-Bulk-Import classes.

Do not depend on `plato_user_mobile`.

Do not delete or claim ownership of the `wudm_*` fields during uninstall.

### 5.13 Presentation

For SRWF usage:

```text
Gravity Forms = Form / Entry data authority
Gravity Flow  = workflow / approval / assignment authority
GravityView   = presentation-only surface
Elementor     = presentation/layout surface
```

GravityView or Elementor must not become notification execution or workflow state authorities.

---

## 7. Legacy Runtime Warning

The initial baseline contains overlapping legacy send paths and infrastructure, including code in or around:

- `includes/Integration/Event_Queue.php`
- `includes/Integration/Listener.php`
- `includes/Integration/Dispatcher.php`
- `includes/Integration/GravityForms_Handler.php`
- `includes/Integration/Sms_Sender.php`
- `includes/Infrastructure/ProviderFactory.php`
- legacy logging/table lifecycle
- legacy locking/retry behavior

Treat these as **read-only legacy references**, not target implementation building blocks.

The canonical legacy source is `legacy-source-pre-greenfield-2026-09-02@7556f86ecc65f37d34d9563ce2087f16235bbca5`. Consult it only through the rules in `docs/SALVAGE_REFERENCE.md`.

The files may still be present on `main` before cutover, but presence in the working tree does not grant reuse authority.

### Critical cutover invariant

Never intentionally leave:

```text
legacy real sender
+
new real sender
```

active for the same logical notification scope.

A migration work unit must prove which runtime path is authoritative before enabling real delivery.

---

## 8. Coding Standards

### 7.1 PHP

Current Composer requirement:

```text
PHP >= 8.2
```

Use modern PHP supported by the repository runtime.

Preserve `declare(strict_types=1);` in PHP files where the project convention uses it.

Prefer:

- explicit types;
- focused classes;
- narrow interfaces;
- dependency injection where it materially improves testability;
- immutable/value-oriented data where appropriate;
- early validation at boundaries;
- WordPress-native APIs for WordPress concerns.

Avoid speculative abstraction.

Do not create interfaces or layers solely because a future use case might exist.

### 7.2 WordPress

Follow WordPress Coding Standards as configured by `phpcs.xml`.

Use WordPress APIs for:

- HTTP requests;
- user metadata;
- capabilities;
- nonces;
- sanitization;
- escaping;
- options;
- entry/admin integration where applicable.

For remote provider communication, prefer the WordPress HTTP API over raw cURL unless a directly proven requirement prevents it.

### 7.3 Gravity Forms / Gravity Flow

Prefer current official framework APIs and extension points.

Before relying on a Gravity Forms or Gravity Flow integration contract that is material to behavior:

- inspect the installed/current supported API or official documentation;
- do not reconstruct undocumented behavior from memory;
- avoid deprecated hooks when a current supported framework/API exists.

### 7.4 Naming

Use names that reflect architectural responsibilities.

Prefer concepts such as:

- `NotificationFeedAddOn`
- `SmsProviderRegistry`
- `RecipientResolver`
- `SynchronousNotificationDispatcher`
- `EntryMetaDeliveryStore`
- `BaleChannel`

Exact class names may differ if repository conventions justify it, but responsibilities must remain clear.

Do not preserve legacy names if they obscure the target responsibility.

---

## 9. Provider Integration Rules

A new SMS provider is eligible only when it has, at minimum:

- official/documented API behavior;
- HTTPS transport;
- defined authentication;
- WordPress HTTP API compatibility;
- explicit recipient/message request semantics;
- synchronous request/response behavior;
- operationally useful success/error distinction;
- no mandatory background worker for baseline delivery;
- at least one useful declared capability such as `plain` or `pattern`.

Provider-specific request details must remain behind the provider boundary.

Do not leak provider-specific settings or response semantics upward into:

- Point Manager;
- workflow topology;
- generic recipient resolution;
- generic Rule identity.

IPPanel is the initial primary provider.

Reuse validated IPPanel Edge behavior where correct, but conform it to the new provider contract.

---

## 10. Recipient Resolution Rules

Recipient resolution must stay generic.

Supported target sources include:

- Gravity Forms Entry field;
- fixed target;
- WordPress user;
- WordPress role;
- Gravity Flow assignee / step assignee where supported;
- fixed Bale target;
- user-based Bale target.

Do not introduce SRWF-specific recipient classes such as:

- `RegistrationOfficer`;
- `Accountant`;
- `Manager`.

Those are operational roles represented through WordPress/Gravity Flow identity and assignment.

Missing contact information is not a fatal workflow exception. Record/skip the unavailable destination and continue the valid configured fallback path.

---

## 11. Point Manager Rules

Point Manager is an orchestration/guidance surface, not configuration authority over workflow topology.

It should:

- inspect actual forms/workflows;
- show notification-related setup state;
- detect missing or materially misconfigured notification Feed Steps;
- provide precise instructions;
- link to the correct native Gravity Flow screen;
- provide `Check Again`;
- report evidence-based status.

It must not:

- insert Steps automatically;
- reorder Steps;
- rewrite Approval routing;
- silently repair workflow topology;
- become a second workflow editor.

---

## 12. Operational Status and Retry

Operational delivery state belongs to the Notification plugin.

Central presentation should use GravityView + Elementor where practical.

Gravity Forms Entry Detail must retain an independent small operational status/retry surface.

Do not build a second generic searchable dashboard if GravityView can present the Entry Meta state.

Retry endpoints/actions must:

- require appropriate capability;
- verify nonce;
- sanitize identifiers;
- run synchronously;
- record a new attempt;
- never schedule background work.

---

## 13. Security and Privacy

This is a personal/internal plugin; security work must be proportional rather than enterprise-heavy.

Still required:

- capability checks for privileged actions;
- nonces for state-changing admin actions;
- input sanitization;
- output escaping;
- no provider credentials in logs;
- no implicit external network request just because an admin page rendered;
- no public unauthenticated Retry action;
- no production credentials in tests or CI.

A Doctor/diagnostics page must be side-effect-free by default.

A real test send, if supported, must require a separate explicit user action and a clear destination.

---

## 14. Dependency and Package Discipline

The repository uses Composer.

Do not manually edit `composer.lock`.

If dependency changes are required:

1. explain why the existing dependency set cannot satisfy the work unit;
2. change `composer.json`;
3. update the lock through Composer;
4. inspect the dependency diff;
5. run relevant validation.

Do not add a dependency merely to avoid writing a small amount of straightforward code.

Do not add queue/scheduler packages for notification execution.

The repository currently commits `vendor/`; preserve or change that policy only under an explicit repository-maintenance work unit.

---

## 15. Setup and Validation Commands

From the repository root, use Composer scripts defined by the project.

Install/update the development environment only when needed:

```bash
composer install
```

Run relevant checks:

```bash
composer lint
composer stan
composer test
composer phpcompat
```

Equivalent underlying commands currently map to:

```text
lint      → phpcs --standard=phpcs.xml
stan      → phpstan analyse --memory-limit=2G
test      → phpunit
phpcompat → phpcs -p --standard=phpcompat.xml
```

If one of these checks cannot run because the environment or required product fixtures are unavailable, report:

```text
NOT_EXECUTED_ENVIRONMENT_UNAVAILABLE
```

Do not report a check as passing when it was not executed.

Do not use a formatter/fixer across unrelated files unless the task explicitly requires repository-wide formatting.

---

## 16. Testing Expectations

Every behavioral change should receive the narrowest useful regression coverage.

Prefer a layered test strategy:

```text
pure/unit tests
    ↓
provider HTTP fakes
    ↓
WordPress / Gravity Forms integration
    ↓
Gravity Flow workflow integration
```

Automated tests must not send real SMS or Bale messages.

Critical migration tests include:

- submission Feed behavior;
- Flow Feed-Step interception and execution;
- native conditional logic;
- provider capability gating;
- ordered synchronous fallback;
- `FAILED` vs `AMBIGUOUS` observability;
- recipient resolution;
- selected user-meta keys;
- Entry Meta persistence;
- Attention Required state;
- manual Retry;
- workflow continuation on delivery failure;
- absence of background notification scheduling;
- absence of dual legacy/new real senders.

For bug fixes, add a regression test that fails before the fix when practical.

For deletion/refactor work, test the behavior that proves the removed path is no longer required.

---

## 17. No-Background Validation

After notification-runtime migration, explicitly inspect for forbidden delivery scheduling.

Search for and review relevant use of:

```text
as_enqueue_async_action
as_schedule_*
wp_schedule_event
wp_schedule_single_event
wp_next_scheduled
cron
queue
retry scheduler
```

Not every occurrence is automatically forbidden; for example, an unrelated maintenance task may be separate from notification execution.

But no notification send/retry path may rely on those mechanisms in the target baseline.

Do not preserve legacy queue code merely because another maintenance cron exists.

---

## 18. External API Testing Rules

Never use production recipients or credentials in automated validation.

Use:

- fake HTTP clients;
- stub providers;
- deterministic fixtures;
- explicitly designated staging/test credentials only for controlled manual integration validation.

For ambiguous network outcomes, tests should preserve the distinction between:

```text
FAILED
AMBIGUOUS
```

The fallback chain may continue after either state under the Owner-approved duplicate-risk policy.

---

## 19. Change Scope Discipline

Implement the smallest coherent work unit in the greenfield target core.

Do not mix unrelated cleanup into a migration work unit.

Do not:

- redesign adjacent architecture;
- modernize unrelated code opportunistically;
- rename broad namespaces without need;
- reformat the repository;
- replace working provider logic solely for style;
- update unrelated dependencies;
- remove historical data without explicit migration/retention authority.

If a directly caused regression requires touching an adjacent file, keep the change narrow and document why it is required.

---

## 20. Documentation Discipline

When behavior changes, update the documentation that owns that behavior.

Do not duplicate the complete architecture inside README.

README should remain human-oriented and link to:

```text
docs/TARGET_ARCHITECTURE.md
docs/MIGRATION_PLAN.md
docs/SALVAGE_REFERENCE.md
```

If implementation requires a legitimate architecture reopen:

1. update the target decision first or in the same controlled change;
2. identify the exact reopened decision;
3. cite the contradicting evidence;
4. leave unaffected decisions locked;
5. update the migration plan if sequencing/disposition changes.

Do not silently let code drift away from the architecture documents.

---

## 21. Pull Request Discipline

Unless the task explicitly says otherwise:

- work on a dedicated branch;
- keep one migration work unit per PR where practical;
- do not merge your own PR;
- do not approve your own PR;
- do not broaden scope to “finish everything”;
- report exact base SHA and final Head SHA;
- list changed files;
- list executed checks and exact outcomes;
- list any checks not executed;
- identify residual risks or open gaps.

A PR is not complete merely because code was written.

Completion requires evidence appropriate to the work unit.

---

## 22. Before You Finish Any Implementation Task

Verify all applicable items:

- [ ] Read `docs/TARGET_ARCHITECTURE.md`.
- [ ] Read the relevant section(s) of `docs/MIGRATION_PLAN.md`.
- [ ] Read `docs/SALVAGE_REFERENCE.md` before consulting any legacy implementation.
- [ ] Confirm starting branch/SHA when required.
- [ ] Build from target responsibilities rather than reshaping legacy classes.
- [ ] If legacy was consulted, record exactly what was transplanted and why.
- [ ] Inspect all modified files and material call sites.
- [ ] Keep Gravity Flow as workflow authority.
- [ ] Do not add queue/Cron/background notification execution.
- [ ] Do not create dual legacy/new real sender paths.
- [ ] Preserve one Step/Feed per logical notification.
- [ ] Keep Bale separate from SMS providers.
- [ ] Apply provider capability gating.
- [ ] Use `wudm_notification_mobile` / `wudm_bale_chat_id` for selected staff user-meta contracts.
- [ ] Do not introduce WP-Bulk-Import runtime dependency.
- [ ] Keep Entry Meta as target delivery-state authority.
- [ ] Keep Retry manual and synchronous.
- [ ] Add/update regression tests for changed behavior.
- [ ] Run relevant Composer checks.
- [ ] Clearly report checks not executed.
- [ ] Update docs when behavior/contracts changed.
- [ ] Confirm no secrets or real test destinations entered the diff.
- [ ] Confirm the diff stayed inside the requested work unit.

---

## 23. Stop Conditions

Stop and report instead of improvising if:

- the required starting SHA does not match;
- current official Gravity Forms / Gravity Flow behavior contradicts a locked architecture decision;
- a requested implementation requires background delivery despite the closed synchronous baseline;
- a legacy Rule cannot be migrated without guessing;
- a provider fails the Provider Contract;
- migration would require old and new real senders simultaneously;
- an external dependency/version is materially incompatible;
- tests reveal a contradiction that requires architecture reopening.

Do not convert these conditions into hidden assumptions.

---

## 24. Definition of Done

For an implementation work unit, “done” means:

```text
requested change implemented
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

For the overall refactor, completion additionally requires the release criteria in `docs/MIGRATION_PLAN.md`.

---

## 25. Agent Reporting Format

At the end of implementation work, provide a compact evidence-backed report containing:

```text
Repository:
Base:
Branch:
Final Head:

Work Unit:
Result:

Changed Files:
- ...

Architecture Conformance:
- ...

Validation:
- command → PASS / FAIL / NOT_EXECUTED_ENVIRONMENT_UNAVAILABLE

Legacy Runtime Check:
- ...

Open Gaps:
- none | ...

PR:
- URL or NOT_CREATED
```

Do not claim:

- implementation;
- test execution;
- validation;
- PR creation;
- CI success;
- deployment;
- migration completion

without direct evidence.

---

## 26. Final Rule

Build the target core greenfield. Treat `legacy-source-pre-greenfield-2026-09-02` as a read-only reference and salvage only the bounded assets allowed by `docs/SALVAGE_REFERENCE.md`. Prefer deletion and simplification once the replacement path is proven.

The desired end state is intentionally smaller than the initial implementation:

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

Do not rebuild the complexity that this migration is intended to remove.
