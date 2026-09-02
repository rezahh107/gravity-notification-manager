# Gravityflow SMS IPPanel — Target Architecture

> **Document ID:** `GFSMS-TARGET-ARCHITECTURE-1.0.0`  
> **Status:** `CLOSED`  
> **Decision date:** `2026-09-02`  
> **Repository:** `rezahh107/Gravityflow-SMS-Ippanel`  
> **Source baseline inspected:** `main@405b7bbf06447cdc5bb72e81de18989687221d37`  
> **Authority:** Owner-approved target architecture  
> **Purpose:** Define the destination architecture. This document does **not** claim that the current implementation already conforms to it.

---

## 1. Contract Status

This document is the canonical architecture contract for the refactor of `Gravityflow-SMS-Ippanel`.

The architecture is **decision-closed**. Implementation work MUST conform to this document and MUST NOT reopen closed architectural choices merely because another design appears more sophisticated, more generic, or more conventional.

The distinction is explicit:

```text
TARGET_ARCHITECTURE.md
= where the plugin is going

MIGRATION_PLAN.md
= how the current source gets there

implementation
= the actual code changes

validation
= evidence that the implementation conforms
```

`plan != implementation != validation != completion`.

---

## 2. Problem Statement

The plugin must provide reliable operational notifications for Gravity Forms / Gravity Flow workflows on ordinary shared hosting, with:

- clear notification configuration;
- native Gravity Forms / Gravity Flow integration;
- synchronous delivery;
- multiple SMS providers;
- Bale fallback;
- operational staff recipients;
- simple failure visibility;
- manual retry;
- minimal custom infrastructure;
- low maintenance cost.

The plugin is primarily a personal/internal operational tool. The design therefore prioritizes:

1. functional fit;
2. current native product mechanisms;
3. shared-hosting reliability;
4. administrative clarity;
5. low maintenance;
6. minimal custom infrastructure;
7. future durability.

---

## 3. Governing Principles

### 3.1 Native-first

Use current supported capabilities in this order:

```text
Current Native Capability
→ Current Official Framework / API
→ Current Official Extension Point
→ Current Documented Hook
→ Small Compatibility Adapter
→ Legacy mechanism only if unavoidable
```

Native-first does **not** mean “never write custom code.” It means custom code should orchestrate or bridge current supported capabilities rather than rebuild functionality already owned by Gravity Forms, Gravity Flow, WordPress, GravityView, or Elementor.

### 3.2 Gravity Flow owns workflow topology

Gravity Flow remains the authority for:

- workflow steps;
- step order;
- routing;
- approvals;
- assignments;
- next-step decisions.

The Notification plugin MUST NOT become a second workflow editor.

### 3.3 One logical notification, one Rule/Feed/Step

A logical notification is defined by its business purpose and message.

Examples:

- “Tell the student that registration was approved.”
- “Tell the accountant that a case is ready for review.”
- “Tell management that workflow processing completed.”

A single logical notification may have:

- multiple recipients receiving the same message;
- primary and secondary SMS providers;
- Bale as fallback.

Those delivery alternatives are **not** separate workflow notifications.

### 3.4 Synchronous-first

The baseline delivery path is synchronous at the native event/step boundary.

No baseline notification execution may depend on:

- Action Scheduler;
- WP-Cron;
- server cron;
- an external scheduler;
- a background queue;
- a worker process.

### 3.5 Availability over exactly-once delivery

The Owner explicitly accepts the rare possibility of duplicate notifications caused by concurrency or an ambiguous provider response.

The system optimizes for:

```text
notification is likely to arrive
>
strict exactly-once delivery
```

Best-effort duplicate suppression is sufficient.

### 3.6 Presentation is not workflow authority

GravityView and Elementor may present operational notification status, but they do not own:

- workflow state;
- notification execution;
- delivery state;
- provider behavior;
- retry semantics.

---

## 4. Target Architecture

```text
                               ┌─────────────────────┐
                               │   Gravity Forms     │
                               │ Form / Entry SSOT   │
                               └─────────┬───────────┘
                                         │
                     ┌───────────────────┴───────────────────┐
                     │                                       │
                     ▼                                       ▼
          Form Submission Feed                      Gravity Flow
          (native GF feed path)              (workflow/assignment authority)
                                                             │
                                                             ▼
                                              Compatible Feed-based Step
                                                             │
                                                             ▼
                                             Logical Notification Rule
                                             (GFFeedAddOn feed config)
                                                             │
                                                             ▼
                                                   Recipient Resolver
                                                             │
                                                             ▼
                                              Synchronous Dispatcher
                                                             │
                         ┌───────────────────────────────────┴──────────────────────────────┐
                         │                                                                  │
                         ▼                                                                  ▼
                  SMS Channel                                                           Bale Channel
                         │                                                                  │
                         ▼                                                                  ▼
                SmsProviderRegistry                                                   Bale Bot API
                         │
              ┌──────────┴──────────┐
              ▼                     ▼
       Primary Provider      Secondary Provider
          (IPPanel)            (eligible only)
              │                     │
              └──────────┬──────────┘
                         │
                         ▼
                 Entry Meta Delivery State
                         │
            ┌────────────┴─────────────┐
            ▼                          ▼
   GF Entry Detail              Attention Required
   Status + Retry            GravityView + Elementor
```

---

## 5. Gravity Forms Rule Contract

### 5.1 `GFFeedAddOn` is the canonical notification Rule mechanism

The target notification rule is a Gravity Forms feed implemented through the current Add-On Framework.

The Feed owns configuration such as:

- logical notification name;
- message/template;
- recipient configuration;
- channel behavior;
- provider requirements;
- conditional logic;
- enabled/disabled state.

Native feed conditional logic should be used instead of rebuilding a custom rule engine.

### 5.2 Form submission

Feeds intended for form submission use the normal Gravity Forms feed lifecycle.

### 5.3 Gravity Flow

Feeds intended for workflow positions are exposed through a compatible Gravity Flow feed-step integration.

The target must use the Gravity Flow feed-step compatibility mechanism rather than manually pretending that a Gravity Forms feed is automatically a Flow Step.

The implementation may require a narrow adapter based on the current Gravity Flow feed-step framework, including `Gravity_Flow_Step_Feed_Add_On`.

That adapter is an implementation bridge inside this architecture. It is **not** a second workflow engine.

### 5.4 Native feed interception is preferred

When a compatible feed is assigned to a Gravity Flow Step, Gravity Flow is expected to intercept that feed from normal post-submission execution and process it when the workflow reaches the configured Step.

This native behavior is a core reason for selecting Feed-as-Flow-Step.

---

## 6. Workflow Step Contract

### 6.1 Notification is allowed to be a real Workflow Step

A notification being visible as a real Gravity Flow Step is an intentional architectural choice, not a defect.

Example:

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

This makes notification placement explicit and visible in the workflow.

### 6.2 One Step/Feed = one logical notification

Examples:

```text
Notify Student: Registration Approved
= one Step / Feed

Notify Accountant: Case Ready
= another Step / Feed
```

If the same message is sent to several recipients, one Rule may resolve multiple recipients.

Provider fallback stays inside that Rule.

### 6.3 Notification failure must not hold the business workflow hostage

The notification Step is operationally **non-blocking**.

The delivery result is recorded, but the business workflow proceeds.

Conceptually:

```text
SUCCESS
→ record
→ Step Complete

FAILED
→ fallback chain
→ record
→ Step Complete

AMBIGUOUS
→ continue fallback chain
→ record
→ Step Complete
```

No remote provider outage should indefinitely trap a registration case inside a notification Step.

---

## 7. Point Manager Contract

The plugin may provide a centralized **Point Manager**, but it is not a workflow topology editor.

### 7.1 Point Manager MUST

- show forms and notification-related workflow positions;
- show configured notification Rules/Feeds;
- show whether expected Notification Steps are present;
- show configuration health;
- show unresolved delivery problems;
- provide precise setup instructions;
- link to the correct Gravity Flow configuration screen;
- link to relevant official Gravity Flow help where useful;
- provide a **Check Again** action that re-inspects the actual workflow configuration.

### 7.2 Point Manager MUST NOT

- automatically insert Flow Steps;
- reorder workflow Steps;
- silently change routing;
- silently change Approval destinations;
- become an alternative workflow editor.

### 7.3 Guidance model

When a Step is missing, the user should receive practical instructions such as:

```text
1. Open Forms → <Form> → Settings → Workflow.
2. Add a new Step.
3. Select <Notification Feed Step Type>.
4. Select feed <Feed Name>.
5. Set Next Step to <Expected Destination>.
6. Save.
7. Return here and click “Check Again”.
```

The verification pass should inspect the actual current Gravity Flow Step object/configuration and report whether the expected setup now exists.

---

## 8. Recipient Resolution Contract

The generic Recipient Resolver may support:

- Gravity Forms Entry field;
- fixed phone number;
- multiple fixed phone numbers;
- WordPress user;
- WordPress role;
- Gravity Flow assignee / step assignee;
- fixed Bale target;
- user-based Bale target.

No SRWF-specific staff directory is created.

### 8.1 Staff identity

Gravity Flow / WordPress User identity remains authoritative for the assigned person.

Example:

```text
Accountant Approval Step
        ↓
Assignee = WP User #52
        ↓
Recipient Resolver
```

### 8.2 Staff contact metadata

The selected standard user-meta keys are:

```text
SMS:
wudm_notification_mobile

Bale:
wudm_bale_chat_id
```

These keys are independent from Plato and MUST NOT depend on `plato_user_mobile`.

The current `WP-Bulk-Import` plugin may be used to define/manage these fields, but the Notification plugin MUST read them directly through the WordPress user-meta API.

Therefore:

```text
WP-Bulk-Import runtime active?
    YES → management UI available
    NO  → notification plugin can still read stored user meta
```

The Notification plugin MUST NOT depend on `WP-Bulk-Import` classes at runtime.

### 8.3 Missing contact behavior

A missing address is not a fatal workflow error.

The resolver returns no usable destination for that channel, the attempt is recorded appropriately, and the configured fallback chain continues where applicable.

---

## 9. SMS Provider Contract

`SmsProviderRegistry` is a contract-gated registry, not an open-ended collection of arbitrary adapters.

### 9.1 Provider eligibility gate

A new SMS Provider is eligible only if it has, at minimum:

- an official/documented API;
- HTTPS transport;
- defined authentication;
- compatibility with the WordPress HTTP API;
- explicit recipient/message request semantics;
- synchronous request/response behavior;
- distinguishable success/error behavior sufficient for operations;
- no mandatory background worker/queue for baseline delivery;
- at least one useful capability such as `plain` or `pattern`.

A provider that fails this gate should not be integrated merely to increase provider count.

### 9.2 Capability declaration

Providers declare supported capabilities, for example:

```text
plain
pattern
multi_recipient_plain
multi_recipient_pattern
provider_message_reference
```

The exact internal representation is an implementation detail; the capability boundary is architectural.

### 9.3 Incompatible fallback

If a Rule requires a capability that a fallback provider does not support:

```text
required = pattern
provider supports pattern = false
```

the provider is skipped.

The system MUST NOT automatically convert:

```text
pattern → plain
```

or otherwise invent semantic transformations between providers.

### 9.4 IPPanel

IPPanel remains the initial primary SMS provider.

Its adapter may preserve validated Edge API request/response behavior from the current implementation, but it must conform to the new provider contract.

Provider-specific behavior must not leak upward into the Rule, Point Manager, Recipient Resolver, or workflow topology.

---

## 10. Bale Channel Contract

Bale is a separate notification channel.

It is **not** an SMS provider.

```text
Notification Rule
     │
     ├─ SMS Channel → SmsProviderRegistry
     │
     └─ Bale Channel → Bale Bot API
```

Bale may be configured as immediate fallback after the SMS provider chain.

Bale target values are text values and may be resolved from:

- `wudm_bale_chat_id`;
- a fixed configured Bale target.

No delayed Bale retry is part of the baseline.

---

## 11. Delivery and Failover Contract

The baseline is an ordered synchronous chain.

```text
Primary SMS Provider
        │
        ├─ CONFIRMED_SUCCESS → stop
        │
        └─ NOT_CONFIRMED_SUCCESS
                     ↓
Secondary SMS Provider
        │
        ├─ CONFIRMED_SUCCESS → stop
        │
        └─ NOT_CONFIRMED_SUCCESS
                     ↓
                   Bale
        │
        ├─ CONFIRMED_SUCCESS → resolved
        │
        └─ NOT_CONFIRMED_SUCCESS
                     ↓
              Attention Required
```

### 11.1 Reporting states

For observability, individual attempts should distinguish at least:

```text
SUCCESS
FAILED
AMBIGUOUS
SKIPPED
```

The routing decision may treat `FAILED` and `AMBIGUOUS` identically for failover because the Owner explicitly accepts possible duplicate delivery.

### 11.2 Owner-accepted duplicate risk

If Provider A times out after actually sending the SMS, Provider B may still be attempted.

Receiving two messages in this rare case is acceptable.

No reconciliation subsystem is required.

---

## 12. Delivery State Contract

Gravity Forms Entry Meta is sufficient for the target baseline.

It stores notification execution/attempt state associated with the Entry.

Required conceptual information includes:

- logical notification Rule/Feed;
- Step/Point context where useful;
- attempt number;
- channel;
- provider;
- status;
- timestamp;
- provider reference where available;
- attention-required flag;
- manual-retry resolution state.

The exact meta schema is an implementation contract to be versioned during implementation.

### 12.1 Best-effort duplicate suppression

Before normal automatic execution, the plugin may check Entry Meta for a prior completed execution and suppress obvious repeat processing.

Atomic exactly-once guarantees are **not required**.

Do not introduce:

- a dedicated delivery table solely for exactly-once behavior;
- database locks;
- compare-and-set infrastructure;
- a unique-claim subsystem;
- distributed locking.

The rare race where simultaneous requests both send is an explicitly accepted risk.

---

## 13. Manual Retry Contract

Unresolved failures are recoverable manually.

A user with the required capability may click a `Retry` action.

Manual Retry:

1. is initiated only by explicit user action;
2. executes immediately in the current request;
3. reruns the configured synchronous delivery chain;
4. records a new attempt;
5. resolves the attention flag when a confirmed success occurs;
6. does not schedule future work.

Required safeguards:

- capability check;
- nonce verification;
- sanitized identifiers;
- no public unauthenticated retry endpoint.

---

## 14. Attention Required Presentation

Operational state belongs to the Notification plugin.

Central presentation belongs to GravityView + Elementor.

### 14.1 Central view

The preferred central operational page is:

```text
Entry Meta
    ↓
GravityView filtered View
    ↓
Elementor presentation
```

Example:

```text
Attention Required

Entry #1482   Student Approved Notification   FAILED
              [View Entry] [Retry]

Entry #1517   Accounting Notification         AMBIGUOUS
              [View Entry] [Retry]
```

The Notification plugin may expose a bounded retry action/shortcode/control that GravityView can render.

### 14.2 Entry Detail fallback

Gravity Forms Entry Detail should retain a small operational status box containing:

- notification attempt summary;
- unresolved status;
- manual Retry action.

This operational fallback must remain usable even if GravityView/Elementor presentation is unavailable.

### 14.3 No custom dashboard subsystem

Do not rebuild, without evidence of a gap:

- custom pagination;
- custom searchable tables;
- custom responsive listing;
- custom page designer.

---

## 15. SRWF Boundaries

For SRWF usage:

```text
Gravity Forms
= canonical Form / Entry data authority

Gravity Flow
= workflow / approval / assignment authority

GravityView
= presentation-only surface
```

Routine GravityView edits or “Needs Review” presentation behavior are not automatically Notification Points.

The generic plugin may remain technically extensible, but the SRWF baseline must not introduce a second workflow authority.

---

## 16. Security and Privacy Boundary

This is a personal/internal plugin; heavyweight security architecture is not a priority.

The following native safeguards remain mandatory where applicable:

- WordPress capability checks;
- nonces for state-changing admin/manual Retry actions;
- sanitization;
- escaping;
- secret-safe logging;
- no unnecessary public endpoints;
- WordPress HTTP API for remote requests.

Do not log provider credentials.

Avoid storing full message bodies and full phone numbers in general-purpose debug logs when they are not needed for operations.

Security work must remain proportional to the actual deployment model.

---

## 17. Explicit Non-Goals — What Not to Build

The following are outside the target baseline unless the architecture is formally reopened under Section 20:

- custom workflow engine;
- custom background queue;
- Action Scheduler notification execution;
- WP-Cron notification execution or delayed retry;
- server-cron dependency;
- automatic delayed retry engine;
- exponential backoff scheduler;
- enterprise event bus;
- generic omnichannel framework;
- custom rules engine duplicating native feed conditional logic;
- custom staff/contact directory;
- SRWF-specific staff classes;
- custom delivery database solely for exactly-once guarantees;
- atomic exactly-once infrastructure;
- provider reconciliation subsystem;
- automatic Pattern-to-Plain translation;
- undocumented/scraped SMS provider integrations;
- a custom central table/dashboard when GravityView + Elementor can present the data;
- automatic mutation of Gravity Flow topology by Point Manager.

---

## 18. Implementation Invariants

Every implementation Work Unit must preserve these invariants:

1. `Gravity Flow Editor` remains topology authority.
2. Flow notification execution uses the selected native feed-step family.
3. A logical notification is not silently split into separate provider-specific workflow Steps.
4. No baseline notification depends on Cron/queue/background execution.
5. Provider failure does not indefinitely block the business workflow.
6. Bale remains a separate channel.
7. Provider failover is capability-aware.
8. Unsupported provider capability is skipped, not auto-translated.
9. Entry Meta remains the baseline delivery-state store.
10. Exactly-once is not a requirement.
11. Manual Retry is explicit and synchronous.
12. `wudm_notification_mobile` and `wudm_bale_chat_id` are external WordPress user-meta contracts, not owned/deleted by this plugin.
13. GravityView/Elementor are presentation surfaces only.
14. No migration phase may leave the old real sender and new real sender intentionally active for the same notification scope.

---

## 19. Acceptance Boundary for the Target Architecture

The architecture itself is considered satisfied only when implementation evidence proves all of the following:

- a normal Gravity Forms notification Rule can execute through the Feed Add-On path;
- a compatible notification Feed can be configured as a Gravity Flow Step;
- a Flow-assigned feed does not independently execute at the normal submission boundary;
- notification Step failure does not strand the business workflow;
- recipient resolution covers the selected entry/user/role/assignee cases;
- staff contact data resolves from the selected user-meta keys;
- synchronous ordered SMS failover works;
- Bale fallback works;
- unsupported provider capability is skipped;
- Entry Meta records the operational result;
- unresolved failures appear as Attention Required;
- manual Retry works without Cron;
- no Action Scheduler/WP-Cron/background notification path remains active;
- no duplicate legacy/new sender path remains active.

These are validation criteria, not claims that the current baseline already passes them.

---

## 20. Reopen Conditions

This architecture MUST NOT be reopened merely because:

- a different model proposes a “cleaner” design;
- a queue is considered more enterprise-grade;
- a dedicated database would provide stronger exactly-once semantics;
- another framework is theoretically more generic;
- a developer prefers hooks over Steps or vice versa.

Reopen only the **smallest affected decision unit** when at least one of the following is true:

1. **Owner requirement change**  
   The Owner explicitly changes a mandatory requirement.

2. **Official-contract invalidation**  
   Current authoritative Gravity Forms / Gravity Flow / WordPress documentation makes a selected mechanism impossible, unsupported, or deprecated.

3. **Implementation contradiction**  
   Direct implementation evidence proves that a locked decision cannot satisfy a mandatory requirement.

4. **Authority conflict**  
   A newer authoritative SRWF contract directly conflicts with this architecture.

A reopen must name:

- the exact decision being reopened;
- the contradicting evidence;
- what remains locked.

---

## 21. Decision Register

| ID | Decision | State |
|---|---|---|
| `D-01` | Gravity Flow Editor owns workflow topology | `CLOSED` |
| `D-02` | Point Manager guides/verifies but does not mutate topology | `CLOSED` |
| `D-03` | `GFFeedAddOn` Feed is the notification Rule mechanism | `CLOSED` |
| `D-04` | Gravity Flow notifications use compatible Feed-based Steps | `CLOSED` |
| `D-05` | One Step/Feed = one logical notification | `CLOSED` |
| `D-06` | Delivery baseline is synchronous | `CLOSED` |
| `D-07` | No Action Scheduler/WP-Cron/background retry baseline | `CLOSED` |
| `D-08` | Ordered failover = Primary SMS → Secondary SMS → Bale | `CLOSED` |
| `D-09` | Unresolved delivery = Attention Required + manual Retry | `CLOSED` |
| `D-10` | Ambiguous SMS may fail over; rare duplicate is acceptable | `CLOSED` |
| `D-11` | Entry Meta is sufficient delivery-state storage | `CLOSED` |
| `D-12` | Atomic exactly-once delivery is not required | `CLOSED` |
| `D-13` | Staff SMS meta = `wudm_notification_mobile` | `CLOSED` |
| `D-14` | Staff Bale meta = `wudm_bale_chat_id` | `CLOSED` |
| `D-15` | Notification plugin has no runtime dependency on WP-Bulk-Import | `CLOSED` |
| `D-16` | GravityView + Elementor provide central Attention presentation | `CLOSED` |
| `D-17` | Entry Detail retains status/manual Retry fallback | `CLOSED` |
| `D-18` | SMS Provider Registry is contract-gated and capability-aware | `CLOSED` |
| `D-19` | Incompatible fallback provider is skipped; no auto-conversion | `CLOSED` |
| `D-20` | Bale is a separate channel, not an SMS provider | `CLOSED` |
| `D-21` | GravityView is presentation-only for SRWF | `CLOSED` |
| `D-22` | Provider failures do not block the business workflow indefinitely | `CLOSED` |

---

## 22. Source Baseline Notes

This architecture was closed against source inspection of:

```text
rezahh107/Gravityflow-SMS-Ippanel
main@405b7bbf06447cdc5bb72e81de18989687221d37
```

The inspected baseline still contains legacy/parallel mechanisms including synchronous direct Gravity Forms handling, Gravity Flow listener/dispatcher behavior, queue/scheduling code, current provider/factory abstractions, settings/admin surfaces, and logging infrastructure.

Those source facts define the starting point only. They do not override this target contract.

See `MIGRATION_PLAN.md` for the evidence-backed disposition of current components.

---

## 23. Official Reference Set

Current official contracts should be re-checked during implementation if their behavior is decision-critical.

- Gravity Forms — `GFFeedAddOn`  
  https://docs.gravityforms.com/gffeedaddon/

- Gravity Flow — Integrations / feed-based add-on Steps  
  https://docs.gravityflow.io/category/integrations/

- Gravity Flow — `Gravity_Flow_Step_Feed_Add_On`  
  https://docs.gravityflow.io/step_feed_class/

- Gravity Flow — `Gravity_Flow_Step`  
  https://docs.gravityflow.io/step-class/

- Gravity Flow — Gravity Forms add-on Step Types  
  https://docs.gravityflow.io/gravity-form-add-on-step-types/

- WordPress — `wp_remote_post()`  
  https://developer.wordpress.org/reference/functions/wp_remote_post/

- WordPress — User Metadata  
  https://developer.wordpress.org/plugins/users/working-with-user-metadata/

- GravityView — Elementor Widget  
  https://www.gravitykit.com/docs/gravityview-pro/advanced-elementor-widget/

- IPPanel Edge API — Pattern send  
  https://ippanelcom.github.io/Edge-Document/docs/send/pattern/

- Bale Bot API  
  https://docs.bale.ai/

---

## 24. Final Architectural Statement

The target is intentionally small:

```text
Native Gravity Forms Feed
+ Native Gravity Flow Feed Step
+ Simple Point guidance/verification
+ Recipient resolution
+ Synchronous multi-provider SMS
+ Bale fallback
+ Entry Meta status
+ Manual Retry
+ GravityView/Elementor presentation
```

Any future implementation that adds infrastructure beyond this shape must first prove that a locked mandatory requirement cannot be met without it.
