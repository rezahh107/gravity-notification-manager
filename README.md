# Gravity Notification Manager

Native-first multi-channel notifications for Gravity Forms and Gravity Flow with synchronous SMS provider failover, Bale fallback, workflow-aware recipients, Attention Required status, manual retry, and a modern WordPress-native admin experience.

## Project status

The target architecture is **decision-closed** and the new core is being built **docs-first / greenfield-by-default**.

The current repository still contains the pre-greenfield runtime until controlled cutover. That legacy implementation is not architecture authority and is not used as the initial design source for new components.

Normal implementation method:

```text
Current official contracts
→ greenfield implementation
→ target tests
→ post-implementation legacy differential review
→ current-valid findings only
→ revalidation
```

## Target identity

```text
Plugin Name: Gravity Notification Manager
Plugin slug: gravity-notification-manager
Text domain: gravity-notification-manager
PHP namespace: GravityNotify
```

## Authoritative project documents

- [Product Identity](docs/PRODUCT_IDENTITY.md)
- [Target Architecture](docs/TARGET_ARCHITECTURE.md)
- [Migration Plan](docs/MIGRATION_PLAN.md)
- [Owner Preference Profile](docs/OWNER_PREFERENCE_PROFILE.md)
- [UI/UX Reference](docs/UI_UX_REFERENCE.md)
- [Legacy Differential Review Reference](docs/SALVAGE_REFERENCE.md)

## Legacy source

The immutable pre-greenfield source reference is:

```text
tag: legacy-source-pre-greenfield-2026-09-02
commit: 7556f86ecc65f37d34d9563ce2087f16235bbca5
```

For normal greenfield Work Units, equivalent legacy implementation is reviewed **after** the new component is independently implemented and tested. Legacy is used only to discover current-valid edge cases or migration facts that may have been missed.

## Target runtime shape

```text
Gravity Forms Feed
        +
Gravity Flow compatible Feed Step
        ↓
Recipient Resolver
        ↓
Synchronous Dispatcher
        ├─ SMS → capability-aware provider registry
        └─ Bale
        ↓
Entry Meta delivery state
        ↓
Attention Required + Manual Retry
```

The baseline intentionally avoids background notification queues, Action Scheduler, WP-Cron delivery/retry, a custom workflow engine, heavy exactly-once infrastructure, and duplicate state authorities.

## Admin UI direction

GNM uses a compact operational information architecture:

```text
Overview
Notification Points
Settings
Help & Diagnostics
```

The approved UX direction combines:

```text
EDIS UX grammar
+
current stable WordPress Design System
+
GNM-specific simplified information architecture
```

The preferred EDIS reference is `rezahh107/EDIS-WordPress-Evidence-Exporter` at the inspected snapshot recorded in `docs/UI_UX_REFERENCE.md`.

Where supported by the actual WordPress baseline, GNM should use stable WordPress design-system tokens/components rather than building a parallel visual system. The experimental customizable WordPress Widget Dashboard is **not** a production dependency while its extension API remains experimental.

Central case-level Attention Required presentation remains GravityView + Elementor; GNM admin surfaces provide configuration/health/operations, not a duplicate case-management dashboard.