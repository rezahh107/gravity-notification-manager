# WU-01 — Gravity Forms Feed Contract Snapshot

> Project: Gravity Notification Manager  
> Work Unit: `WU-01`  
> Inspection date: `2026-09-05`  
> Base commit: `2a20fa97d0a4708dce13c27f7cbcc1840541cc69`

## Purpose

This snapshot records the current Gravity Forms contracts used to build the greenfield Feed foundation. WU-01 defines configuration and the synchronous framework boundary only; recipient resolution, provider transport, Gravity Flow execution, delivery state, retry, cutover, and real sends remain later Work Units.

## Current Gravity Forms contracts

Official sources inspected:

- `https://docs.gravityforms.com/gffeedaddon/`
- `https://docs.gravityforms.com/gfaddon/`
- `https://docs.gravityforms.com/_async_feed_processing/`
- `https://docs.gravityforms.com/gform_is_feed_asynchronous/`
- `https://docs.gravityforms.com/creating-a-feed-settings-page/`

The WU-01 implementation relies on these current contracts:

1. `GFFeedAddOn` is the supported feed-based Add-On Framework base class.
2. `feed_settings_fields()` defines Feed Settings with the standard Settings API structure.
3. The current `GFFeedAddOn` Feed Settings example exposes native Gravity Forms merge-tag UI for a message textarea with the `merge-tag-support` class; GNM uses that native facility instead of a custom merge-tag engine.
4. `process_feed( $feed, $entry, $form )` is the feed-processing boundary.
5. Native Feed conditional logic uses a settings field whose type is `feed_condition`.
6. `GFFeedAddOn::$_async_feed_processing` is boolean and defaults to `false`; WU-01 sets it explicitly to `false` so the closed synchronous architecture remains visible in code.
7. Gravity Forms can support asynchronous feed processing, but GNM intentionally does not enable it for this target.

## Closed WU-01 decisions

- One Feed represents one logical notification.
- Feed condition behavior is native Gravity Forms behavior, not a custom Rule engine.
- Message composition exposes the native Gravity Forms merge-tag UI; legacy custom message replacement is not transplanted.
- SMS and Bale are distinct channel choices.
- Fallback is configuration intent only in WU-01; transport/provider selection remains WU-02.
- No Pattern-to-Plain semantic conversion is performed.
- `process_feed()` performs no provider, SMS, Bale, HTTP, queue, retry, or delivery-state action in WU-01.
- Feed metadata uses explicit schema version `1` without adding a separate database or state authority.
- Production cutover/entrypoint wiring is not performed in this Work Unit.

## Validation contract

The exact final WU-01 Head must pass the existing GitHub-hosted qualification path:

1. `composer validate --strict`
2. locked dependency installation
3. optimized autoload generation
4. full PHPUnit suite, including WU-00 no-send regression tests and WU-01 Gravity Forms tests
5. PHPCS over `src` and `tests`

Only executed checks on the exact final commit count as PASS.
