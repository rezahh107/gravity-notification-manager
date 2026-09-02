# Gravityflow SMS IPPanel — Migration Plan

> **Document ID:** `GFSMS-MIGRATION-PLAN-1.1.0`  
> **Status:** `GREENFIELD_CORE_APPROVED / READY_FOR_IMPLEMENTATION_WORK-UNIT_DEFINITION`  
> **Plan date:** `2026-09-02`  
> **Repository:** `rezahh107/Gravityflow-SMS-Ippanel`  
> **Required implementation baseline:** `main@7556f86ecc65f37d34d9563ce2087f16235bbca5`  
> **Immutable legacy reference:** `legacy-source-pre-greenfield-2026-09-02@7556f86ecc65f37d34d9563ce2087f16235bbca5`  
> **Target contract:** `docs/TARGET_ARCHITECTURE.md`  
> **Purpose:** Build the closed target architecture as a greenfield core, using the frozen legacy source only as a read-only salvage reference, then perform a controlled cutover and retire the legacy runtime.

---

## 1. Plan Authority and Boundary

`TARGET_ARCHITECTURE.md` is authoritative.

This plan may refine:

- sequencing;
- file/class boundaries;
- migration mechanics;
- tests;
- rollback;
- compatibility handling.

This plan MUST NOT independently reopen:

- Feed-as-Rule;
- Feed-as-Flow-Step;
- synchronous delivery;
- no-Cron/no-queue baseline;
- Entry Meta delivery state;
- ordered SMS/Bale failover;
- manual Retry;
- Point Manager authority;
- GravityView/Elementor presentation boundary;
- staff contact metadata;
- provider eligibility rules.

If implementation evidence contradicts the target, stop only the affected Work Unit and invoke the target document’s **Reopen Conditions**.

---

## 2. Baseline and Legacy Identity

Greenfield implementation work begins from:

```text
Repository: rezahh107/Gravityflow-SMS-Ippanel
Branch: main
SHA: 7556f86ecc65f37d34d9563ce2087f16235bbca5
```

The immutable legacy source authority is:

```text
Tag: legacy-source-pre-greenfield-2026-09-02
Commit: 7556f86ecc65f37d34d9563ce2087f16235bbca5
Mode: READ_ONLY_REFERENCE
```

The current `main` tree still contains legacy runtime code because cutover has not happened yet. That does **not** make those classes the implementation foundation for the new core.

The greenfield rule is:

```text
TARGET_ARCHITECTURE.md
        ↓
design new responsibility from scratch
        ↓
need prior implementation knowledge?
        ↓
consult SALVAGE_REFERENCE.md
        ↓
inspect exact file from immutable legacy tag
        ↓
validate against current official contract
        ↓
transplant only the still-valid behavior/logic/data
```

Do not copy the legacy tree into a `legacy/` directory.

Individual implementation Work Units may specify a newer exact base/head. Those explicit Work Unit identities supersede the initial implementation baseline while the immutable legacy tag remains unchanged.

See `docs/SALVAGE_REFERENCE.md` for the approved salvage categories and paths.

---

## 3. Baseline Architectural Findings

The inspected source currently contains multiple overlapping execution paths.

### 3.1 Current composition

The current bootstrap/plugin composition registers legacy/current components including:

- Gravity Flow listener;
- event queue;
- dispatcher;
- direct Gravity Forms handler;
- separate SMS sender;
- provider factory;
- recipient/rule services;
- settings/admin surfaces;
- logger/table lifecycle.

This means the migration is not “add a new sender.” It is a controlled replacement of parallel execution paths.

### 3.2 Queue/scheduler path exists

`includes/Integration/Event_Queue.php` contains:

- Action Scheduler enqueue behavior;
- WP-Cron fallback behavior;
- queued dispatch hook behavior.

`includes/Lifecycle/Activator.php` also schedules a daily log-cleanup cron event.

The target forbids Cron/Action Scheduler for notification execution/retry. Cleanup scheduling is a separate concern and must be reviewed independently; it must not be allowed to preserve the old delivery architecture by accident.

### 3.3 Direct Gravity Forms path exists

`includes/Integration/GravityForms_Handler.php` implements a direct Gravity Forms submission path with custom rule/condition/delivery behavior.

The target replaces this with the native `GFFeedAddOn` Rule/processing model.

### 3.4 Gravity Flow listener/dispatcher path exists

`includes/Integration/Listener.php` listens to Gravity Flow lifecycle hooks and sends snapshots to the custom queue/dispatcher path.

The target uses compatible Feed-based Workflow Steps for notification execution.

Therefore the old Listener is not retained as the primary notification trigger.

### 3.5 Parallel sender exists

`includes/Integration/Sms_Sender.php` provides another send path.

The target must end with one authoritative synchronous execution path.

### 3.6 Provider abstraction exists but is not the target contract

Current provider-related files include:

- `includes/Integration/SMS_Provider_Interface.php`
- `includes/Integration/IPPanel_Provider.php`
- `includes/Integration/Secondary_Provider.php`
- `includes/Infrastructure/ProviderFactory.php`
- `includes/Integration/Wp_HTTP_Client.php`
- `includes/Integration/HTTP_Client_Interface.php`

Useful transport/provider behavior may be consulted from the immutable legacy tag, but the new provider layer is designed greenfield as a contract-gated, capability-aware `SmsProviderRegistry`. Legacy classes are not the default implementation base.

Notably, current `Secondary_Provider` is only an `IPPanel_Provider` subclass with a different name; it is not evidence of a genuinely independent second provider.

### 3.7 Reusable service candidates exist

Current service files include:

- `includes/Services/RecipientResolver.php`
- `includes/Services/MessageBuilder.php`
- `includes/Services/PatternVariableBuilder.php`
- `includes/Services/PhoneNumberNormalizer.php`
- `includes/Services/LockManager.php`

These are read-only salvage references. Any reuse is on-demand validated transplant into greenfield code, not in-place evolution or automatic preservation.

### 3.8 Admin/UI is legacy-oriented

Current admin files include:

- `includes/Admin/DoctorPage.php`
- `includes/Admin/Logs_Table.php`
- `includes/Admin/Settings_Page.php`
- `includes/Admin/Settings_Fields.php`
- `includes/Admin/Settings_Schema.php`

The target replaces delivery-log/dashboard responsibilities with Entry Meta + GravityView/Elementor presentation and adds Point guidance/verification.

### 3.9 Activation currently creates/schedules legacy support infrastructure

`includes/Lifecycle/Activator.php` calls `Logger::create_table()` and schedules `gfsms_cleanup_logs`.

This must not survive merely because it already exists.

---

## 4. Migration Strategy

Selected strategy:

```text
GREENFIELD CORE
+
READ-ONLY LEGACY REFERENCE
+
ON-DEMAND VALIDATED TRANSPLANT
+
SINGLE CONTROLLED CUTOVER
+
LEGACY RETIREMENT
```

### 4.1 Greenfield-by-default rule

The target core is written from `docs/TARGET_ARCHITECTURE.md`, not by reshaping legacy classes.

For each target responsibility:

```text
1. Define the target responsibility from the closed architecture.
2. Verify the current official Gravity Forms / Gravity Flow / WordPress / provider contract.
3. Design the new class/API around the target responsibility.
4. Consult legacy only if it contains useful prior knowledge.
5. Transplant the smallest validated behavior, not the old architecture.
6. Write target-focused tests.
```

Legacy code must not dictate:

- class boundaries;
- execution topology;
- Rule storage;
- queue/retry semantics;
- locking model;
- workflow event model;
- provider capability model;
- admin information architecture.

### 4.2 Approved salvage taxonomy

Only these classifications are used:

```text
REFERENCE_ONLY
TRANSPLANT_CANDIDATE
BEHAVIOR_REFERENCE
DATA_MIGRATION_ONLY
SELECTIVE_CONTENT_REUSE
RETIRE
```

The authoritative per-asset manifest is `docs/SALVAGE_REFERENCE.md`.

Terms such as `PRESERVE_AND_HARDEN` and `REFACTOR` are intentionally avoided for legacy target components because they can incorrectly imply in-place architectural evolution.

### 4.3 Conceptual sequence

```text
Freeze legacy reference (DONE)
        ↓
Minimal legacy characterization
        ↓
Build greenfield target core
        ↓
At each bounded need:
inspect → validate → transplant
        ↓
Build target tests and integration proof
        ↓
Migrate only required settings/data
        ↓
Controlled cutover
        ↓
Prove only greenfield runtime sends
        ↓
Delete/retire legacy runtime
        ↓
Final validation and documentation
```

The migration is therefore not a class-by-class refactor of the current plugin.

---

## 5. Non-Negotiable Cutover Invariant

At no point may normal production operation intentionally have:

```text
legacy real sender
+
new real sender
```

active for the same logical notification scope.

The Owner accepts rare concurrency duplicates inside the selected delivery model; that does **not** authorize migration-wide duplicate execution from two architectures.

Before new real delivery is enabled for a migrated scope, the equivalent legacy sender for that scope must be disabled.

---

## 6. Legacy Source Disposition

All paths below refer to the immutable legacy source:

```text
legacy-source-pre-greenfield-2026-09-02@7556f86ecc65f37d34d9563ce2087f16235bbca5
```

| Legacy asset | Classification | Greenfield rule |
|---|---|---|
| `gravityflow-sms-ippanel.php` | `REFERENCE_ONLY` | New bootstrap is designed from target requirements; legacy file is useful only for metadata/bootstrap history |
| `includes/Core/Bootstrap.php` | `RETIRE` | Do not evolve it into the new composition root |
| `includes/Plugin.php` | `REFERENCE_ONLY` | Consult only if useful for lifecycle/history |
| `includes/Integration/Event_Queue.php` | `RETIRE` | Never transplant queue/scheduler behavior |
| `includes/Integration/Listener.php` | `RETIRE` | Do not use lifecycle-hook listener as the new delivery trigger |
| `includes/Integration/Dispatcher.php` | `REFERENCE_ONLY` | Consult only for bounded message/recipient/provider knowledge; never transplant its orchestration |
| `includes/Integration/GravityForms_Handler.php` | `REFERENCE_ONLY` | May reveal old form-rule behavior; do not transplant direct `gform_after_submission` architecture |
| `includes/Integration/Sms_Sender.php` | `RETIRE` | New synchronous dispatcher owns delivery |
| `includes/Integration/SMS_Provider_Interface.php` | `BEHAVIOR_REFERENCE` | New capability-aware provider contract is written from scratch |
| `includes/Integration/IPPanel_Provider.php` | `TRANSPLANT_CANDIDATE` | Extract only current-valid Edge request/response behavior after official API validation |
| `includes/Integration/Secondary_Provider.php` | `RETIRE` | Current subclass is not a genuinely independent provider |
| `includes/Integration/Wp_HTTP_Client.php` | `TRANSPLANT_CANDIDATE` | Strong candidate after focused review/tests |
| `includes/Integration/HTTP_Client_Interface.php` | `BEHAVIOR_REFERENCE` | New HTTP seam may reuse the idea, not necessarily the exact interface |
| `includes/Infrastructure/ProviderFactory.php` | `RETIRE` | New `SmsProviderRegistry` is greenfield |
| `includes/Services/RecipientResolver.php` | `BEHAVIOR_REFERENCE` | Extract assignee/user/role resolution knowledge; replace legacy mobile-meta contract |
| `includes/Services/MessageBuilder.php` | `BEHAVIOR_REFERENCE` | Prefer current native Gravity Forms merge-tag APIs; transplant only proven gaps |
| `includes/Services/PatternVariableBuilder.php` | `BEHAVIOR_REFERENCE` | Extract provider-independent pattern mapping behavior only where still needed |
| `includes/Services/PhoneNumberNormalizer.php` | `BEHAVIOR_REFERENCE` | Preserve validated normalization behavior through target-focused tests |
| `includes/Services/LockManager.php` | `RETIRE` | Heavy exactly-once locking is outside target |
| `includes/Domain/EventSnapshot.php` | `RETIRE` | Queue/event-snapshot architecture is not target |
| `includes/Domain/EventState.php` | `RETIRE` | Queue/event-state architecture is not target |
| `includes/Domain/EventType.php` | `RETIRE` | Native Feed/Step identity replaces legacy event model |
| `includes/Domain/Settings.php` | `DATA_MIGRATION_ONLY` | Extract only settings values/schema knowledge needed for migration |
| `includes/Admin/Settings_Page.php` | `REFERENCE_ONLY` | Consult labels/setting meaning only; new admin surfaces follow target |
| `includes/Admin/Settings_Fields.php` | `REFERENCE_ONLY` | No in-place UI evolution assumption |
| `includes/Admin/Settings_Schema.php` | `DATA_MIGRATION_ONLY` | Map only still-valid global provider settings |
| `includes/Admin/Logs_Table.php` | `RETIRE` | Central target presentation uses Entry Meta + GravityView/Elementor |
| `includes/Admin/DoctorPage.php` | `REFERENCE_ONLY` | New diagnostics must be side-effect-free by design |
| `includes/Logging/Logger.php` | `REFERENCE_ONLY` | Do not make legacy log table the new delivery-state authority |
| `includes/Lifecycle/Activator.php` | `REFERENCE_ONLY` | New activation behavior is designed greenfield; do not carry scheduler/table creation by inertia |
| `includes/Lifecycle/Deactivator.php` | `REFERENCE_ONLY` | Carry forward only cleanup obligations that remain valid |
| `includes/Lifecycle/Uninstaller.php` | `REFERENCE_ONLY` | New uninstall policy must protect external `wudm_*` user meta |
| `languages/**` | `SELECTIVE_CONTENT_REUSE` | Reuse only translations for UI concepts that survive |
| existing provider credentials/settings values | `DATA_MIGRATION_ONLY` | Migrate values safely; do not preserve legacy settings architecture |

The detailed salvage rules and extraction gates are defined in `docs/SALVAGE_REFERENCE.md`.

---

## 7. Target Runtime Components

The implementation may choose exact class names, but the final responsibility graph should remain approximately:

```text
Notification Feed Add-On
├─ Feed Settings / Rule definition
├─ Native feed condition
└─ process_feed()
        │
        ▼
RecipientResolver
        │
        ▼
SynchronousNotificationDispatcher
        │
        ├─ SmsChannel
        │    └─ SmsProviderRegistry
        │          ├─ IPPanelProvider
        │          └─ SecondaryProvider
        │
        └─ BaleChannel
        │
        ▼
EntryMetaDeliveryStore
```

Gravity Flow adds a narrow compatible Feed-Step adapter around the Feed Add-On.

Point Manager and Attention presentation are separate read/control surfaces over the same authoritative configuration/state.

---

## 8. Work Unit Sequence

### WU-00 — Minimal Legacy Asset Capture and No-Send Greenfield Harness

**Objective**

Capture only the legacy facts needed for safe replacement, and establish a no-send test harness for the greenfield core. Do not attempt to understand or repair the legacy architecture comprehensively.

**Required work**

- verify exact repository HEAD;
- enumerate current notification hooks and send paths;
- record current settings/options/meta/table names;
- identify scheduled hooks currently registered;
- create provider fakes/mocks so tests never send real SMS/Bale;
- inspect only salvage-listed assets from the immutable legacy tag;
- characterize current-valid IPPanel request/response behavior worth transplanting;
- characterize recipient/message/normalization behavior worth preserving;
- capture target-relevant behavior as regression tests where practical;
- produce an explicit `SALVAGED / NOT_SALVAGED` ledger for every legacy asset consulted.

**Forbidden**

- architecture change;
- real provider calls;
- deleting legacy paths;
- changing production behavior.

**Exit gate**

- all material current send entry points are known;
- test harness can prove when a send attempt would occur without contacting a provider.

---

### WU-01 — Native Notification Feed Foundation

**Objective**

Write the target `GFFeedAddOn` foundation as greenfield code without turning on a second production sender. Do not derive its class structure from `GravityForms_Handler` or the legacy custom Rule schema.

**Required work**

- register the Notification Feed Add-On through the current Gravity Forms Add-On Framework;
- define one logical Feed = one logical notification;
- define feed settings for message, recipients, channel/fallback requirements, and native feed condition;
- keep asynchronous feed processing disabled for the target baseline;
- define stable internal feed metadata/schema version;
- add tests for feed settings and feed condition behavior.

**Forbidden**

- Action Scheduler;
- WP-Cron;
- new custom Rule database;
- enabling both old and new real submission send paths.

**Exit gate**

- Feed CRUD/configuration works;
- native conditional logic is used;
- no real provider call is required to validate the Feed layer.

---

### WU-02 — Provider Registry, IPPanel, and Bale Channel

**Objective**

Build the target synchronous transport layer.

**Required work**

- define minimal normalized SMS provider contract;
- define provider capability declarations;
- implement contract-gated `SmsProviderRegistry`;
- implement IPPanel against the new contract using validated transplant only for still-current Edge request/response knowledge;
- transplant or rewrite the WordPress HTTP transport only after focused validation;
- add Bale as a separate channel;
- implement ordered provider selection;
- skip providers that lack the Rule-required capability;
- preserve provider-specific references where available;
- classify `SUCCESS`, `FAILED`, `AMBIGUOUS`, `SKIPPED`.

**Provider admission gate**

A provider is not added unless it satisfies the contract in `TARGET_ARCHITECTURE.md`.

**Forbidden**

- automatic Pattern-to-Plain conversion;
- provider-specific logic in Point Manager;
- background retry;
- arbitrary undocumented provider adapters.

**Exit gate**

Provider mocks prove:

```text
primary success → stop
primary failed/ambiguous → secondary
secondary incompatible → skip
secondary failed/ambiguous → Bale
all fail → unresolved
```

---

### WU-03 — Recipient Resolution

**Objective**

Write the target recipient resolver from the selected generic model. Legacy `RecipientResolver` is a behavior reference only.

**Required recipient types**

At minimum:

- Gravity Forms Entry field;
- fixed SMS target;
- WordPress user;
- WordPress role;
- Gravity Flow assignee/step assignee where current API permits;
- fixed Bale target;
- user-based Bale target.

**Staff contact keys**

```text
wudm_notification_mobile
wudm_bale_chat_id
```

Read them directly with WordPress user-meta APIs.

Do not call WP-Bulk-Import classes.

**Forbidden**

- hard-coded `RegistrationOfficer` / `Accountant` / `Manager` domain classes;
- a separate staff directory;
- dependency on `plato_user_mobile`.

**Exit gate**

Tests prove user/role/assignee resolution and graceful behavior for missing contact data.

---

### WU-04 — Synchronous Feed Execution and Gravity Flow Feed-Step Integration

**Objective**

Make the greenfield Feed implementation the authoritative runtime notification mechanism.

**Gravity Forms path**

Normal submission Feed uses standard `GFFeedAddOn` processing.

**Gravity Flow path**

Implement the narrow compatibility layer required for the Notification Feed to appear as a Gravity Flow Step, using the current feed-step framework.

**Required proof**

- a Flow-assigned Feed does not independently execute during normal form submission;
- it executes when the workflow reaches its configured Step;
- native Feed conditional logic is respected;
- provider failure is recorded without indefinitely holding the workflow;
- one Step/Feed represents one logical notification.

**Forbidden**

- recreating the old `gravityflow_step_complete → queue → dispatcher` delivery architecture;
- custom workflow orchestration;
- hidden background sends.

**Exit gate**

A representative workflow passes:

```text
Submit
→ Approval
→ Notification Feed Step
→ Next Step
```

with zero real network calls in automated tests and explicit test-provider fixtures for runtime behavior.

---

### WU-05 — Entry Meta Delivery State and Manual Retry

**Objective**

Replace the custom delivery-state/locking model with the approved lightweight Entry Meta model.

**Required work**

- define versioned Entry Meta keys/schema;
- record attempts and final operational state;
- implement best-effort duplicate suppression;
- implement `attention_required`;
- implement explicit manual Retry;
- manual Retry reruns the same current synchronous chain;
- successful manual Retry resolves the attention state.

**Accepted limitation**

No atomic exactly-once guarantee.

**Forbidden**

- custom delivery table as the new SSOT;
- database-level atomic claim subsystem;
- scheduled retry;
- queue-based retry.

**Exit gate**

Tests cover:

- sequential duplicate suppression;
- normal success;
- all-fail attention;
- manual retry success;
- attempt history;
- accepted non-guarantee for simultaneous races.

---

### WU-06 — Point Manager Guidance and Verification

**Objective**

Provide the central “do not get lost” setup experience without taking ownership of Flow topology.

**Required work**

For each supported notification setup, Point Manager should:

- show current state;
- detect missing/misconfigured Notification Feed Steps;
- provide exact setup instructions;
- provide a link to the relevant Gravity Flow screen;
- provide a link to current official help where appropriate;
- provide `Check Again`;
- verify Step type/feed/active state/expected routing fields that are safely inspectable.

**Forbidden**

- inserting Steps;
- reordering Steps;
- mutating Approval routing;
- silently fixing Flow topology.

**Exit gate**

A user can follow the guidance, configure the Flow in the native editor, return, click `Check Again`, and receive an evidence-based configuration status.

---

### WU-07 — Attention Required Surfaces

**Objective**

Expose unresolved delivery state without building another dashboard subsystem.

**Plugin-owned**

- Entry Meta state;
- manual Retry endpoint/action;
- Entry Detail status meta box;
- bounded reusable Retry control/shortcode if required.

**Presentation-owned**

- GravityView View filtered to unresolved/attention entries;
- Elementor layout/styling.

**Forbidden**

- a duplicate custom searchable delivery database UI;
- a second status store inside GravityView.

**Exit gate**

- Entry Detail can show status and Retry independently;
- GravityView can present the central attention list using plugin-owned Entry Meta;
- retry from the central presentation calls the same plugin Retry logic.

---

### WU-08 — Configuration Migration and Controlled Cutover

**Objective**

Move real configuration to the target Feed/Step model without duplicate real sending.

**Required work**

- inventory current global provider credentials/settings;
- migrate reusable provider/global settings;
- identify legacy per-form/rule configuration;
- automatically migrate only mappings that are semantically unambiguous;
- produce explicit manual migration guidance for ambiguous old rules;
- create/configure required Feeds;
- guide user to create/configure Flow Feed Steps;
- verify with Point Manager;
- disable equivalent legacy sender before enabling the new sender for that scope.

**Historical delivery data**

Old log data does not need to be transformed into the new Entry Meta model unless a direct operational requirement is found.

It may remain temporarily read-only for rollback/history.

**Forbidden**

- guessing ambiguous old Rule mappings;
- enabling new production send while equivalent old path is still active.

**Exit gate**

Every migrated active notification has one and only one intended runtime execution path.

---

### WU-09 — Legacy Runtime Retirement

**Objective**

Delete or deactivate architecture that is no longer part of the target after cutover is proven.

**Expected retirement**

- `Event_Queue`;
- Action Scheduler delivery path;
- WP-Cron delivery fallback;
- legacy Gravity Flow Listener delivery path;
- legacy queued Dispatcher behavior;
- direct `GravityForms_Handler` sender;
- duplicate `Sms_Sender`;
- old retry/backoff machinery;
- obsolete delivery locks;
- custom Rule/condition system superseded by Feed settings/native condition;
- obsolete provider factory;
- obsolete custom delivery log UI/table lifecycle when rollback/history no longer requires it.

**Important**

Do not delete code based on filename alone. Confirm zero target call sites first.

**Exit gate**

Static and runtime verification show no legacy notification sender remains registered.

---

### WU-10 — Lifecycle, Diagnostics, Compatibility, and Release Validation

**Objective**

Finish the migration cleanly.

**Required work**

- remove obsolete scheduled event registration/cleanup;
- make Doctor/diagnostics side-effect-free by default;
- any real test send must require explicit user action and clear destination;
- reconcile plugin header / Composer PHP/runtime requirements;
- verify dependency compatibility;
- ensure logging does not expose credentials;
- update uninstall behavior;
- never delete external `wudm_notification_mobile` or `wudm_bale_chat_id`;
- update README to point to architecture/migration docs;
- run final regression matrix.

**Exit gate**

Target architecture conformance is proven and documentation matches actual runtime.

---

## 9. Data Migration Rules

### 9.1 Global provider settings

Existing valid IPPanel credential/settings **values** may be migrated to the new provider configuration. This is data migration only and does not authorize reuse of the legacy settings architecture.

Do not expose secrets in migration logs.

### 9.2 Legacy Rule configuration

Legacy rules should be converted to Feed settings only when the mapping is deterministic.

If old semantics cannot be represented without guessing:

```text
DO NOT GUESS
→ report the rule
→ provide manual setup guidance
```

### 9.3 Historical log table

If the current logger table contains useful historical records:

- keep it read-only through cutover if desired;
- do not make the new architecture depend on it;
- remove it only after rollback/history requirements expire.

### 9.4 User metadata

Do not migrate or own:

```text
wudm_notification_mobile
wudm_bale_chat_id
```

The Notification plugin is a consumer of those WordPress user-meta contracts.

---

## 10. Entry Meta Schema Requirements

The exact meta keys are implementation details, but the schema must be:

- namespaced;
- versionable;
- queryable enough for GravityView/Attention presentation;
- compact enough for ordinary Entry operations.

A possible conceptual split:

```text
<namespace>_attention_required
<namespace>_delivery_state
<namespace>_attempts
```

The implementation may choose a better normalized shape after testing.

The schema must not pretend to provide atomic exactly-once semantics.

---

## 11. Regression Matrix

At minimum, final validation must cover:

### Feed behavior

- submission Feed executes at submission;
- Flow-assigned Feed is intercepted from normal submit processing;
- Flow Feed Step executes when reached;
- disabled Feed does not send;
- native feed condition true/false behavior.

### Workflow behavior

- Notification Step completes after success;
- Notification Step completes after all providers fail;
- business workflow continues after notification failure;
- Step routing remains owned by Gravity Flow.

### Provider chain

- primary success prevents unnecessary fallback;
- primary definite failure invokes secondary;
- primary ambiguous result also permits secondary;
- incompatible secondary capability is skipped;
- secondary success stops Bale fallback;
- SMS chain unresolved invokes Bale;
- Bale unresolved sets Attention Required.

### Provider capabilities

- Plain Rule uses only Plain-capable provider;
- Pattern Rule uses only Pattern-capable provider;
- no automatic Pattern-to-Plain conversion;
- provider-specific batch behavior does not leak into generic Rule semantics.

### Recipient resolution

- Entry field recipient;
- fixed recipient;
- WordPress user;
- WordPress role;
- Gravity Flow assignee;
- user SMS from `wudm_notification_mobile`;
- user Bale from `wudm_bale_chat_id`;
- missing contact gracefully falls through.

### Delivery state

- `SUCCESS`;
- `FAILED`;
- `AMBIGUOUS`;
- `SKIPPED`;
- attention flag;
- attempt history;
- best-effort sequential duplicate suppression.

### Manual Retry

- requires capability;
- requires valid nonce;
- no background scheduling;
- records new attempt;
- successful retry resolves Attention Required.

### No-background invariant

Assert that normal notification behavior does not invoke:

- Action Scheduler enqueue;
- WP-Cron send scheduling;
- custom queue;
- worker.

### Diagnostics

- opening Doctor page sends nothing;
- rendering/exporting diagnostics sends nothing;
- explicit test-send action is separately authorized;
- secrets are not logged.

### Migration/cutover

- old and new sender are never both active for the same migrated scope;
- old scheduled delivery hook is not left registered after retirement;
- legacy paths have zero runtime send call sites.

---

## 12. Validation Environments

Use layered validation:

```text
Static checks
    ↓
Unit/provider fakes
    ↓
WordPress + Gravity Forms integration
    ↓
Gravity Flow workflow integration
    ↓
Staging with non-production provider credentials/test destinations
    ↓
Controlled production cutover
```

Automated tests MUST NOT contact real SMS/Bale providers.

Production credentials MUST NOT be used in CI.

---

## 13. Rollback Strategy

Before legacy retirement and after controlled cutover, rollback may be operational:

```text
disable new migrated sender
→ restore legacy sender for affected scope
```

but never leave both enabled.

After WU-09 removes the legacy implementation, rollback becomes release/Git based:

```text
deploy last known-good package/commit
```

Do not retain dead dual-runtime architecture indefinitely merely to make rollback convenient.

---

## 14. Release Completion Criteria

The refactor is not complete until all are true:

- `TARGET_ARCHITECTURE.md` remains unchanged unless formally reopened;
- Feed Add-On is authoritative for notification Rule configuration;
- Flow Feed Step is authoritative for workflow-position execution;
- Point Manager guides/verifies but does not mutate Flow topology;
- one Step/Feed represents one logical notification;
- all delivery is synchronous in the baseline;
- no Action Scheduler/WP-Cron/background notification execution remains;
- primary/secondary SMS failover works;
- Bale fallback works;
- provider capability gate works;
- Entry Meta is the active delivery-state store;
- Attention Required is queryable/presentable;
- manual Retry works;
- staff contacts resolve from selected user meta;
- GravityView/Elementor can present the central attention page;
- Entry Detail provides operational fallback;
- legacy duplicate sender paths are removed;
- Doctor/diagnostics have no implicit external send side effects;
- runtime/dependency metadata is coherent;
- documentation matches runtime;
- regression suite passes against the final PR Head.

---

## 15. Required README Update After Migration Starts

The repository README should contain a small authoritative pointer, not duplicate the full documents.

Recommended text:

```markdown
## Architecture

The target architecture is decision-closed and the implementation strategy is greenfield-by-default.

- [Target Architecture](docs/TARGET_ARCHITECTURE.md)
- [Migration Plan](docs/MIGRATION_PLAN.md)
- [Legacy Salvage Reference](docs/SALVAGE_REFERENCE.md)

Implementation changes must conform to the target architecture and salvage rules. Architectural
decisions may be reopened only under the reopen conditions defined in the
target contract.
```

---

## 16. Migration Stop Conditions

Stop the current Work Unit and report rather than improvising when:

- the source HEAD does not match the required work-unit baseline;
- a current official Gravity Forms/Gravity Flow contract contradicts the selected native mechanism;
- a migration would require two real senders to be active simultaneously;
- a legacy Rule cannot be mapped without guessing;
- a provider fails the Provider Contract;
- a proposed change requires adding Cron/queue/background execution;
- implementation evidence requires reopening a locked architectural decision.

Do not silently broaden the work unit.

---

## 17. Official Implementation References

Re-check these current official contracts during the affected Work Unit:

- Gravity Forms — `GFFeedAddOn`  
  https://docs.gravityforms.com/gffeedaddon/

- Gravity Flow — Integrations / Feed-based Steps  
  https://docs.gravityflow.io/category/integrations/

- Gravity Flow — `Gravity_Flow_Step_Feed_Add_On`  
  https://docs.gravityflow.io/step_feed_class/

- Gravity Flow — Step class / status behavior  
  https://docs.gravityflow.io/step-class/

- WordPress HTTP API — `wp_remote_post()`  
  https://developer.wordpress.org/reference/functions/wp_remote_post/

- WordPress User Metadata  
  https://developer.wordpress.org/plugins/users/working-with-user-metadata/

- GravityView Elementor integration  
  https://www.gravitykit.com/docs/gravityview-pro/advanced-elementor-widget/

- IPPanel Edge API  
  https://ippanelcom.github.io/Edge-Document/

- Bale Bot API  
  https://docs.bale.ai/

---

## 18. Final Migration Statement

The migration is a **greenfield rewrite of the target core**, not a refactor of the legacy runtime.

Legacy is permanently frozen at:

```text
legacy-source-pre-greenfield-2026-09-02@7556f86ecc65f37d34d9563ce2087f16235bbca5
```

and is consulted only through `docs/SALVAGE_REFERENCE.md`.

The desired transformation is:

```text
LEGACY REFERENCE
hooks + queue + cron + dispatcher + direct GF sender
+ duplicate sender + custom rules + log table
        │
        │ read-only, on-demand
        ▼
inspect → validate → transplant only bounded value
        │
        ▼
GREENFIELD TARGET
native Feed + native Flow Feed Step
+ synchronous dispatcher
+ provider registry + Bale
+ Entry Meta + manual Retry
+ Point guidance/verification
+ GravityView/Elementor presentation
```

The default answer to “should we modify this legacy class?” is **no**.

Modify a legacy runtime file only when required for:

- controlled cutover/deactivation;
- bounded settings/data migration;
- a directly required compatibility shim;
- safe retirement/deletion.

Do not evolve legacy classes toward the target architecture.

Every transplanted behavior must justify itself against:

1. `docs/TARGET_ARCHITECTURE.md`;
2. current official contracts;
3. `docs/SALVAGE_REFERENCE.md`;
4. target-focused tests.
