# Gravity Notification Manager — Product Identity

> **Status:** `CLOSED`
> **Repository:** `rezahh107/gravity-notification-manager`
> **Authority:** Owner-approved product identity for the greenfield core

## Canonical identity

```text
Product Name: Gravity Notification Manager
Repository: rezahh107/gravity-notification-manager
Plugin slug: gravity-notification-manager
Text domain: gravity-notification-manager
Greenfield PHP namespace: GravityNotify
```

This identity applies to all new greenfield code, documentation, UI labels, package metadata, and future implementation work.

## Legacy identity boundary

The existing pre-greenfield runtime still contains historical identifiers such as:

```text
gravityflow-sms-ippanel.php
GFSMS
legacy plugin/text-domain names
legacy option/meta names
```

Those identifiers are migration evidence only. Do not propagate them into new target code merely for continuity.

The immutable legacy reference remains:

```text
tag: legacy-source-pre-greenfield-2026-09-02
commit: 7556f86ecc65f37d34d9563ce2087f16235bbca5
```

## Naming boundary

`IPPanel` and `SMS` are components, not the product identity.

```text
Gravity Notification Manager
├─ Gravity Forms / Gravity Flow integration
├─ SMS Channel
│  └─ IPPanelProvider / eligible future providers
└─ Bale Channel
```

## Change rule

Do not change the product name, slug, text domain, repository identity, or greenfield namespace unless the Owner explicitly reopens this identity decision.
