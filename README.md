# Gravity Notification Manager

Native-first multi-channel notifications for Gravity Forms and Gravity Flow with SMS provider failover, Bale fallback, workflow-aware recipients, Attention Required status, and manual retry.

## Project status

The target architecture is **decision-closed** and the new core is being built **greenfield-by-default**.

The current repository still contains the pre-greenfield runtime until controlled cutover. That legacy implementation is not the architecture authority and should not be refactored into the new core.

## Target identity

```text
Plugin Name: Gravity Notification Manager
Plugin slug: gravity-notification-manager
Text domain: gravity-notification-manager
PHP namespace: GravityNotify
```

## Architecture

- [Target Architecture](docs/TARGET_ARCHITECTURE.md)
- [Migration Plan](docs/MIGRATION_PLAN.md)
- [Legacy Salvage Reference](docs/SALVAGE_REFERENCE.md)

## Legacy source

The immutable pre-greenfield source reference is:

```text
tag: legacy-source-pre-greenfield-2026-09-02
commit: 7556f86ecc65f37d34d9563ce2087f16235bbca5
```

Legacy code is consulted only as a read-only source of validated implementation knowledge. New target code is designed from the target architecture first.

## Target shape

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

The baseline intentionally avoids background notification queues, Action Scheduler, WP-Cron delivery/retry, a custom workflow engine, and a custom central dashboard.
