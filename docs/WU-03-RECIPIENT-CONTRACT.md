# WU-03 Recipient Contract Snapshot

Status: `IMPLEMENTATION_CONTRACT / WU-03 / CURRENT-SOURCE SNAPSHOT`

Snapshot date: 2026-09-08

Scope: only current Gravity Forms Entry/form access, WordPress user/role/user-meta access, and Gravity Flow explicitly selected-Step assignee access used by WU-03/WU-04. This document does not redefine WU-01 Feed metadata or WU-02 transport semantics.

## Gravity Forms

Official source: https://docs.gravityforms.com/entry-object/

Facts relied upon:

- The Gravity Forms Entry object is an associative array whose submitted values are keyed by field/input IDs.
- The documented field-object path is `GFAPI::get_field( $form_or_id, $field_id )` followed by `GF_Field::get_value_export( $entry, $input_id )` when a specific input value is required.
- WU-03 therefore interprets `recipient_source_value` for `entry_field` only as a positive Gravity Forms field ID such as `7` or input ID such as `8.2`. It does not execute merge tags or arbitrary code.

## WordPress users, roles, and contact meta

Official sources:

- https://developer.wordpress.org/reference/functions/get_user_by/
- https://developer.wordpress.org/reference/functions/get_users/
- https://developer.wordpress.org/reference/functions/get_user_meta/

Facts relied upon:

- `get_user_by()` resolves a user by supported identity fields including `id`, `login`, `email`, and `slug`.
- `get_users()`/`WP_User_Query` supports role filtering and can return user IDs. WU-03 requests IDs ordered by `ID ASC` and also sorts/deduplicates before contact lookup for deterministic behavior.
- `get_user_meta( $user_id, $key, true )` reads one user-meta value. WU-03 reads only `wudm_notification_mobile` for `sms` and `wudm_bale_chat_id` for `bale`.
- No `plato_user_mobile` fallback and no WP-Bulk-Import runtime dependency is used.

## Gravity Flow

Official/current sources:

- https://docs.gravityflow.io/the-workflow-orchestration-api/
- https://docs.gravityflow.io/step-class/
- https://docs.gravityflow.io/gravityflow_step_assignees/
- https://docs.gravityflow.io/the-workflow-step-framework/

Owner-verified primary source: Gravity Flow 3.1.0 `gravityflow.zip`, SHA-256 `ac0573b75831380417a21a455176e25eb746d718bbbd0bb70d6da6f48cba5404`.

Facts relied upon:

- For `recipient_source_type = flow_assignee`, the existing `recipient_source_value` is the authoritative explicit positive Gravity Flow Step ID. It is not a user/role identifier in this source mode.
- Gravity Flow's documented Step contract uses Entry-bound exact-Step lookup via `gravity_flow()->get_step( $step_id, $entry )`; the owner-verified 3.1.0 `Gravity_Flow_API::get_step( $step_id, $entry )` delegates to that supported exact-Step path. WU-03 uses that API with the current Entry context.
- `Gravity_Flow_Step::get_assignees()` returns assignee objects for the selected Step.
- `Gravity_Flow_Assignee::get_type()` and `get_id()` expose assignee identity. WU-03 supports `user_id`, `role`, and `email` only when the email maps to a WordPress user; all channel contact lookup then uses the same closed WordPress user-meta keys above.
- WU-03 never infers the assignee source from the current Notification Feed Step, previous Step, workflow/list/history order, routing adjacency, or current user.
- Missing/invalid Step selectors, unavailable selected Steps, unavailable assignee APIs/collections, empty assignee collections, or unsupported assignee types become structured unresolved/skip results. WU-03 does not mutate assignments or workflow topology.

## WU-03 normalization boundary

WU-03 performs only generic normalization required for stable recipient identity: scalar conversion, whitespace trimming, positive field/input selector syntax, positive integer Flow Step selector syntax, stable user-ID ordering/deduplication, and rejection of empty destinations. It intentionally does not copy legacy country/provider phone normalization or WU-02 provider acceptance rules.

## Result and side-effect boundary

`GravityNotify\Recipient\ResolutionResult` carries the requested channel, configured source type, resolved destinations, and safe skip classifications. No Entry Meta, custom table, log subsystem, provider I/O, Bale API call, workflow mutation, retry, queue, cron, or background work is performed.

## Legacy differential review

Work Unit: `WU-03`

New implementation Head reviewed: `c15bbed22c1939a5bd3ac992085e96db91695030`

Legacy authority: tag `legacy-source-pre-greenfield-2026-09-02`, commit `7556f86ecc65f37d34d9563ce2087f16235bbca5`

Legacy assets inspected:

- `includes/Services/RecipientResolver.php`
- `includes/Services/PhoneNumberNormalizer.php`

Material difference found: `NO`

Decision: `NO_MATERIAL_FINDING`

Findings checked:

- Legacy `fixed` SMS handling split comma/whitespace-delimited values into multiple numbers. The current WU-01 metadata/UI and WU-03 contract define one `recipient_source_value` for a singular `Fixed target`; WU-03 therefore does not import legacy multi-value parsing without a current requirement.
- Legacy `submitter` resolution is not part of the closed WU-01 recipient source set and is not imported.
- Legacy user-contact fallback keys `billing_phone`, `mobile`, and `plato_user_mobile` conflict with the closed WU-03 contact authority and are intentionally rejected.
- Legacy recipient caching is unnecessary for the bounded resolver and would add state/behavior not required by WU-03.
- Legacy `PhoneNumberNormalizer` delegated normalization to the selected provider. WU-03 intentionally does not recreate provider-dependent normalization because provider acceptance/transport semantics remain owned by WU-02.
- Legacy acceptance of raw scalar assignee representations is not imported; WU-03 uses only the documented current Gravity Flow assignee object contract.

Current official contract checked: the Gravity Forms, WordPress, and Gravity Flow sources listed above, plus the existing WU-01 `Fixed target` metadata boundary and WU-02 already-resolved transport boundary.

Target architecture compatibility: `PASS`

Exact behavior incorporated: none from legacy. RUN-020 supersedes only the earlier current-Step wording with the Owner-selected explicit configured Step-ID semantics.

Legacy behavior intentionally NOT incorporated: multi-fixed parsing, submitter source, legacy contact-key fallbacks, recipient cache, provider-driven normalization, undocumented/scalar assignee inference, and any current/previous/order/history/current-user Step heuristic.

Tests added/updated after review: RUN-020 adds explicit-Step selector, exact-Step, identity, failure, integration, and anti-heuristic coverage.

Revalidation result: pending exact-final-head validation after the RUN-020 repair is committed.
