# Gravity Notification Manager — Migration Plan

> **Document ID:** `GNM-MIGRATION-PLAN-1.3.0`  
> **Status:** `GREENFIELD_CORE_APPROVED`  
> **Plan date:** `2026-09-03`  
> **Repository:** `rezahh107/gravity-notification-manager`  
> **Product identity:** `docs/PRODUCT_IDENTITY.md`  
> **Target contract:** `docs/TARGET_ARCHITECTURE.md`  
> **Owner preference overlay:** `docs/OWNER_PREFERENCE_PROFILE.md`  
> **UI/UX reference:** `docs/UI_UX_REFERENCE.md`  
> **Legacy differential-review manifest:** `docs/SALVAGE_REFERENCE.md`  
> **Immutable legacy tag:** `legacy-source-pre-greenfield-2026-09-02`

## 1. Purpose and Authority

This plan moves the repository to the closed Gravity Notification Manager target architecture without reopening architectural decisions.

Authority order for implementation:

```text
PRODUCT_IDENTITY.md
→ TARGET_ARCHITECTURE.md
→ MIGRATION_PLAN.md
→ applicable project contracts/constraints
→ OWNER_PREFERENCE_PROFILE.md
→ UI_UX_REFERENCE.md for UI scope
→ SALVAGE_REFERENCE.md
→ AGENTS.md
→ current Work Unit instruction
```

This plan may refine sequencing, file/class boundaries, migration mechanics, tests, rollback, compatibility handling and implementation methodology. It may not independently reopen Feed-as-Rule, Feed-as-Flow-Step, synchronous delivery, Entry Meta state, manual Retry, Point Manager authority, GravityView/Elementor presentation, staff metadata, provider eligibility, or other closed architectural decisions.

## 2. Canonical Product Identity

```text
Product Name: Gravity Notification Manager
Repository: rezahh107/gravity-notification-manager
Plugin slug: gravity-notification-manager
Text domain: gravity-notification-manager
Greenfield PHP namespace: GravityNotify
```

The new core is authored directly under this identity.

Legacy identifiers are not renamed in place merely for cosmetics. They remain migration evidence until cutover/retirement.

## 3. Baseline and Legacy Identity

The original greenfield documentation baseline was created from the repository state after the legacy tag was frozen. Every implementation Work Unit must specify its own exact current base/head rather than relying on a stale remembered baseline.

Immutable legacy authority:

```text
tag: legacy-source-pre-greenfield-2026-09-02
commit: 7556f86ecc65f37d34d9563ce2087f16235bbca5
mode: READ_ONLY_REFERENCE
```

The legacy tag never moves.

The current `main` tree still contains legacy runtime until controlled cutover. Presence in `main` does not make legacy classes the implementation foundation for the new core.

## 4. Selected Migration Strategy

Selected strategy:

```text
DOCS-FIRST GREENFIELD IMPLEMENTATION
+
CURRENT OFFICIAL CONTRACT VERIFICATION
+
TARGET-FOCUSED TESTS
+
POST-IMPLEMENTATION LEGACY DIFFERENTIAL REVIEW
+
CURRENT-VALID FINDING INCORPORATION
+
EXACT-TARGET REVALIDATION
+
CONTROLLED CUTOVER
+
LEGACY RETIREMENT
```

The key change from the earlier salvage-first idea is intentional:

```text
DO NOT inspect equivalent legacy implementation first
        ↓
Build the target component independently from current official contracts
        ↓
Test it
        ↓
Only then inspect the equivalent old implementation
```

This minimizes architectural anchoring while preserving useful historical edge-case knowledge.

## 5. Per-Work-Unit Development Lifecycle

For each material greenfield Work Unit:

### 5.1 Current Contract Snapshot

Before implementation, record the current relevant baseline and official sources.

Template:

```text
Repository base SHA:
WordPress:
PHP:
Gravity Forms:
Gravity Flow:
GravityView:
Elementor:
IPPanel/Bale/current external contract:
Official docs inspected:
Inspection date:
```

Include only relevant products.

Rules:

- verify version-sensitive behavior against current official/primary documentation;
- prefer stable, officially supported, non-deprecated mechanisms compatible with the actual baseline;
- do not select beta/preview/experimental APIs simply because they are newer;
- do not rely on memory for mutable API contracts.

### 5.2 Greenfield implementation

Design from:

```text
TARGET_ARCHITECTURE.md
+
current official contracts
+
Owner preference / UI reference overlays where applicable
```

Do not derive target class boundaries from legacy classes.

### 5.3 Initial validation

Run target-focused tests with no real SMS/Bale send.

The new implementation should be understandable and testable without loading legacy architecture.

### 5.4 Post-Implementation Legacy Differential Review

After the initial implementation/test pass, inspect only equivalent, manifest-approved legacy assets from the immutable tag.

Question:

```text
Did legacy contain a material edge case, low-level behavior, request/response detail,
normalization rule, migration fact or user-facing wording the new implementation missed?
```

If no: record `NO_MATERIAL_FINDING`.

If yes:

1. validate the finding against current official behavior and target architecture;
2. reject stale/retired behavior;
3. incorporate only the smallest current-valid material value;
4. update tests;
5. re-run affected validation.

### 5.5 Exact-target qualification

Qualification claims must identify the exact final Head/artifact tested.

Do not report an earlier Head, substitute environment, static check or synthetic result as exact final-target PASS.

Use truthful states such as:

```text
PASS
FAIL
PARTIAL
NOT_RUN
NOT_EXECUTED_ENVIRONMENT_UNAVAILABLE
NOT_PROVEN
```

## 6. Complexity / Dependency Gate

Before adding infrastructure, abstraction, dependency or persistent state beyond the closed target, answer:

```text
What exact failure does this prevent?
What material benefit does it provide?
Can a current native/platform primitive satisfy the requirement?
Why is the simpler alternative insufficient?
What runtime/maintenance cost is added?
```

A weak or speculative answer is evidence against adding the machinery.

This gate cannot authorize an architecture reopen by itself.

## 7. Non-Negotiable Cutover Rule

At no point may production intentionally have:

```text
legacy real sender
+
new real sender
```

active for the same logical notification scope.

Before enabling greenfield real delivery for a migrated scope, disable the equivalent legacy sender for that scope.

Owner acceptance of rare provider/concurrency duplicates does not authorize migration-wide duplicates from two architectures.

## 8. Legacy Disposition Summary

All post-implementation legacy review is governed by `docs/SALVAGE_REFERENCE.md`.

### Retire; do not transplant architecture

- `includes/Integration/Event_Queue.php`
- `includes/Integration/Listener.php`
- legacy `Dispatcher` orchestration
- legacy direct `GravityForms_Handler` execution model
- `includes/Integration/Sms_Sender.php`
- `includes/Infrastructure/ProviderFactory.php`
- `includes/Integration/Secondary_Provider.php`
- heavy exactly-once `LockManager`
- legacy EventSnapshot/EventState/EventType pipeline
- custom Rule engine/condition system superseded by Feed settings
- delivery-log table as target state authority
- automatic retry/backoff/scheduler behavior

### Bounded differential-review candidates

- IPPanel Edge request/response knowledge
- WordPress HTTP adapter behavior
- phone normalization behavior
- pattern-variable processing knowledge
- message / Gravity Forms merge-tag knowledge
- assignee / user / role recipient resolution behavior
- still-relevant translation strings
- validated provider credential/settings values

No legacy asset is reused merely because it already exists.

## 9. Target Runtime Components

Exact class names may evolve inside Work Units, but responsibility boundaries remain approximately:

```text
GravityNotify Notification Feed Add-On
├─ Feed settings / Rule definition
├─ native Feed conditional logic
└─ process_feed()
        ↓
Recipient Resolver
        ↓
Synchronous Notification Dispatcher
        ├─ SMS Channel
        │   └─ SmsProviderRegistry
        │       ├─ IPPanelProvider
        │       └─ eligible future provider
        └─ Bale Channel
        ↓
EntryMetaDeliveryStore
```

Gravity Flow adds a narrow supported Feed-Step integration around the Feed Add-On.

Point Manager and operational admin surfaces are read/control surfaces over the same authoritative configuration/state; they are not alternative workflow/state authorities.

## 10. Admin UI Direction

The approved GNM admin information architecture is:

```text
Overview
Notification Points
Settings
Help & Diagnostics
```

Direction:

```text
EDIS UX grammar
+
current stable WordPress Design System
+
GNM-specific simplified IA
```

At the decision date, WordPress 7.1 provides the stable theming foundation (`wp-theme` design tokens and stable theming support) suitable for modern plugin-owned admin UI. Each UI Work Unit must re-check the current official WordPress contract.

The customizable Widget Dashboard remains experimental at the decision date and is **not** a production dependency.

Rules:

- use WordPress-native controls/APIs when sufficient;
- use stable design tokens where supported by the baseline;
- do not build a parallel custom design system merely for appearance;
- use React only when interaction complexity creates a material usability benefit;
- do not make GNM a SPA by default;
- scope admin assets to GNM screens whenever practical;
- important states use text + semantic cue, never color alone;
- preserve RTL/LTR correctness, accessibility and reduced-motion behavior;
- central case-level Attention Required remains GravityView + Elementor.

See `docs/UI_UX_REFERENCE.md`.

## 11. Work Unit Sequence

### WU-00 — Greenfield Development Baseline + No-Send Harness

Objective: establish a current implementation baseline and a safe test environment **without reading equivalent legacy implementation code**.

Required:

- verify exact current repository Head;
- create the initial Current Contract Snapshot;
- identify supported runtime/product versions relevant to the first implementation stage;
- reconcile/prepare Composer and test tooling only as needed for greenfield work;
- create deterministic fakes/stubs for SMS/Bale/HTTP boundaries;
- prove automated tests cannot contact real providers;
- establish canonical `GravityNotify` greenfield namespace/bootstrap/test skeleton as appropriate;
- document how future Work Units record exact-target qualification.

Forbidden:

- inspecting legacy IPPanel/recipient/rule implementation for design guidance;
- repairing legacy architecture;
- real provider calls;
- starting cutover.

Exit: greenfield code can be developed/tested without real external sends and without architectural dependence on legacy.

### WU-01 — Greenfield Gravity Forms Feed Foundation

Objective: create the target `GFFeedAddOn` notification Rule layer from scratch using current official Gravity Forms contracts.

Required:

- one Feed = one logical notification;
- Feed settings for message, recipient sources, channel/fallback requirements;
- native Feed conditional logic;
- synchronous processing boundary;
- schema/versioning for Feed metadata where needed;
- tests.

After initial tests: run the bounded legacy differential review for old GF/message/rule-related behavior. Incorporate only current-valid missing behavior.

Exit: Feed CRUD/configuration and condition behavior work without real provider calls.

### WU-02 — SMS Provider Contract, Registry, IPPanel, Bale

Objective: build the synchronous delivery transport layer from current official external/platform contracts.

Required:

- normalized SMS provider contract;
- capability declarations;
- contract-gated `SmsProviderRegistry`;
- greenfield IPPanel provider;
- WordPress HTTP transport seam;
- Bale as separate channel;
- attempt statuses `SUCCESS`, `FAILED`, `AMBIGUOUS`, `SKIPPED`;
- ordered fallback behavior;
- deterministic HTTP/provider tests.

After initial tests: inspect approved legacy IPPanel/HTTP assets for missed current-valid Edge request/response/error details.

Forbidden:

- copying `IPPanel_Provider.php` wholesale;
- Pattern→Plain auto-conversion;
- background retry;
- arbitrary undocumented providers.

### WU-03 — Recipient Resolver

Objective: build generic recipient resolution from current target/API contracts.

At minimum support:

- Entry field;
- fixed SMS target;
- WordPress user;
- WordPress role;
- Gravity Flow assignee / step assignee where current API permits;
- fixed Bale target;
- user-based Bale target.

Staff contact keys:

```text
wudm_notification_mobile
wudm_bale_chat_id
```

Do not depend on WP-Bulk-Import classes or `plato_user_mobile`.

After initial tests: inspect legacy resolver/normalizer only for missed current-valid behavior.

### WU-04 — Gravity Flow Feed-Step Integration and Synchronous Execution

Objective: make the greenfield Feed the authoritative workflow-position runtime mechanism using current official Gravity Flow integration contracts.

Required proof:

- submission Feed uses normal Feed lifecycle;
- Flow-assigned Feed is intercepted from ordinary submit execution where required by the supported contract;
- compatible Feed Step executes when workflow reaches it;
- native condition is respected;
- provider failure is recorded without stranding workflow;
- one Step/Feed = one logical notification.

After initial tests: differential-review legacy Flow triggering only for edge cases; never transplant listener→queue→dispatcher architecture.

### WU-05 — Entry Meta Delivery State and Manual Retry

Objective: create approved lightweight operational state and explicit recovery.

Required:

- namespaced/versioned Entry Meta schema;
- attempt history;
- final status;
- best-effort duplicate suppression;
- `attention_required` state;
- synchronous manual Retry;
- successful Retry resolves Attention Required;
- capability/nonce protections.

After initial tests: review legacy state/locking/log behavior only to identify edge cases; do not transplant heavy exactly-once or log-table authority.

### WU-06 — Modern GNM Admin Foundation + Point Manager

Objective: implement the purpose-built admin UI using `UI_UX_REFERENCE.md` and current stable WordPress UI contracts.

Required:

- GNM navigation: Overview / Notification Points / Settings / Help & Diagnostics;
- EDIS-inspired panel/card/status/action grammar;
- stable WordPress design tokens/components where supported;
- no production dependency on experimental Widget Dashboard;
- responsive/RTL/LTR/accessibility baseline;
- Point Manager inspection and configuration health;
- exact setup guidance;
- direct link to relevant Gravity Flow screen;
- `Check Again` verification;
- assets scoped to GNM screens where practical.

Forbidden:

- inserting/reordering Flow Steps;
- changing Approval routing;
- silent topology repair;
- SPA/framework expansion without material UX benefit;
- copying EDIS job/export IA.

After initial implementation/tests: legacy admin UI may be reviewed only for surviving setting labels/migration obligations; EDIS remains the approved visual/UX reference.

### WU-07 — Attention Required and Entry Detail Surfaces

Plugin owns:

- Entry Meta state;
- Retry action;
- GF Entry Detail status/Retry surface;
- bounded aggregate/shortcut information in GNM Overview if useful.

Presentation owns:

- GravityView filtered Attention View;
- Elementor case layout/styling.

GNM admin must not become a duplicate case-management state store/dashboard.

### WU-08 — Settings/Data Migration and Controlled Cutover

This Work Unit may inspect legacy first because the migration target is the old state itself.

Required:

- inventory valid provider credentials/global settings;
- migrate only required values;
- map legacy Rules to new Feeds only when deterministic;
- produce manual guidance for ambiguous mappings;
- configure/verify required Flow Feed Steps;
- disable equivalent legacy sender before enabling greenfield sender for each scope.

Do not guess ambiguous mappings.

### WU-09 — Legacy Runtime Retirement

After greenfield cutover is proven, retire/remove:

- Event Queue;
- Action Scheduler notification path;
- WP-Cron notification fallback;
- legacy Flow listener/dispatcher delivery path;
- direct GF handler sender;
- duplicate sender;
- old automatic retry/backoff;
- obsolete heavy delivery locks;
- custom Rule engine;
- obsolete provider factory;
- obsolete delivery log UI/table lifecycle when no rollback/history need remains.

Do not delete based on filename alone. Prove zero target call sites first.

### WU-10 — Lifecycle, Diagnostics, Compatibility, Documentation, Release Validation

Required:

- remove obsolete scheduled notification hooks;
- make diagnostics side-effect-free by default;
- explicit/manual test-send only;
- reconcile plugin header/Composer/runtime requirements;
- verify dependency compatibility;
- secret-safe logging;
- safe uninstall policy;
- never delete external `wudm_*` staff metadata;
- update README/docs to actual runtime;
- run final regression matrix on exact final Head;
- record final current-contract snapshot and any environment limitations.

## 12. Data Migration Rules

### 12.1 Provider settings

Migrate valid provider credential/settings **values** only. This does not authorize preserving legacy settings architecture.

Never expose credentials in reports or diffs.

### 12.2 Legacy Rules

Convert a legacy Rule to a Feed only when mapping is deterministic.

If mapping requires guessing:

```text
DO NOT GUESS
→ report
→ provide manual setup guidance
```

### 12.3 Historical logging

Historical legacy log data may remain temporarily read-only if useful for rollback/history, but greenfield runtime must not depend on it.

### 12.4 Staff metadata

Do not own/delete:

```text
wudm_notification_mobile
wudm_bale_chat_id
```

Gravity Notification Manager is a consumer of these user-meta contracts.

## 13. Regression Matrix

Final validation must cover at minimum:

### Feed / Flow

- submission Feed executes correctly;
- Flow-assigned Feed does not independently send at submit where the supported integration requires interception;
- Flow Feed Step executes when reached;
- disabled/conditional Feed behavior;
- notification failure does not strand workflow.

### Provider chain

- primary success stops fallback;
- primary failure invokes secondary;
- primary ambiguous result may invoke secondary;
- incompatible secondary capability is skipped;
- secondary success stops Bale;
- unresolved SMS invokes Bale;
- unresolved Bale sets Attention Required.

### Recipient resolution

- Entry field;
- fixed recipient;
- WordPress user;
- WordPress role;
- Gravity Flow assignee;
- selected staff SMS/Bale meta;
- missing contacts.

### Delivery state / Retry

- `SUCCESS`, `FAILED`, `AMBIGUOUS`, `SKIPPED`;
- Attention Required;
- attempt history;
- best-effort sequential duplicate suppression;
- capability + nonce protected manual Retry;
- successful Retry resolves attention.

### No-background invariant

Normal notification execution/retry must not invoke:

- Action Scheduler enqueue;
- WP-Cron send scheduling;
- custom queue;
- worker.

### Admin UI / UX

- Overview/Notification Points/Settings/Help & Diagnostics routes render as intended;
- important states remain understandable without color alone;
- Point Manager `Check Again` verifies actual configuration;
- diagnostics render without external send;
- admin assets are not globally enqueued without need;
- no production dependency on experimental Widget Dashboard contract;
- central case-level Attention Required remains GravityView/Elementor-driven.

### Migration

- secrets are not logged;
- legacy/new real senders never overlap for migrated scope;
- legacy notification scheduling hooks are absent after retirement.

## 14. Validation Environments

Use layered validation:

```text
static checks
→ unit/provider fakes
→ WordPress + Gravity Forms integration
→ Gravity Flow workflow integration
→ admin UI interaction/accessibility checks
→ staging with non-production test destinations
→ controlled production cutover
```

Automated tests MUST NOT contact real SMS/Bale providers.

Production credentials MUST NOT be used in CI.

## 15. Rollback

Before legacy retirement, rollback may be operational:

```text
disable greenfield sender for affected scope
→ restore legacy sender for that scope
```

Never leave both enabled.

After legacy removal, rollback is release/Git based: deploy the last known-good package/commit.

Do not retain dual runtime indefinitely just to make rollback easier.

## 16. Stop Conditions

Stop the affected Work Unit instead of improvising when:

- required starting SHA does not match;
- current official Gravity Forms/Gravity Flow/WordPress contract contradicts the selected mechanism;
- implementation would require legacy/new real sender overlap;
- legacy Rule cannot be mapped without guessing;
- provider fails the Provider Contract;
- proposed change requires Cron/queue/background notification execution;
- a production-critical UI path requires an experimental WordPress API without explicit Owner reopen/approval;
- implementation evidence requires architecture reopening.

## 17. Release Completion Criteria

The greenfield migration is complete only when:

- product identity is consistently `Gravity Notification Manager` / `gravity-notification-manager` / `GravityNotify`;
- Feed Add-On owns Rule configuration;
- Flow Feed Step owns workflow-position execution;
- Point Manager guides/verifies but does not mutate topology;
- one Feed/Step = one logical notification;
- baseline delivery is synchronous;
- no background notification execution/retry remains;
- ordered SMS failover works;
- Bale fallback works;
- provider capability gate works;
- Entry Meta is active delivery state;
- Attention Required is queryable/presentable;
- manual Retry works;
- staff contacts use selected user meta;
- modern GNM admin surfaces conform to `UI_UX_REFERENCE.md`;
- GravityView/Elementor present central case-level Attention view;
- Entry Detail provides operational fallback;
- legacy duplicate send paths are removed;
- diagnostics have no implicit send side effects;
- runtime/dependency metadata is coherent;
- documentation matches actual runtime;
- post-implementation legacy differential reviews are recorded for applicable Work Units;
- final regression checks are run against the exact final PR Head/artifact to the claimed level.

## 18. Official Implementation References

Re-check current official contracts during affected Work Units. The URLs below are starting points, not frozen version proof:

- Gravity Forms `GFFeedAddOn`: https://docs.gravityforms.com/gffeedaddon/
- Gravity Flow feed integrations: https://docs.gravityflow.io/category/integrations/
- Gravity Flow Feed Add-On Step: https://docs.gravityflow.io/step_feed_class/
- Gravity Flow Step class: https://docs.gravityflow.io/step-class/
- WordPress HTTP API: https://developer.wordpress.org/reference/functions/wp_remote_post/
- WordPress User Metadata: https://developer.wordpress.org/plugins/users/working-with-user-metadata/
- WordPress Design System theming / 7.1 dev note: https://make.wordpress.org/core/2026/07/31/design-system-theming-in-wordpress-7-1/
- WordPress Developer Blog Dashboard experiment status: https://developer.wordpress.org/news/2026/06/whats-new-for-developers-june-2026/
- GravityView Elementor integration: https://www.gravitykit.com/docs/gravityview-pro/advanced-elementor-widget/
- IPPanel Edge API: https://ippanelcom.github.io/Edge-Document/
- Bale Bot API: https://docs.bale.ai/

## 19. Final Migration Statement

This is a **docs-first greenfield rewrite of the target core**, not a class-by-class refactor of the legacy runtime.

```text
CLOSED TARGET + CURRENT OFFICIAL CONTRACTS
        ↓
GREENFIELD IMPLEMENTATION
        ↓
TARGET TESTS
        ↓
POST-IMPLEMENTATION LEGACY DIFFERENTIAL REVIEW
        ↓
CURRENT-VALID MATERIAL FINDINGS ONLY
        ↓
REVALIDATE EXACT TARGET
        ↓
CONTROLLED CUTOVER
        ↓
RETIRE LEGACY
```

Default answer to “should we evolve this legacy class into the target?” is **no**.