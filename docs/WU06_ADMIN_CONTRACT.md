# WU-06 Admin Contract Snapshot

Status: initial greenfield implementation, pending post-test legacy differential review.

## Current contract snapshot — 2026-09-09

- Repository base: `main@60ebb19433ebb40d24d9d8ee20067afafb9d36eb`.
- WordPress test/runtime baseline: WordPress 7.1 (`.wp-env.json`), PHP 8.3; Composer requires PHP >=8.2.
- WordPress official contracts re-checked: `add_menu_page()` / `add_submenu_page()`, `register_setting()` with `sanitize_callback`, `admin_enqueue_scripts`, capability checks, `check_admin_referer()`, contextual escaping, and the WordPress 7.1 `wp-theme` design-token stylesheet.
- WordPress 7.1 design-system contract: the registered `wp-theme` stylesheet exposes public semantic `--wpds-*` CSS custom properties. WU-06 uses those tokens directly and does not introduce React or the experimental customizable Widget Dashboard API.
- Accessibility baseline: semantic native controls/labels, visible focus, status text independent of color, logical CSS layout, LTR isolation for technical values, and no animation/transition introduced.
- Gravity Forms authority: one `GFFeedAddOn` Feed remains one logical notification Rule.
- Gravity Flow authority: the supported Feed Step remains workflow-position authority. WU-06 reads `Gravity_Flow_API::get_steps()` and the public Step `get_type()`, `get_setting()`, `get_id()`, and `get_name()` helpers only. No topology-write API is present in the WU-06 inspection interface.

Official references inspected:

- https://developer.wordpress.org/reference/functions/add_menu_page/
- https://developer.wordpress.org/reference/functions/add_submenu_page/
- https://developer.wordpress.org/reference/functions/register_setting/
- https://developer.wordpress.org/reference/hooks/admin_enqueue_scripts/
- https://developer.wordpress.org/reference/functions/check_admin_referer/
- https://developer.wordpress.org/apis/security/escaping/
- https://make.wordpress.org/core/2026/07/31/design-system-theming-in-wordpress-7-1/
- https://developer.wordpress.org/block-editor/reference-guides/packages/packages-theme/
- Gravity Flow developer documentation for the local Orchestration API / `Gravity_Flow_API` and Feed Add-On Step settings.

## Information architecture and capability

GNM owns exactly four new product surfaces under one native WordPress admin parent: Overview, Notification Points, Settings, and Help & Diagnostics. All four and the privileged `Check Again` action use `manage_options` and render/action callbacks re-check that capability.

The existing legacy runtime remains present until its separately owned cutover/retirement Work Units. WU-06 does not migrate legacy settings, retire legacy runtime, or enable a second greenfield production sender.

## Point Manager authority and statuses

Point Manager is read/verify/guidance only. Its source interface contains only `forms()`, `feeds()`, and `workflow_placements()` reads. It does not create, insert, reorder, delete, update, or silently repair Gravity Flow Steps or approval routing.

Deterministic statuses are `CONFIGURED`, `NEEDS_SETUP`, `DISABLED`, and `NOT_APPLICABLE`. Feed metadata comes from the canonical greenfield Feed schema; Flow placement comes from current Gravity Flow Step configuration. Missing Flow dependency is reported as unavailable rather than guessed.

Flow-assignee recipient rules require a GNM Flow Feed Step so the assignee context is truthful. A non-Flow recipient Feed with no selected Flow Step remains a valid normal Gravity Forms submission Feed. Multiple GNM Flow placements for one Feed are reported as inconsistent and require human correction.

## Check Again

`Check Again` is an explicit POST to `admin-post.php`. It requires `manage_options`, strictly positive form/feed identifiers, and a target-bound WordPress nonce. It constructs a fresh inspector and re-reads current Feed/Flow truth before redirecting to the Point Manager. No notification provider, HTTP transport, delivery-state writer, or Gravity Flow topology-write API is called.

## Settings and privacy

WU-06 owns one bounded namespaced option: `gravity_notify_settings`. It currently prepares the already-approved greenfield runtime inputs `ippanel_api_key`, `sms_from_number`, and `bale_bot_token`. It does not migrate any legacy option value in WU-06.

Secret inputs are write-only in the UI: stored API keys/tokens are never rendered back into HTML. Diagnostics expose readiness booleans/status text only. Saving or rendering settings performs no connection test and no SMS/Bale send.

## Help & Diagnostics

Diagnostics show only product/runtime availability, PHP/WordPress version, provider readiness, and Point counts. They exclude credentials, recipient values, Entry contents, raw provider bodies, and provider requests. Missing dependencies degrade to explicit availability states rather than fatals.

## Assets, RTL/LTR, accessibility

The single WU-06 stylesheet is enqueued only for the captured GNM screen hooks. It uses WordPress 7.1 `wp-theme` semantic tokens when the registered handle is available, with conservative CSS fallbacks so the page remains readable while the repository's older plugin-header compatibility claim awaits its WU-10 reconciliation.

Layout uses logical properties and responsive grids. Technical values use explicit `dir="ltr"` plus `unicode-bidi: isolate`; WordPress/admin direction remains inherited for Persian RTL. Important states always render their textual status in addition to visual treatment. Native links, buttons, forms, and labels preserve keyboard behavior; `:focus-visible` is explicit. WU-06 introduces no animation or transition, so there is no motion to suppress.

Automated tests prove these markup/style hooks and semantic contracts; they do not claim pixel-level or assistive-technology browser validation.

## Legacy differential

Not yet performed. Per the migration contract, equivalent legacy admin UI is intentionally not inspected until the initial WU-06 focused implementation tests pass.
