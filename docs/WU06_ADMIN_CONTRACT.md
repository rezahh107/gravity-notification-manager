# WU-06 Admin Contract Snapshot

Status: implemented and qualified through the WU-06 focused greenfield gate; bounded legacy differential completed; PRI-FND-001 inactive-Step truth repaired on PR #9.

## Current contract snapshot — 2026-09-09

- Repository base: `main@60ebb19433ebb40d24d9d8ee20067afafb9d36eb`.
- WordPress test/runtime baseline: WordPress 7.1 (`.wp-env.json`), PHP 8.3; Composer requires PHP >=8.2.
- WordPress official contracts re-checked: `add_menu_page()` / `add_submenu_page()`, `register_setting()` with `sanitize_callback`, Settings API form handling, `admin_enqueue_scripts`, capability checks, `check_admin_referer()`, contextual escaping, and the registered `wp-theme` design-token stylesheet.
- WordPress admin implementation is native PHP; no React runtime and no experimental customizable Widget Dashboard API are used.
- Gravity Flow official contracts re-checked: local `Gravity_Flow_API::get_steps()` read access and public Step helpers `get_type()`, `get_setting()`, `get_id()`, `get_name()`, and `is_active()`. `Gravity_Flow_Step::is_active()` is the authoritative current Step active-state read; the documented Feed-Step contract uses the `feed_<id>` selection setting shape inspected by Point Manager.
- Gravity Flow operator navigation targets the documented Form Settings → Workflow location. A direct admin path is rendered only when the supported local Flow API is present; otherwise existing bounded setup guidance remains visible.

Official references inspected include current WordPress Developer Resources for administration menus, Settings API, `register_setting()`, `check_admin_referer()`, `admin_enqueue_scripts`, and `@wordpress/theme`, plus current Gravity Flow documentation for the Workflow Orchestration API, Step class, Workflow Step object, permissions, and Form Settings → Workflow configuration.

## Information architecture and capability

GNM exposes exactly four new product surfaces under one native WordPress admin parent:

1. Overview
2. Notification Points
3. Settings
4. Help & Diagnostics

All surfaces and `Check Again` use `manage_options`; render/action callbacks re-check capability. WU-06 admin registration occurs after Composer autoload but before the legacy Gravity Forms / Gravity Flow runtime dependency gate so Help & Diagnostics can render understandable missing-dependency states.

The legacy runtime remains present until separately owned cutover/retirement Work Units. WU-06 does not migrate legacy settings, retire legacy runtime, or enable a second greenfield production sender.

## Point Manager authority and status vocabulary

Point Manager is read/verify/guidance only. Its source interface contains only `forms()`, `feeds()`, and `workflow_placements()` reads. It has no create/insert/reorder/delete/update/activate Flow API.

Status vocabulary implemented here is:

- `CONFIGURED`
- `NEEDS_SETUP`
- `DISABLED`
- `NOT_APPLICABLE`

Feed metadata comes from the canonical greenfield Feed schema; Flow placement comes from current Gravity Flow Step configuration. Each matching GNM placement preserves the Step ID/name, selected Feed IDs, and current boolean active-state truth read from `Gravity_Flow_Step::is_active()`. Inactive matching placements remain visible to the operator; they are not filtered out or collapsed into a missing-placement state. Missing Flow dependency is reported as unavailable rather than guessed.

A single active matching GNM Flow Feed Step may be `CONFIGURED`. A single inactive matching Step is `NEEDS_SETUP` with guidance to activate or correct that existing Step in Gravity Flow; it is never `CONFIGURED`. A Flow-assignee recipient Feed without any matching GNM Flow Feed Step remains `NEEDS_SETUP` with missing-Step guidance. A non-Flow recipient Feed without any Flow placement remains a valid normal Gravity Forms submission Feed. Multiple matching GNM Flow placements remain inconsistent and `NEEDS_SETUP` regardless of their active/inactive mixture; GNM never repairs topology automatically.

## Check Again

`Check Again` is an explicit POST to `admin-post.php`. It requires `manage_options`, strictly positive form/feed identifiers, and a target-bound WordPress nonce. It creates a fresh inspector and re-reads current Feed/Flow truth before returning to Point Manager.

No notification provider, HTTP transport, delivery-state writer, or Gravity Flow topology-write API is called by Check Again.

## Settings ownership and privacy

WU-06 owns one bounded namespaced option: `gravity_notify_settings`.

Current fields prepare only already-approved greenfield runtime inputs:

- `ippanel_api_key`
- `sms_from_number`
- `bale_bot_token`

WU-06 performs no legacy migration. Secret inputs are write-only in HTML: stored API keys/tokens are never echoed back. Diagnostics expose readiness status only. Saving/rendering settings performs no connection test and no SMS/Bale send.

## Help & Diagnostics

Diagnostics show only privacy-safe product/runtime availability, PHP/WordPress version, provider readiness, Feed/Flow integration availability, and Point counts. They exclude credentials, recipient values, Entry contents, raw provider bodies, and provider requests. Missing optional dependencies degrade to explicit availability states rather than fatals.

## Assets, RTL/LTR, accessibility

The single WU-06 stylesheet is enqueued only for captured GNM admin screen hooks. Where available it consumes WordPress `wp-theme` semantic `--wpds-*` tokens with conservative CSS fallbacks.

Layout uses logical properties and responsive grids. Technical values use explicit `dir="ltr"` plus `unicode-bidi: isolate`; surrounding WordPress direction is inherited for Persian RTL. Important states render textual status in addition to visual treatment. Native links/buttons/forms/labels preserve keyboard semantics and explicit `:focus-visible` styling is provided. WU-06 introduces no animation or transition, so no reduced-motion override is required.

Automated tests prove these code/style contracts and deterministic state derivation; they do not claim pixel-level, contrast-in-browser, screen-reader, or assistive-technology validation.

## Bounded legacy differential

Work Unit: `WU-06`

Initial greenfield Head: `04f3af0691ed2c7d6ea4ddd5dbe978d89ac90ec6`

Initial focused validation: GitHub Actions `WU-00 Qualification` run `34351454696`, WU-06 focused admin target completed successfully before legacy inspection.

Legacy source inspected only after that pass:

- immutable tag/commit `legacy-source-pre-greenfield-2026-09-02@7556f86ecc65f37d34d9563ce2087f16235bbca5`;
- bounded `includes/Admin/Settings_Page.php` setting meaning only;
- bounded `includes/Admin/Settings_Schema.php` labels/schema only.

Material current WU-06 behavior missing: **NO**.

Decision: `NO_MATERIAL_FINDING`.

Migration obligation recorded for later WU-08 only: legacy settings include `ippanel_api_key` and `default_sender_number`; any future migration must map values deliberately and secret-safely rather than treating the legacy settings architecture as authoritative. WU-06 does not migrate those values.

Intentionally not incorporated: legacy queue/retry controls, log dashboard, custom Rule/triggers, secondary-provider assumptions, AJAX connection/fetch-sender side effects, legacy page topology, and legacy styling.
