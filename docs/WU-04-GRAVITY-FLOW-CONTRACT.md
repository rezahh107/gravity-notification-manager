# WU-04 Gravity Flow Feed-Step Contract Snapshot

Run: `GNM-001-WU-04-RUN-014`

This document records the bounded current contract used by WU-04. It does not activate production senders, define WU-05 delivery state, or authorize a parallel workflow/interception engine.

## Execution baseline

- Repository base: `main@d2944d061c61fa49548f1f2f481fc2f6ebac0de6`.
- Repository CI runtime: PHP 8.3.
- Executor shell observed: PHP 8.4.23.
- Gravity Forms installed deployment version: not observable from this execution environment.
- Current public Gravity Forms documentation/changelog inspected on 2026-09-07; `GFFeedAddOn::process_feed()` remains the supported synchronous Feed processing seam and native Feed conditions remain part of the Feed Add-On Framework.
- Gravity Flow installed deployment version: not observable from this execution environment.
- Owner-supplied primary source: `gravityflow.zip`, SHA-256 `ac0573b75831380417a21a455176e25eb746d718bbbd0bb70d6da6f48cba5404`, plugin header version 3.1.0.
- Current public Gravity Flow changelog inspected on 2026-09-07: current release 3.1.1 (2026-08-25). No 3.1.1 entry removes or replaces the Feed-Step base contract used here.

## Official/current sources

- https://docs.gravityflow.io/step_feed_class/
- https://docs.gravityflow.io/step-class/
- https://docs.gravityflow.io/the-workflow-step-framework/
- https://docs.gravityflow.io/changelog/
- https://docs.gravityforms.com/gffeedaddon/

## Supported Feed-Step contract relied upon

The owner-verified Gravity Flow 3.1.0 source establishes that `Gravity_Flow_Step_Feed_Add_On` is the Feed Add-On integration base and instructs integrations to subclass it and register the extending step using `Gravity_Flow_Steps::register()`.

WU-04 relies only on these base-owned behaviors:

1. the extending class names its associated Feed Add-On class;
2. the base reads existing Add-On feeds using the Add-On's `get_feeds()` contract;
3. the base uses the existing Feed selection rather than creating a second rule model;
4. the base evaluates the Feed condition through Gravity Flow / the native Feed condition path;
5. the base invokes the associated Add-On's existing `process_feed()` method with the current Feed, Entry and Form;
6. the base tracks processed Feed IDs for step status;
7. the base's submission interception removes/delays workflow-selected feeds through the Add-On-specific pre-process Feed hook so the selected logical Feed is not also processed at ordinary submission;
8. the step completion/status contract is independent from the notification provider's delivery-success value.

Current official documentation continues to publish `Gravity_Flow_Step_Feed_Add_On`, documents Feed-Step settings/status examples on `Gravity_Flow_Step`, and documents `gravityflow_loaded` plus `Gravity_Flow_Steps::register()` as the supported custom-step registration lifecycle. Gravity Flow 2.9.13 specifically records Feed Add-On step compatibility with Gravity Forms 2.9.4 Feed result status storage; Gravity Flow 3.0.0 updated Feed-setting labels while retaining feed-based steps.

## GNM implementation boundary

- `GravityNotify\GravityFlow\NotificationFeedStep` is intentionally a thin adapter. It defines only its stable step type, associated `NotificationFeedAddOn` class and label. Feed discovery, condition evaluation, processing, processed-feed tracking and submit interception remain Gravity Flow responsibilities.
- `GravityNotify\GravityFlow\FeedStepRegistration` registers immediately when the public Feed-Step surface is loaded or defers to `gravityflow_loaded`. Missing Gravity Flow is a safe no-op and cannot fatal GNM.
- `NotificationFeedAddOn::process_feed()` remains the single logical execution seam for both ordinary Gravity Forms Feed processing and Gravity Flow Feed-Step processing.
- `NotificationFeedProcessor` delegates recipient topology to WU-03 `RecipientResolver` and transport to WU-02 `SynchronousDispatcher`.
- WU-01 currently exposes one message field and no Pattern code/parameter metadata. WU-04 therefore renders that message as native Gravity Forms text and sends it as Plain SMS; it does not perform Pattern-to-Plain conversion.
- WU-02 requires an already-configured SMS sender. WU-04 accepts that sender through request-time composition rather than reading legacy settings or activating production credentials.
- Native `process_feed()` boolean status is used as request-local/framework-visible delivery outcome; Gravity Flow's Feed-Step completion contract remains separate so a delivery failure does not intentionally strand workflow.
- No GNM Entry Meta delivery state, attempt history or manual Retry is introduced; those remain WU-05.

## No-send / synchronous boundary

All automated WU-04 tests use deterministic in-memory provider/channel fakes. No provider credentials, real destinations or network calls are used. WU-04 adds no queue, cron, worker or delayed delivery mechanism.

## Bounded legacy differential review

Status: `NO_MATERIAL_FINDING`.

The review was performed only after the initial greenfield WU-04 implementation and tests passed. The immutable legacy reference inspected was tag `legacy-source-pre-greenfield-2026-09-02`, commit `7556f86ecc65f37d34d9563ce2087f16235bbca5`, limited to relevant Flow-triggering material in `includes/Integration/Listener.php`.

`docs/SALVAGE_REFERENCE.md` classifies that listener path as `RETIRE` because the compatible Feed-Step mechanism replaces the lifecycle-hook delivery trigger. The bounded review found no current-valid material behavior to incorporate. No legacy listener, queue, scheduler, or dispatcher orchestration was transplanted into WU-04.
