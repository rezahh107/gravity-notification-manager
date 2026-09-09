# WU-07 — Attention Required and Entry Detail Presentation Contract

> **Document ID:** `GNM-WU-07-ATTENTION-REQUIRED-ENTRY-DETAIL-1.0.0`  
> **Status:** `IMPLEMENTATION / PR QUALIFICATION`  
> **Work Unit:** `WU-07`  
> **Run:** `RUN-027`  
> **Exact base:** `main@8d246f71dfe8ccce4b2e650f83b3ba961550d487`  
> **Implementation branch:** `lpo/gnm-wu07-run026`  
> **Inspection date:** `2026-09-09`

## 1. Authority and scope

WU-07 adds bounded operator presentation over the existing WU-05 delivery-state and manual Retry contracts. It does not create a second delivery-state authority, workflow authority, Retry engine, case database, queue, scheduler, reconciliation process, or background worker.

```text
Gravity Forms Entry Meta  = delivery-state authority
Feed                       = logical notification Rule authority
Gravity Flow               = workflow topology/routing authority
GravityView + Elementor    = central case presentation only
Gravity Forms Entry Detail = independent per-Entry operational fallback
```

The canonical state key remains `gravity_notify_delivery_state_v1`.

## 2. Current contract snapshot

Repository/runtime baseline used by this implementation:

- exact base `8d246f71dfe8ccce4b2e650f83b3ba961550d487`;
- package PHP floor `>=8.2`;
- blocking repository CI on PHP 8.3;
- real integration workflow on WordPress 7.1 / PHP 8.3 / owner-authorized Gravity Forms and Gravity Flow packages, external-provider-no-send;
- GravityView and Elementor are optional presentation dependencies and are not provisioned by repository CI.

Primary documentation inspected on `2026-09-09`:

- Gravity Forms `gform_entry_detail_meta_boxes`: https://docs.gravityforms.com/gform_entry_detail_meta_boxes/
- Gravity Forms Entry Detail meta boxes: https://docs.gravityforms.com/gform-entry-detail-meta-boxes/
- Gravity Forms Entry Meta with Add-On Framework: https://docs.gravityforms.com/using-entry-meta-with-the-addon-framework/
- Gravity Forms `gform_get_meta()` / `gform_update_meta()`: https://docs.gravityforms.com/gform-get-meta/ and https://docs.gravityforms.com/gform-update-meta/
- Gravity Forms GFAPI Entry/Feed access: https://docs.gravityforms.com/getting-entries-with-the-gfapi/ and https://docs.gravityforms.com/managing-add-on-feeds-with-the-gfapi/
- WordPress authenticated `admin_post_{$action}`: https://developer.wordpress.org/reference/hooks/admin_post_action/
- WordPress capability/nonce/safe redirect: https://developer.wordpress.org/reference/functions/current_user_can/ , https://developer.wordpress.org/reference/functions/wp_verify_nonce/ , https://developer.wordpress.org/reference/functions/wp_safe_redirect/
- GravityView supported entry filtering: https://docs.gravitykit.com/article/1060-modifying-entries-displayed-in-a-view and https://codex.gravitykit.com/hook_gravityview_view_entries/
- GravityView Custom Content, merge tags and shortcode: https://docs.gravitykit.com/article/79-using-the-custom-content-field , https://docs.gravitykit.com/article/76-merge-tags , https://docs.gravitykit.com/article/67-shortcodes-gravityview
- Elementor Shortcode widget: https://elementor.com/help/shortcode-widget/

Deprecated `gravityview_fe_search_criteria` is not used.

## 3. Shared truthful presentation model

`GravityNotify\Presentation\DeliveryStatePresentationReader` is read-only. It reads through the same `DeliveryStateStoreInterface` used by WU-05 and delegates complete target trust/coherence validation to `DeliveryStateManager::target_state()`.

It projects only bounded operator-safe facts: Entry/Form/Feed identifiers, safe Feed name/channel, `RESOLVED` / `UNRESOLVED`, Attention Required, WU-05 Retry eligibility, last execution type/time, and exact attempt statuses `SUCCESS | FAILED | AMBIGUOUS | SKIPPED`.

It deliberately omits provider identifiers/references, recipient values, message bodies, credentials, tokens, raw provider bodies, broad Entry payloads and raw diagnostics.

Missing state is explicit and does not fabricate a target. Malformed/untrusted persisted state is never treated as resolved. Malformed roots/targets remain Attention Required for operator visibility while Retry remains unavailable unless the existing WU-05 validator reports `allowed`. Multiple Feed targets remain distinct and deterministic.

## 4. Gravity Forms Entry Detail surface

WU-07 registers one side meta box through `gform_entry_detail_meta_boxes`. Per trusted target it shows safe Feed name/ID, channel, final state, textual Attention Required `Yes`/`No`, Retry eligibility, last execution type/time, and exact attempt-status summary.

Malformed/missing state has explicit non-optimistic copy. Technical identifiers/statuses use `<bdi dir="ltr">` for readable Persian/RTL mixed-direction output. State meaning never depends on color alone.

Rendering reads state only. It does not send, write Entry Meta, mutate Gravity Flow, or perform provider I/O.

## 5. Explicit manual Retry from Entry Detail

WU-07 reuses the existing WU-05 authenticated action:

```text
action = gravity_notify_retry_notification
method = POST
```

The control is shown only when the target is trusted, Attention Required is true, WU-05 `retry_eligibility()` returns `allowed`, the user has `gravityforms_edit_entries`, and the current Feed passes the same Feed identity/form-scope/active/meta/Add-On validation as the WU-05 handler.

The nonce remains target-bound to `gravity_notify_retry_<entry_id>_<feed_id>`. The WU-05 dispatch path still rechecks POST, capability, nonce, Entry, Form, Feed applicability and persisted state before any send/mutation.

After a valid Retry outcome, the handler uses `wp_safe_redirect()` with HTTP 303 to a server-built Gravity Forms Entry Detail URL; no caller-controlled return URL is trusted. Entry Detail re-reads authoritative Entry Meta before presenting the result:

- `retry_success` is shown resolved only if fresh state is trusted `RESOLVED` with Attention Required false;
- `retry_unresolved` stays Attention Required when fresh state says so;
- a proven post-send persistence failure never claims persisted success;
- stale/ineligible/malformed state failures remain fail-closed errors and are not represented as an executed Retry.

GET, registration and render-only paths never invoke delivery.

## 6. GravityView Attention Required integration

The canonical state is nested JSON in Gravity Forms Entry Meta. WU-07 does not add a convenience meta mirror, custom table, cache authority, SQL substring inference, or state mutation merely to make GravityView filtering easier.

The integration uses current supported `gravityview/view/entries`, documented for logic that cannot be safely represented in SQL. Candidate Entry IDs are evaluated through the shared authoritative reader; only Entries with `entry_requires_attention === true` are retained. Malformed state remains visible as Attention Required; resolved or missing state is excluded.

GNM never invents production View IDs. A real site opts a View in with its actual ID:

```php
add_filter(
    'gravity_notify_attention_required_view_ids',
    static function ( array $ids ): array {
        $ids[] = YOUR_REAL_GRAVITYVIEW_ID;
        return $ids;
    }
);
```

A GravityView Custom Content field can use:

```text
[gnm_attention_target entry_id="{entry_id}"]
```

The shortcode is capability-protected, read-only, privacy-bounded and links to Gravity Forms Entry Detail when Form identity can be established.

### Pagination/count limitation

GravityView documents that post-fetch `gravityview/view/entries` filtering does not recalculate the original query count/pagination. WU-07 therefore does not claim authoritative post-filter page counts. Apply ordinary supported View/Form/workflow criteria first to keep the candidate set bounded; WU-07 is the final authoritative Attention predicate for returned candidates.

A globally exact cross-page Attention Required count would require a separately approved query/index design. WU-07 does not create a mirror state authority to simulate it. Gravity Forms Entry Detail remains independently usable even when GravityView is absent.

## 7. Elementor boundary

No direct Elementor PHP dependency is introduced. Elementor remains presentation/layout only. Where Elementor is used, add its stable **Shortcode** widget and embed the real View:

```text
[gravityview id="YOUR_REAL_GRAVITYVIEW_ID"]
```

Use the actual production View ID. WU-07 does not invent a template/page/View ID and does not claim live Elementor configuration from repository CI. If Elementor is absent, GravityView can render through normal shortcode/page integration. If GravityView is absent, Gravity Forms Entry Detail remains the operational fallback.

## 8. Privacy, accessibility and side-effect guarantees

WU-07 uses semantic text in addition to host styling, normal keyboard-operable links/buttons, WordPress focus behavior, and LTR isolation for technical values in RTL context. It exposes no recipient PII/full phone lists, message bodies, credentials, tokens, raw provider bodies or broad Entry payloads.

Presentation/filter/shortcode evaluation performs no provider send or workflow mutation. Only the explicit authenticated Retry POST can invoke the existing synchronous delivery chain.

## 9. Optional GNM Overview aggregate

Not implemented. A truthful aggregate would otherwise require repeated broad Entry scans or a new index/mirror mechanism, which is unnecessary for WU-07 and conflicts with minimum-sufficient complexity.

## 10. Deterministic verification mapping

Focused WU-07 tests cover:

- `T-WU07-01`: resolved/unresolved truth, exact `AMBIGUOUS`/`SKIPPED`, malformed/partial state, multi-target distinction and privacy projection;
- `T-WU07-02`: Entry Detail registration/rendering, missing/malformed handling and read-only zero state writes;
- `T-WU07-03`: Retry control eligibility reuses WU-05 state/capability/Feed applicability; existing WU-05 tests continue to prove POST/capability/nonce/Entry/Feed/state fail-closed behavior and GET no-send;
- `T-WU07-04`: result notice re-reads state and never claims optimistic resolution;
- `T-WU07-05`: Attention predicate for resolved/unresolved/malformed/missing/multi-target candidates;
- `T-WU07-06`: bounded GravityView/Elementor-facing content and optional-dependency graceful behavior;
- `T-WU07-07`: semantic text, LTR isolation and privacy-safe output;
- `T-WU07-08`: current WU-00 through WU-06 regression in blocking CI;
- `T-WU07-09`: exact-head WU-00 Qualification;
- `T-WU07-10`: exact-head Real GF Flow Integration.

Repository CI does not provision GravityView/Elementor, so live View/page setup is not faked; deterministic seams are tested and the external setup boundary is documented.

## 11. Bounded legacy differential review

Performed after the initial implementation/test pass as required by `AGENTS.md`.

```text
Legacy asset/tag/path:
legacy-source-pre-greenfield-2026-09-02 @ 7556f86ecc65f37d34d9563ce2087f16235bbca5
includes/Admin/Logs_Table.php

Material finding: NO

Observed legacy behavior:
A custom paginated/searchable log table backed by legacy logging state and displaying
recipient/message/error fields.

Decision:
Do not port it. It duplicates the closed GravityView/Elementor presentation boundary,
introduces a different state source, and exposes data outside WU-07 privacy requirements.

Exact behavior incorporated: None.
Intentionally not incorporated: legacy custom list table, log-state authority,
recipient/message display and legacy search/pagination architecture.
```

## 12. External/manual verification checklist

On a real site with GravityView/Elementor available:

1. Create/select the real operational GravityView.
2. Add its actual View ID through `gravity_notify_attention_required_view_ids`.
3. Add safe columns plus `[gnm_attention_target entry_id="{entry_id}"]`.
4. Verify known `UNRESOLVED` appears and known `RESOLVED` does not within the View candidate set.
5. Verify malformed state is Attention Required rather than resolved.
6. Verify multiple Feed targets remain distinct.
7. Open Entry Detail and confirm current state/attempt truth.
8. With authorized test fixtures only, explicitly Retry and confirm redirected Entry Detail reflects freshly persisted state.
9. In Elementor, embed the real GravityView shortcode and verify responsive RTL/mixed-LTR readability.
10. Confirm ordinary View/Entry rendering performs no external send or Gravity Flow mutation.

These are external presentation checks and are not represented as CI PASS unless that environment is actually provisioned.

## 13. Exact-head qualification expectation

The final pushed PR Head must pass every blocking step in `.github/workflows/wu-00-ci.yml`, including the dedicated WU-07 focused target, and the repository's `Real GF Flow Integration` workflow on the same exact Head. No validation from an earlier commit qualifies a later final Head.
