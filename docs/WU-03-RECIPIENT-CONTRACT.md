# WU-03 Recipient Contract Snapshot

Status: `IMPLEMENTATION_CONTRACT / WU-03 / CURRENT-SOURCE SNAPSHOT`

Snapshot date: 2026-09-06

Scope: only current Gravity Forms Entry/form access, WordPress user/role/user-meta access, and Gravity Flow current-step assignee access used by WU-03. This document does not redefine WU-01 Feed metadata or WU-02 transport semantics.

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

Official sources:

- https://docs.gravityflow.io/the-workflow-orchestration-api/
- https://docs.gravityflow.io/step-class/
- https://docs.gravityflow.io/assignee-class/

Facts relied upon:

- The documented local orchestration API is `new Gravity_Flow_API( $form_id )`; `get_current_step( $entry )` returns the current `Gravity_Flow_Step` when available.
- `Gravity_Flow_Step::get_assignees()` returns current-step assignee objects.
- `Gravity_Flow_Assignee::get_type()` and `get_id()` expose documented assignee identity. WU-03 supports `user_id`, `role`, and `email` only when the email maps to a WordPress user; all channel contact lookup then uses the same closed WordPress user-meta keys above.
- Missing current-step context, missing assignees, or unsupported assignee types become structured unresolved/skip results. WU-03 does not infer routing, mutate assignments, or scrape internal workflow state.

## WU-03 normalization boundary

WU-03 performs only generic normalization required for stable recipient identity: scalar conversion, whitespace trimming, positive field/input selector syntax, stable user-ID ordering/deduplication, and rejection of empty destinations. It intentionally does not copy legacy country/provider phone normalization or WU-02 provider acceptance rules.

## Result and side-effect boundary

`GravityNotify\Recipient\ResolutionResult` carries the requested channel, configured source type, resolved destinations, and safe skip classifications. No Entry Meta, custom table, log subsystem, provider I/O, Bale API call, workflow mutation, retry, queue, cron, or background work is performed.

## Legacy differential review

Pending until the initial greenfield WU-03 implementation has passed the repository PHPUnit/PHPCS/Composer checks, as required by `docs/SALVAGE_REFERENCE.md`.
