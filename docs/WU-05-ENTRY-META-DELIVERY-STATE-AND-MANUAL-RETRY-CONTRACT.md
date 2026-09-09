# WU-05 — Entry Meta Delivery State and Manual Retry Contract

> **Document ID:** `GNM-WU-05-ENTRY-META-DELIVERY-STATE-RETRY-1.0.0`  
> **Status:** `IMPLEMENTED / PR QUALIFICATION`  
> **Work Unit:** `WU-05`  
> **Code implementation Head reviewed for this contract:** `7e57f724014ceae4a78509fb95ea659777c9a2a9`

## 1. Authority and boundary

WU-05 adds lightweight delivery-state observability and an explicit synchronous manual Retry seam without changing the closed delivery architecture.

Gravity Forms Entry Meta is the delivery-state authority. Feed configuration remains the logical notification Rule authority, and Gravity Flow remains workflow topology/routing authority.

WU-05 does **not** add a queue, Action Scheduler, WP-Cron, server cron, worker, delayed retry, custom delivery table, heavy exactly-once locking, provider reconciliation, migration/cutover behavior, or the WU-07 Entry Detail/GravityView/Elementor presentation.

## 2. Entry Meta schema ownership

The canonical Entry Meta key is:

```text
gravity_notify_delivery_state_v1
```

The value is UTF-8 JSON written through Gravity Forms Entry Meta APIs. The root document identifies itself with:

```text
namespace: gravity_notify.delivery_state
schema_version: 1
entry_id: <positive Entry ID>
notifications: <logical Feed targets>
```

Each logical Feed target is stored under `feed:<feed_id>` and contains the bounded contract fields needed for WU-05 decisions and inspection:

- Entry, Form and Feed identifiers;
- safe Feed name and channel identity;
- `final_status`;
- `attention_required`;
- last execution sequence;
- whether a successful manual Retry resolved the target;
- append-only execution history for the retained valid state;
- manual Retry history.

A target is trusted only when the complete target-level contract is present with valid bounded types/identities and coherent final state. In particular:

```text
RESOLVED   => attention_required === false
UNRESOLVED => attention_required === true
```

Malformed or partially missing state is never trusted for duplicate suppression or manual Retry authorization. Ordinary processing may recover into a fresh valid bounded state document and records the safe `prior_state_malformed` reason; manual Retry fails closed when eligible state cannot be established.

## 3. Execution and attempt history

Each execution record contains, at contract level:

- monotonically derived `execution_sequence`;
- execution type (`ORDINARY`, `MANUAL_RETRY`, or `DUPLICATE_SUPPRESSED`);
- timestamp;
- channel;
- ordered attempt list;
- safe skip/reason list;
- `delivery_succeeded`;
- execution final status;
- execution Attention Required value.

Each persisted transport attempt contains:

- execution and attempt sequence numbers;
- timestamp;
- exact attempt status;
- channel;
- provider identifier where applicable;
- capability identifier where applicable;
- bounded provider reference values where available.

The attempt status remains exactly one of the existing transport truths:

```text
SUCCESS
FAILED
AMBIGUOUS
SKIPPED
```

WU-05 does not relabel `AMBIGUOUS` as `FAILED`. A transport failure with no provider acceptance/rejection response remains `AMBIGUOUS`; the existing synchronous fallback behavior remains authoritative.

A failure before any provider attempt is recorded as an unresolved execution with safe skip/reason information. No fake provider attempt is invented.

## 4. Final state and Attention Required

A confirmed logical delivery success produces:

```text
final_status: RESOLVED
attention_required: false
```

If the configured synchronous execution chain completes without confirmed logical success, the target produces:

```text
final_status: UNRESOLVED
attention_required: true
```

This state does not claim provider reconciliation and does not turn ambiguous transport evidence into a confirmed failure.

## 5. Best-effort duplicate suppression

Ordinary sequential Feed processing may suppress another send when the same Entry/Feed target is already confirmed `RESOLVED` with `attention_required = false`.

A suppressed ordinary execution is recorded as `DUPLICATE_SUPPRESSED` with no transport attempt. This is intentionally best-effort only:

- no database lock;
- no compare-and-set claim;
- no transaction or distributed coordination;
- no exactly-once guarantee.

A rare concurrent duplicate remains an accepted architecture trade-off. Explicit manual Retry bypasses this ordinary duplicate-suppression decision so that recovery is not accidentally blocked.

## 6. Manual Retry contract

The callable Retry seam is an authenticated WordPress `admin-post` action and is not the WU-07 UI.

Retry is synchronous in the current request and must pass all of these checks before any send/state mutation:

1. request method is `POST`;
2. current user has `gravityforms_edit_entries`;
3. Entry and Feed identifiers are canonical positive decimal identifiers after bounded request sanitization;
4. nonce verifies against `gravity_notify_retry_<entry_id>_<feed_id>`;
5. the Entry exists and identifies the same Entry ID;
6. the Entry has a valid Form and that Form exists;
7. the Feed exists, matches the requested Feed ID, is applicable to the Entry Form, is active when an active flag is present, has valid Feed meta, and belongs to the GNM Add-On;
8. persisted WU-05 state is valid and the target currently requires Attention Required recovery.

Missing capability, missing/invalid nonce, malformed identifiers, missing Entry/Form/Feed, wrong Feed identity/scope, or malformed/missing Retry state fails closed.

A valid Retry calls the same current `NotificationFeedAddOn` / `NotificationFeedProcessor` synchronous notification chain used by ordinary processing; it does not duplicate provider/routing logic. The new execution is appended to history and earlier valid history is preserved.

Confirmed Retry success sets the target to `RESOLVED`, clears `attention_required`, and records the Retry as resolved. A Retry with no confirmed success appends truthful attempt history and leaves `attention_required = true`.

A manual Retry is reported as `retry_success` or `retry_unresolved` only when the authoritative Entry Meta transition for that Retry was persisted successfully. If transport executes but the state write fails, the request-local `NotificationExecutionResult` keeps the truthful transport result and the existing safe `delivery_state / persistence_failed` marker, while the Retry seam returns the existing state-error path instead of claiming a persisted Retry outcome. This rule does not redefine ordinary `process_feed()` transport success: ordinary delivery semantics remain transport-truthful even when its state write fails.

Handler registration, GET/render-only requests and read-only inspection do not perform delivery. Retry schedules no future work.

## 7. Persistence privacy boundary

WU-05 Entry Meta must not persist credentials, provider secrets, raw provider error bodies, message/recipient payloads, or sensitive transport diagnostics.

Only bounded execution facts and safe identifiers/references required by the state contract are retained. Raw transport diagnostic/error data remains outside this Entry Meta document.

## 8. Current official contract snapshot

The implementation mechanism was checked against current primary documentation before qualification:

- Gravity Forms `gform_update_meta()` — https://docs.gravityforms.com/gform-update-meta/
- Gravity Forms `gform_get_meta()` — https://docs.gravityforms.com/gform-get-meta/
- Gravity Forms Entry Meta / Add-On Framework guidance — https://docs.gravityforms.com/using-entry-meta-with-the-addon-framework/
- Gravity Forms Feed access through GFAPI — https://docs.gravityforms.com/managing-add-on-feeds-with-the-gfapi/
- Gravity Forms Entry access through GFAPI — https://docs.gravityforms.com/getting-entries-with-the-gfapi/
- WordPress authenticated `admin_post_{$action}` — https://developer.wordpress.org/reference/hooks/admin_post_action/
- WordPress `current_user_can()` — https://developer.wordpress.org/reference/functions/current_user_can/
- WordPress `wp_verify_nonce()` — https://developer.wordpress.org/reference/functions/wp_verify_nonce/
- WordPress `sanitize_text_field()` — https://developer.wordpress.org/reference/functions/sanitize_text_field/

Nonce verification is not treated as authorization; capability and nonce checks are both required.

## 9. WU-07 presentation boundary

WU-05 exposes the state and secure Retry capability only. WU-07 owns the final Gravity Forms Entry Detail status/Retry presentation and any GravityView or Elementor presentation. WU-05 does not add those surfaces.

## 10. Bounded legacy differential record

```text
Work Unit: WU-05
New implementation Head: b06b91466cedcf710c268405cc65f8548bd94287
Legacy asset/tag/path inspected: legacy-source-pre-greenfield-2026-09-02 @ 7556f86ecc65f37d34d9563ce2087f16235bbca5; includes/Services/LockManager.php; includes/Integration/Event_Queue.php; docs/SALVAGE_REFERENCE.md RETIRE boundaries for old delivery-log state, EventSnapshot/EventState/EventType pipeline, and automatic retry/backoff/scheduler behavior
Material difference found: NO
Finding: The bounded legacy assets expose heavy locking, asynchronous queue/scheduler/retry, and retired event/log-state architecture. They provide no current-valid small WU-05 edge case missing from the greenfield Entry Meta state/manual synchronous Retry implementation.
Current official contract checked: Gravity Forms Entry Meta and GFAPI contracts; WordPress authenticated admin-post, current_user_can(), wp_verify_nonce(), and request sanitization contracts listed in section 8.
Target architecture compatibility: Legacy lock/queue/scheduler/log authority is incompatible with the closed WU-05 architecture; no compatible missed behavior was found.
Decision: NO_MATERIAL_FINDING
Exact behavior incorporated (if any): None.
Legacy behavior intentionally NOT incorporated: LockManager/exactly-once locking; Action Scheduler/WP-Cron queue; automatic delayed retry/backoff; legacy delivery log table as state authority; EventSnapshot/EventState/EventType pipeline; legacy orchestration.
Tests added/updated: None from the differential; existing deterministic WU-05 tests remain the behavior authority.
Revalidation result: WU-00 Qualification run 34321427015 completed SUCCESS on exact code Head b06b91466cedcf710c268405cc65f8548bd94287; the documentation-only final Head is requalified separately by the PR exact-head gates.
```

## 11. Final rule

WU-05 remains a lightweight, Entry-Meta-owned, synchronous recovery mechanism. State records what the existing execution chain actually did; manual Retry explicitly reruns that same chain under capability, nonce, identifier, target, and state validation. It introduces no background delivery architecture and no presentation scope owned by later Work Units.
