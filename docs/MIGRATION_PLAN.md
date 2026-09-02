# Gravity Notification Manager — Migration Plan

> **Document ID:** `GNM-MIGRATION-PLAN-1.2.0`  
> **Status:** `GREENFIELD_CORE_APPROVED`  
> **Plan date:** `2026-09-02`  
> **Repository:** `rezahh107/gravity-notification-manager`  
> **Product identity:** `docs/PRODUCT_IDENTITY.md`  
> **Target contract:** `docs/TARGET_ARCHITECTURE.md`  
> **Legacy manifest:** `docs/SALVAGE_REFERENCE.md`  
> **Immutable legacy tag:** `legacy-source-pre-greenfield-2026-09-02`

## 1. Purpose and Authority

This plan moves the repository to the closed Gravity Notification Manager target architecture without reopening architectural decisions.

Authority order for implementation:

```text
PRODUCT_IDENTITY.md
→ TARGET_ARCHITECTURE.md
→ MIGRATION_PLAN.md
→ SALVAGE_REFERENCE.md
→ AGENTS.md
→ current work-unit instruction
```

This plan may refine sequencing, file/class boundaries, migration mechanics, tests, rollback, and compatibility handling. It may not independently reopen Feed-as-Rule, Feed-as-Flow-Step, synchronous delivery, Entry Meta state, manual Retry, Point Manager authority, GravityView/Elementor presentation, staff metadata, provider eligibility, or other closed architectural decisions.

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

Initial greenfield implementation baseline:

```text
main@7556f86ecc65f37d34d9563ce2087f16235bbca5
```

Immutable legacy authority:

```text
tag: legacy-source-pre-greenfield-2026-09-02
commit: 7556f86ecc65f37d34d9563ce2087f16235bbca5
mode: READ_ONLY_REFERENCE
```

Later Work Units may specify a newer exact base/head. The legacy tag never moves.

The current `main` tree still contains legacy runtime until controlled cutover. Presence in `main` does not make legacy classes the implementation foundation for the new core.

## 4. Migration Strategy

Selected strategy:

```text
GREENFIELD CORE
+
READ-ONLY LEGACY REFERENCE
+
ON-DEMAND VALIDATED TRANSPLANT
+
CONTROLLED CUTOVER
+
LEGACY RETIREMENT
```

For each target responsibility:

```text
1. Start from TARGET_ARCHITECTURE.md.
2. Verify current official product/API contracts.
3. Design the new responsibility/class from scratch.
4. Consult SALVAGE_REFERENCE.md only if old implementation knowledge is useful.
5. Inspect the exact file from the immutable legacy tag.
6. Transplant only the smallest still-valid behavior/data.
7. Add target-focused tests.
```

Legacy code must not dictate new:

- class boundaries;
- execution topology;
- Rule storage;
- queue/retry semantics;
- locking model;
- event taxonomy;
- provider capability model;
- admin information architecture.

## 5. Non-Negotiable Cutover Rule

At no point may production intentionally have:

```text
legacy real sender
+
new real sender
```

active for the same logical notification scope.

Before enabling greenfield real delivery for a migrated scope, disable the equivalent legacy sender for that scope.

Owner acceptance of rare provider/concurrency duplicates does not authorize migration-wide duplicates from two architectures.

## 6. Legacy Disposition Summary

All legacy consultation is governed by `docs/SALVAGE_REFERENCE.md`.

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

### Bounded salvage candidates

- IPPanel Edge request/response knowledge
- WordPress HTTP adapter behavior
- phone normalization behavior
- pattern-variable processing knowledge
- message / Gravity Forms merge-tag knowledge
- assignee / user / role recipient resolution behavior
- still-relevant translation strings
- validated provider credential/settings values

No legacy asset is reused merely because it already exists.

## 7. Target Runtime Components

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

Point Manager and Attention Required are read/control surfaces over the same authoritative configuration/state; they are not alternative authorities.

## 8. Work Unit Sequence

### WU-00 — Greenfield Safety Baseline and Minimal Legacy Asset Capture

Objective: establish a no-real-send test harness and capture only facts necessary for safe replacement.

Required:

- verify exact repository Head;
- enumerate current real-send entry points so cutover can disable them;
- identify settings/options/data requiring migration;
- configure provider fakes/stubs;
- inspect only salvage-listed legacy assets;
- capture target-relevant behavior as tests where practical.

Forbidden:

- repairing legacy architecture;
- real provider calls;
- deleting active legacy paths before replacement proof.

Exit: all real send paths are known and tests can detect a send attempt without contacting providers.

### WU-01 — Greenfield Gravity Forms Feed Foundation

Objective: create the target `GFFeedAddOn` notification Rule layer from scratch.

Required:

- canonical product identity/namespace;
- one Feed = one logical notification;
- Feed settings for message, recipients, channel/fallback requirements;
- native Feed conditional logic;
- synchronous processing baseline;
- schema/versioning for Feed metadata;
- tests.

Do not derive class structure from legacy `GravityForms_Handler` or custom Rule schema.

Exit: Feed CRUD/configuration and condition behavior work without real provider calls.

### WU-02 — SMS Provider Contract, Registry, IPPanel, Bale

Objective: build the synchronous delivery transport layer.

Required:

- normalized SMS provider contract;
- capability declarations;
- contract-gated `SmsProviderRegistry`;
- greenfield IPPanel provider using only validated Edge salvage;
- WordPress HTTP transport seam;
- Bale as separate channel;
- attempt statuses `SUCCESS`, `FAILED`, `AMBIGUOUS`, `SKIPPED`;
- ordered fallback behavior.

Forbidden:

- Pattern→Plain auto-conversion;
- background retry;
- arbitrary undocumented providers.

Exit tests prove primary success/secondary fallback/incompatible skip/Bale fallback/all-fail behavior.

### WU-03 — Recipient Resolver

Objective: build generic recipient resolution from the target contract.

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

Exit: tests prove user/role/assignee resolution and graceful missing-contact behavior.

### WU-04 — Gravity Flow Feed-Step Integration and Synchronous Execution

Objective: make the greenfield Feed the authoritative runtime mechanism.

Required proof:

- submission Feed uses normal Feed lifecycle;
- Flow-assigned Feed is intercepted from ordinary submit execution;
- compatible Feed Step executes when workflow reaches it;
- native condition is respected;
- provider failure is recorded without stranding the workflow;
- one Step/Feed = one logical notification.

Forbidden: recreating legacy Flow lifecycle-hook → queue → dispatcher architecture.

### WU-05 — Entry Meta Delivery State and Manual Retry

Objective: create approved lightweight operational state.

Required:

- namespaced/versioned Entry Meta schema;
- attempt history;
- final status;
- best-effort duplicate suppression;
- `attention_required` state;
- synchronous manual Retry;
- successful Retry resolves Attention Required.

Forbidden:

- target delivery DB as SSOT;
- atomic claim subsystem;
- scheduled Retry.

### WU-06 — Point Manager Guidance and Verification

Objective: make setup difficult to misconfigure without mutating Flow topology.

Required:

- inspect actual forms/workflows;
- show configuration health;
- detect missing/misconfigured Notification Feed Steps;
- exact setup instructions;
- direct link to relevant Gravity Flow screen;
- official help link where useful;
- `Check Again` read-back verification.

Forbidden:

- inserting/reordering Steps;
- changing Approval routing;
- silent topology repair.

### WU-07 — Attention Required Surfaces

Plugin owns:

- Entry Meta state;
- Retry action;
- GF Entry Detail status/Retry surface;
- bounded reusable Retry control if needed.

Presentation owns:

- GravityView filtered Attention View;
- Elementor layout/styling.

No duplicate status store or custom generic dashboard subsystem.

### WU-08 — Settings/Data Migration and Controlled Cutover

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
- final regression matrix.

## 9. Data Migration Rules

### 9.1 Provider settings

Migrate valid provider credential/settings **values** only. This does not authorize preserving legacy settings architecture.

Never expose credentials in reports or diffs.

### 9.2 Legacy Rules

Convert a legacy Rule to a Feed only when mapping is deterministic.

If mapping requires guessing:

```text
DO NOT GUESS
→ report
→ provide manual setup guidance
```

### 9.3 Historical logging

Historical legacy log data may remain temporarily read-only if useful for rollback/history, but the greenfield runtime must not depend on it.

### 9.4 Staff metadata

Do not own/delete:

```text
wudm_notification_mobile
wudm_bale_chat_id
```

Gravity Notification Manager is a consumer of these user-meta contracts.

## 10. Regression Matrix

Final validation must cover at minimum:

### Feed / Flow

- submission Feed executes correctly;
- Flow-assigned Feed does not independently send at submit;
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

### Diagnostics / migration

- opening/rendering diagnostics sends nothing;
- explicit test-send is separately authorized;
- secrets are not logged;
- legacy/new real senders never overlap for migrated scope;
- legacy notification scheduling hooks are absent after retirement.

## 11. Validation Environments

Use layered validation:

```text
static checks
→ unit/provider fakes
→ WordPress + Gravity Forms integration
→ Gravity Flow workflow integration
→ staging with non-production test destinations
→ controlled production cutover
```

Automated tests MUST NOT contact real SMS/Bale providers.

Production credentials MUST NOT be used in CI.

## 12. Rollback

Before legacy retirement, rollback may be operational:

```text
disable greenfield sender for affected scope
→ restore legacy sender for that scope
```

Never leave both enabled.

After legacy removal, rollback is release/Git based: deploy the last known-good package/commit.

Do not retain dual runtime indefinitely just to make rollback easier.

## 13. Stop Conditions

Stop the affected Work Unit instead of improvising when:

- required starting SHA does not match;
- current official Gravity Forms/Gravity Flow contract contradicts the selected mechanism;
- implementation would require legacy/new real sender overlap;
- legacy Rule cannot be mapped without guessing;
- provider fails the Provider Contract;
- proposed change requires Cron/queue/background notification execution;
- implementation evidence requires architecture reopening.

## 14. Release Completion Criteria

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
- GravityView/Elementor can present central Attention view;
- Entry Detail provides operational fallback;
- legacy duplicate send paths are removed;
- diagnostics have no implicit send side effects;
- runtime/dependency metadata is coherent;
- documentation matches actual runtime;
- final regression checks pass against the final PR Head.

## 15. Official Implementation References

Re-check current official contracts during affected Work Units:

- Gravity Forms `GFFeedAddOn`: https://docs.gravityforms.com/gffeedaddon/
- Gravity Flow feed integrations: https://docs.gravityflow.io/category/integrations/
- Gravity Flow Feed Add-On Step: https://docs.gravityflow.io/step_feed_class/
- Gravity Flow Step class: https://docs.gravityflow.io/step-class/
- WordPress HTTP API: https://developer.wordpress.org/reference/functions/wp_remote_post/
- WordPress User Metadata: https://developer.wordpress.org/plugins/users/working-with-user-metadata/
- GravityView Elementor integration: https://www.gravitykit.com/docs/gravityview-pro/advanced-elementor-widget/
- IPPanel Edge API: https://ippanelcom.github.io/Edge-Document/
- Bale Bot API: https://docs.bale.ai/

## 16. Final Migration Statement

This is a **greenfield rewrite of the target core**, not a class-by-class refactor of the legacy runtime.

```text
LEGACY REFERENCE
hooks + queue + cron + direct handlers + custom rules + log table
        │
        │ read-only, on-demand
        ▼
inspect → validate → transplant bounded value only
        │
        ▼
GRAVITY NOTIFICATION MANAGER
native Feed + native Flow Feed Step
+ recipient resolver
+ synchronous dispatcher
+ provider registry + Bale
+ Entry Meta + manual Retry
+ Point guidance/verification
+ GravityView/Elementor presentation
```

Default answer to “should we evolve this legacy class into the target?” is **no**.
