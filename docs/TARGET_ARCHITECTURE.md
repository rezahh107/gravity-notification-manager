# Gravity Notification Manager — Target Architecture

> **Document ID:** `GNM-TARGET-ARCHITECTURE-1.1.0`  
> **Status:** `CLOSED`  
> **Decision date:** `2026-09-02`  
> **Repository:** `rezahh107/gravity-notification-manager`  
> **Product identity:** `docs/PRODUCT_IDENTITY.md`  
> **Legacy reference:** `legacy-source-pre-greenfield-2026-09-02@7556f86ecc65f37d34d9563ce2087f16235bbca5`

## 1. Authority

This file is the canonical architecture contract for **Gravity Notification Manager**.

The architecture is decision-closed. Implementation MUST conform to this document and MUST NOT reopen closed choices merely because another design appears more sophisticated.

```text
PRODUCT_IDENTITY.md
= who the product is

TARGET_ARCHITECTURE.md
= where the product is going

MIGRATION_PLAN.md
= how the repository gets there

SALVAGE_REFERENCE.md
= what may be learned/transplanted from legacy
```

`plan != implementation != validation != completion`.

## 2. Canonical Product Identity

```text
Product Name: Gravity Notification Manager
Repository: rezahh107/gravity-notification-manager
Plugin slug: gravity-notification-manager
Text domain: gravity-notification-manager
Greenfield PHP namespace: GravityNotify
```

Legacy identifiers such as `gravityflow-sms-ippanel.php`, `GFSMS`, and old option/text-domain names are migration evidence only. They are not greenfield naming authority.

## 3. Design Goals

The plugin provides operational notifications for Gravity Forms / Gravity Flow on ordinary shared hosting with:

- native Gravity Forms / Gravity Flow integration;
- clear logical notification Rules;
- synchronous delivery;
- multiple eligible SMS providers;
- Bale fallback;
- workflow-aware recipients;
- lightweight delivery state;
- Attention Required visibility;
- explicit manual Retry;
- minimal custom infrastructure.

Priority order:

```text
functional fit
→ current native capability
→ low operational complexity
→ shared-hosting reliability
→ administrative clarity
→ maintainability
→ extensibility only where proven useful
```

## 4. Governing Principles

### 4.1 Native-first

Prefer mechanisms in this order:

```text
Current Native Capability
→ Current Official Framework/API
→ Current Official Extension Point
→ Current Documented Hook
→ Small Compatibility Adapter
→ Legacy mechanism only if unavoidable
```

Native-first does not prohibit custom code. It prohibits rebuilding functionality already owned by supported Gravity Forms, Gravity Flow, WordPress, GravityView, or Elementor mechanisms.

### 4.2 Greenfield core

The new core is designed from this architecture, not from legacy class boundaries.

Legacy may be consulted only under `docs/SALVAGE_REFERENCE.md`.

### 4.3 Gravity Flow owns workflow topology

Gravity Flow remains authority for:

- workflow Steps;
- Step order;
- routing;
- approvals;
- assignments;
- next-step decisions.

Point Manager does not become a second workflow editor.

### 4.4 One logical notification = one Feed/Step

A logical notification is a business message/purpose, for example:

- tell the student registration was approved;
- tell accounting a case is ready;
- tell management workflow completed.

Multiple recipients receiving the same logical message stay in the same Rule. Provider fallback also stays inside the same Rule.

### 4.5 Synchronous-first

Baseline delivery executes synchronously at the native Feed/Step boundary.

No baseline notification delivery/retry depends on:

- Action Scheduler;
- WP-Cron;
- server cron;
- custom queue;
- worker;
- delayed retry scheduler.

### 4.6 Availability over strict exactly-once

The Owner explicitly accepts the rare possibility of duplicate notification delivery caused by concurrency or ambiguous provider responses.

Best-effort duplicate suppression is sufficient. Do not build heavy exactly-once infrastructure.

## 5. Target Runtime Shape

```text
Gravity Forms
    │
    ├─ normal submission Feed
    │
    └─ Gravity Flow
           ↓
      compatible Feed-based Step
           ↓
Logical Notification Feed / Rule
           ↓
Recipient Resolver
           ↓
Synchronous Dispatcher
           │
      ┌────┴───────────────┐
      ▼                    ▼
   SMS Channel          Bale Channel
      │                    │
SmsProviderRegistry      Bale API
      │
 ┌────┴────┐
 ▼         ▼
Primary   Secondary
Provider  Provider
      │
      ▼
Entry Meta Delivery State
      │
 ┌────┴─────────────────────┐
 ▼                          ▼
GF Entry Detail        GravityView + Elementor
Status + Retry         Attention Required view
```

## 6. Gravity Forms Feed Contract

`GFFeedAddOn` Feed configuration is the canonical notification Rule mechanism.

The Feed owns:

- logical notification name;
- message/template;
- recipient source configuration;
- channel/fallback configuration;
- provider capability requirements;
- enabled state;
- native Feed conditional logic.

Do not rebuild a parallel custom Rule engine when native Feed settings/conditions satisfy the need.

Normal submission notifications use the standard Gravity Forms Feed lifecycle.

## 7. Gravity Flow Feed-Step Contract

Workflow-position notifications use the current supported Gravity Flow feed-based Step integration.

Do not assume any arbitrary `GFFeedAddOn` automatically becomes a Flow Step. Implement the documented compatible Feed-Step contract, using a narrow adapter where required by Gravity Flow.

A Feed assigned to a compatible Gravity Flow Step should execute when the workflow reaches that Step rather than independently at the original submit boundary.

A notification being a visible real Workflow Step is intentional:

```text
Registration Officer Approval
        ↓
Notify Student
        ↓
Accountant Approval
        ↓
Notify Manager
        ↓
Workflow Complete
```

Notification delivery failure MUST NOT indefinitely trap the business workflow in the notification Step.

## 8. Point Manager Contract

Point Manager is a centralized **guidance, visibility, and verification** surface.

It SHOULD:

- show forms and notification-related workflow positions;
- show configured Rules/Feeds;
- detect missing/misconfigured Notification Feed Steps;
- show configuration health;
- show unresolved delivery problems;
- provide precise setup instructions;
- link to the correct Gravity Flow configuration screen;
- link to relevant official help where useful;
- provide `Check Again` to re-inspect actual configuration.

It MUST NOT:

- insert Flow Steps automatically;
- reorder Steps;
- silently change routing;
- mutate Approval destinations;
- become a second workflow editor.

Guidance should be practical, e.g.:

```text
1. Open Forms → <Form> → Settings → Workflow.
2. Add a new Step.
3. Select <Notification Feed Step Type>.
4. Select feed <Feed Name>.
5. Set Next Step to <Expected Destination>.
6. Save.
7. Return here and click Check Again.
```

## 9. Recipient Resolution Contract

The generic Recipient Resolver may support:

- Gravity Forms Entry field;
- fixed SMS target;
- WordPress user;
- WordPress role;
- Gravity Flow assignee / step assignee;
- fixed Bale target;
- user-based Bale target.

No SRWF-specific staff directory is created.

Gravity Flow / WordPress User identity remains authoritative for assigned personnel.

Staff contact user-meta contracts are:

```text
SMS  → wudm_notification_mobile
Bale → wudm_bale_chat_id
```

The plugin reads these directly through WordPress user-meta APIs.

It MUST NOT depend at runtime on WP-Bulk-Import classes and MUST NOT depend on `plato_user_mobile`.

Missing contact data is not a fatal workflow exception; record/skip that destination and continue valid fallback behavior.

## 10. SMS Provider Contract

`SmsProviderRegistry` is contract-gated and capability-aware.

A provider is eligible only if it has at minimum:

- official/documented API behavior;
- HTTPS transport;
- defined authentication;
- compatibility with WordPress HTTP APIs;
- explicit recipient/message request semantics;
- synchronous request/response behavior;
- operationally useful success/error distinction;
- no mandatory background worker for baseline delivery;
- at least one useful capability such as `plain` or `pattern`.

A provider that fails this gate should not be integrated merely to increase provider count.

Providers declare capabilities such as:

```text
plain
pattern
multi_recipient_plain
multi_recipient_pattern
provider_message_reference
```

If a Rule requires a capability the next provider lacks, skip that provider.

Never silently transform:

```text
pattern → plain
```

or invent another semantic conversion merely to force fallback.

IPPanel is the initial primary provider. Its Edge request/response behavior may be transplanted only after current official validation and target-focused tests.

## 11. Bale Contract

Bale is a separate channel, not an SMS provider.

```text
Notification Rule
   ├─ SMS → SmsProviderRegistry
   └─ Bale → Bale Bot API
```

Bale may be an immediate synchronous fallback after the SMS provider chain.

No delayed Bale retry is part of the baseline.

## 12. Delivery / Failover Contract

Baseline routing is ordered and synchronous:

```text
Primary SMS Provider
    │
    ├─ confirmed success → stop
    └─ not confirmed
            ↓
Secondary SMS Provider
    │
    ├─ confirmed success → stop
    └─ not confirmed
            ↓
Bale
    │
    ├─ confirmed success → resolved
    └─ not confirmed
            ↓
Attention Required
```

Attempt observability distinguishes at least:

```text
SUCCESS
FAILED
AMBIGUOUS
SKIPPED
```

For routing, both `FAILED` and `AMBIGUOUS` may continue to the next configured fallback because the Owner accepts rare duplicates.

No provider reconciliation subsystem is required.

## 13. Delivery State Contract

Gravity Forms Entry Meta is sufficient for the baseline delivery-state authority.

Conceptual information includes:

- logical Feed/Rule;
- Step/Point context where useful;
- attempt number;
- channel;
- provider;
- status;
- timestamp;
- provider reference where available;
- Attention Required flag;
- manual Retry resolution state.

The exact meta schema is an implementation contract and should be namespaced/versionable.

Best-effort sequential duplicate suppression may check for an existing completed execution.

Do not introduce solely for exactly-once behavior:

- dedicated delivery table;
- database locks;
- compare-and-set infrastructure;
- distributed/unique claiming system.

## 14. Manual Retry Contract

Unresolved failures are recoverable by explicit user action.

Manual Retry:

1. requires an appropriate capability;
2. requires nonce verification;
3. sanitizes identifiers;
4. runs immediately in the current request;
5. reruns the same current synchronous chain;
6. records a new attempt;
7. clears Attention Required after confirmed resolution;
8. schedules no future work.

## 15. Attention Required Presentation

Operational state belongs to Gravity Notification Manager.

Central presentation belongs to GravityView + Elementor:

```text
Entry Meta
    ↓
GravityView filtered View
    ↓
Elementor presentation
```

Gravity Forms Entry Detail retains a small independent status/Retry surface so operations do not depend on GravityView/Elementor being available.

Do not rebuild custom pagination/searchable tables/page-design systems without a proven presentation gap.

## 16. SRWF Boundary

For SRWF usage:

```text
Gravity Forms = canonical Form / Entry data authority
Gravity Flow  = workflow / approval / assignment authority
GravityView   = presentation-only surface
```

Routine GravityView edits or Needs Review presentation behavior are not automatically Notification Points.

## 17. Security / Privacy Boundary

This is a personal/internal plugin; security remains proportional rather than enterprise-heavy.

Still mandatory where applicable:

- capability checks;
- nonces for state-changing actions;
- sanitization;
- escaping;
- secret-safe logging;
- no unnecessary public endpoints;
- WordPress HTTP API for remote requests;
- no provider credentials in logs;
- no implicit provider send merely from rendering diagnostics/admin pages.

## 18. Explicit Non-Goals

Do NOT build unless this architecture is formally reopened:

- custom workflow engine;
- notification background queue;
- Action Scheduler notification execution;
- WP-Cron notification execution/retry;
- server-cron dependency;
- delayed automatic retry engine;
- enterprise event bus;
- generic omnichannel framework;
- custom Rule engine duplicating Feed conditional logic;
- custom staff/contact directory;
- custom delivery DB solely for exactly-once guarantees;
- atomic exactly-once infrastructure;
- provider reconciliation subsystem;
- automatic Pattern-to-Plain translation;
- undocumented/scraped provider integrations;
- custom central dashboard when GravityView + Elementor can present the data;
- automatic Point Manager mutation of Gravity Flow topology.

## 19. Implementation Invariants

Every Work Unit preserves:

1. Gravity Flow Editor remains topology authority.
2. New core uses the canonical product identity in `PRODUCT_IDENTITY.md`.
3. Workflow notification execution uses the selected native Feed-Step family.
4. One logical notification is not split into provider-specific Workflow Steps.
5. Baseline notification execution remains synchronous.
6. Provider failure does not indefinitely block business workflow.
7. Bale remains a separate channel.
8. Provider failover is capability-aware.
9. Unsupported provider capability is skipped, not auto-translated.
10. Entry Meta remains baseline delivery-state authority.
11. Exactly-once remains non-mandatory.
12. Manual Retry remains explicit and synchronous.
13. `wudm_notification_mobile` / `wudm_bale_chat_id` remain external user-meta contracts.
14. GravityView/Elementor remain presentation surfaces only.
15. Old and new real senders are never intentionally active for the same notification scope.

## 20. Architecture Acceptance Boundary

Implementation conformance is proven only when evidence shows:

- normal Gravity Forms Feed execution works;
- compatible Feed can be configured as a Gravity Flow Step;
- Flow-assigned Feed does not independently send at normal submit;
- Feed Step executes when workflow reaches it;
- notification failure does not strand workflow;
- selected recipient sources resolve correctly;
- staff contacts resolve from selected user-meta keys;
- ordered synchronous SMS failover works;
- Bale fallback works;
- unsupported provider capabilities are skipped;
- Entry Meta records operational result;
- unresolved failures appear as Attention Required;
- manual Retry works without Cron/queue;
- no Action Scheduler/WP-Cron/background notification path remains active;
- no duplicate legacy/new sender path remains active.

These are validation criteria, not claims that the current repository already passes them.

## 21. Reopen Conditions

Do not reopen architecture because a different implementation appears cleaner or more enterprise-grade.

Reopen only the smallest affected decision if at least one is true:

1. **Owner requirement change** — the Owner explicitly changes a mandatory requirement.
2. **Official-contract invalidation** — current authoritative documentation makes a selected mechanism impossible, unsupported, or deprecated.
3. **Implementation contradiction** — direct implementation evidence proves a locked choice cannot satisfy a mandatory requirement.
4. **Authority conflict** — a newer authoritative SRWF contract directly conflicts with this architecture.

Any reopen must name:

- the exact decision reopened;
- contradicting evidence;
- what remains locked.

## 22. Decision Register

| ID | Decision | State |
|---|---|---|
| `D-01` | Gravity Flow Editor owns workflow topology | `CLOSED` |
| `D-02` | Point Manager guides/verifies but does not mutate topology | `CLOSED` |
| `D-03` | `GFFeedAddOn` Feed is the notification Rule mechanism | `CLOSED` |
| `D-04` | Flow notifications use compatible Feed-based Steps | `CLOSED` |
| `D-05` | One Step/Feed = one logical notification | `CLOSED` |
| `D-06` | Delivery baseline is synchronous | `CLOSED` |
| `D-07` | No Action Scheduler/WP-Cron/background retry baseline | `CLOSED` |
| `D-08` | Ordered fallback = primary SMS → secondary SMS → Bale | `CLOSED` |
| `D-09` | Unresolved delivery = Attention Required + manual Retry | `CLOSED` |
| `D-10` | Ambiguous SMS may fail over; rare duplicate accepted | `CLOSED` |
| `D-11` | Entry Meta is sufficient delivery-state storage | `CLOSED` |
| `D-12` | Atomic exactly-once delivery is not required | `CLOSED` |
| `D-13` | Staff SMS meta = `wudm_notification_mobile` | `CLOSED` |
| `D-14` | Staff Bale meta = `wudm_bale_chat_id` | `CLOSED` |
| `D-15` | No runtime dependency on WP-Bulk-Import | `CLOSED` |
| `D-16` | GravityView + Elementor provide central Attention presentation | `CLOSED` |
| `D-17` | Entry Detail retains operational fallback | `CLOSED` |
| `D-18` | SMS registry is contract-gated/capability-aware | `CLOSED` |
| `D-19` | Incompatible fallback provider is skipped | `CLOSED` |
| `D-20` | Bale is a separate channel | `CLOSED` |
| `D-21` | GravityView is presentation-only for SRWF | `CLOSED` |
| `D-22` | Provider failures do not indefinitely block workflow | `CLOSED` |
| `D-23` | Product identity = Gravity Notification Manager / `GravityNotify` | `CLOSED` |

## 23. Legacy Boundary

Architecture decisions were derived from direct inspection of the pre-greenfield implementation and subsequent owner decisions.

The immutable legacy reference is:

```text
tag: legacy-source-pre-greenfield-2026-09-02
commit: 7556f86ecc65f37d34d9563ce2087f16235bbca5
```

The legacy tree remains evidence only. See `docs/SALVAGE_REFERENCE.md`.

## 24. Official Reference Set

Re-check current official contracts during implementation when behavior is decision-critical:

- Gravity Forms — `GFFeedAddOn`: https://docs.gravityforms.com/gffeedaddon/
- Gravity Flow — feed-based integrations: https://docs.gravityflow.io/category/integrations/
- Gravity Flow — Feed Add-On Step class: https://docs.gravityflow.io/step_feed_class/
- Gravity Flow — Step class: https://docs.gravityflow.io/step-class/
- WordPress — HTTP API: https://developer.wordpress.org/reference/functions/wp_remote_post/
- WordPress — User Metadata: https://developer.wordpress.org/plugins/users/working-with-user-metadata/
- GravityView — Elementor integration: https://www.gravitykit.com/docs/gravityview-pro/advanced-elementor-widget/
- IPPanel Edge API: https://ippanelcom.github.io/Edge-Document/
- Bale Bot API: https://docs.bale.ai/

## 25. Final Architectural Statement

Gravity Notification Manager is intentionally small:

```text
Native Gravity Forms Feed
+ Native Gravity Flow Feed Step
+ Point guidance/verification
+ Recipient resolution
+ Synchronous multi-provider SMS
+ Bale fallback
+ Entry Meta status
+ Manual Retry
+ GravityView/Elementor presentation
```

Any future implementation that adds infrastructure beyond this shape must first prove that a locked mandatory requirement cannot be met without it.
