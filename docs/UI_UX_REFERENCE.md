# Gravity Notification Manager — UI/UX Reference

> **Document ID:** `GNM-UI-UX-REFERENCE-1.0.0`  
> **Status:** `CLOSED / IMPLEMENTATION_REFERENCE`  
> **Decision date:** `2026-09-03`  
> **Repository:** `rezahh107/gravity-notification-manager`  
> **Visual/UX reference repository:** `rezahh107/EDIS-WordPress-Evidence-Exporter`  
> **Inspected EDIS Head:** `0785e6113c1b5390071311947aa849a537f066b7`

## 1. Purpose

This document defines the approved UI/UX direction for Gravity Notification Manager.

The goal is **not** to clone EDIS. The goal is to reuse the parts of its design grammar the Owner likes while simplifying the information architecture for a daily operational notification tool.

Selected direction:

```text
EDIS UX grammar
+
current stable WordPress Design System
+
GNM-specific simplified information architecture
```

## 2. Stable WordPress UI Baseline

GNM should use current stable, officially supported WordPress admin primitives and design-system facilities available in the actual supported baseline.

At the decision date, WordPress 7.1 provides a stable design-system theming foundation including:

- the registered `wp-theme` stylesheet;
- semantic design tokens exposed as CSS custom properties;
- the stable `ThemeProvider` path through the WordPress theming package for React surfaces where React is justified.

Work Units must re-check the current official WordPress contract before implementation rather than freezing remembered API details indefinitely.

### 2.1 `wp-theme` / design tokens

Where compatible with the supported WordPress baseline, prefer WordPress semantic design tokens for:

- surface/background colors;
- foreground/text colors;
- strokes/borders;
- radius;
- spacing/sizing where suitable;
- focus/interactive visual consistency.

Do not hard-code a parallel design system when the supported WordPress token can satisfy the need.

### 2.2 Experimental Widget Dashboard boundary

The customizable Widget Dashboard remains an experimental WordPress/Gutenberg direction at the decision date and is **not** a stable admin extension API.

Therefore GNM MUST NOT depend on the experimental Widget Dashboard rendering/registration contract for its production admin UI.

This does **not** prohibit visually aligning with the new Dashboard generation.

Target approach:

```text
NOW:
GNM-owned admin pages
+ stable WordPress design system/tokens
+ native controls
+ scoped interaction code

LATER, only after official stabilization:
optionally adapt bounded GNM summary components into supported Dashboard widgets
```

Future Dashboard widget support is an optional evolution, not a current requirement.

## 3. EDIS Patterns to Adopt

The EDIS reference demonstrates several patterns the Owner prefers and GNM should deliberately carry forward where appropriate:

### 3.1 Clear bounded panels

Use visually bounded sections with:

- readable heading;
- concise purpose/explanation;
- logical spacing;
- low visual noise;
- restrained border/radius/elevation.

### 3.2 Summary stat cards

Use summary cards where they answer an operational question quickly.

Examples for GNM:

```text
Notification Points
Configured
Needs Setup
Attention Required
```

Do not create vanity metrics that do not change an operator decision.

### 3.3 Semantic status badges

Use compact semantic states where useful, but never color alone.

Approved target vocabulary includes:

```text
CONFIGURED
NEEDS_SETUP
SUCCESS
FAILED
AMBIGUOUS
ATTENTION_REQUIRED
DISABLED
NOT_APPLICABLE
```

Each important state should have readable text and, where appropriate, an icon/symbol plus semantic color.

### 3.4 Definition lists / structured facts

Use label/value presentation for technical and environment facts rather than dense prose.

Examples:

```text
WordPress
PHP
Gravity Forms
Gravity Flow
GravityView
IPPanel
Bale
```

### 3.5 Clear action hierarchy

A user should immediately understand the primary action.

Examples:

```text
Open Gravity Flow
Check Again
Retry
Test Connection
Save Settings
```

Avoid equal visual weight for every button.

### 3.6 Empty states

Do not leave blank tables/panels.

Explain:

- why nothing is shown;
- whether that is good or incomplete;
- the next action if one exists.

### 3.7 Responsive / RTL / reduced-motion behavior

Preserve the qualities liked in EDIS:

- responsive grids;
- safe overflow handling;
- Persian RTL support;
- correct LTR for code/IDs/technical values;
- reduced-motion respect;
- readable spacing and hierarchy.

## 4. EDIS Patterns NOT to Copy

EDIS is an evidence/export/diagnostics product. GNM is a daily operational notification manager.

Do not copy EDIS complexity that exists for its different mission, including:

- export jobs;
- job progress/worker architecture;
- preflight/export pipelines;
- data-source/coverage navigation;
- diagnostic JSON workflow as a primary user path;
- large multi-page information architecture merely for visual similarity.

Reference design language, not product topology.

## 5. GNM Information Architecture

The approved GNM admin information architecture is intentionally small:

```text
Gravity Notification Manager
├─ Overview
├─ Notification Points
├─ Settings
└─ Help & Diagnostics
```

Do not add another top-level page unless it has a recurring operational purpose that cannot fit coherently in these surfaces.

### 5.1 Overview

Purpose: fast operational orientation.

May show:

- summary counts;
- environment/product availability;
- provider/channel readiness;
- configuration warnings;
- direct navigation to unresolved setup.

It is **not** the central Attention Required case-management dashboard.

### 5.2 Notification Points

Purpose: guidance and verification for logical notifications and workflow placement.

Each point/card should make clear:

- form/workflow context;
- logical notification/feed;
- channel/fallback summary;
- configuration state;
- what is missing or wrong;
- exact next setup action;
- `Open Gravity Flow` or equivalent direct link;
- `Check Again` verification.

Example interaction:

```text
Registration Approved
[NEEDS_SETUP]

Gravity Flow Step is missing.
1. Open Forms → <Form> → Settings → Workflow.
2. Add the supported Notification Feed Step.
3. Select feed <Feed Name>.
4. Set expected next Step.
5. Save.

[Open Gravity Flow] [Check Again]
```

Point Manager remains guidance/verification only; it does not mutate workflow topology.

### 5.3 Settings

Use WordPress-native controls and APIs where they satisfy the need.

Group configuration by actual responsibility, for example:

```text
SMS Providers
  └─ IPPanel
Bale
Defaults / operational options
```

Do not build a custom settings framework merely for styling.

Connection/test-send actions must be explicit user actions. Rendering the page must never send external messages.

### 5.4 Help & Diagnostics

Purpose: explain current environment/configuration health and provide safe troubleshooting.

May show:

- installed/detected relevant product versions;
- provider/channel configuration status;
- Feed/Flow integration availability;
- Point configuration checks;
- privacy-safe technical details;
- safe next action.

Diagnostics are side-effect-free by default.

A real test SMS/Bale action, if provided, must be explicit, capability-protected, nonce-protected, clearly labeled as a real external send, and use a consciously selected destination.

## 6. Attention Required Boundary

The central case-level Attention Required presentation remains:

```text
Entry Meta
→ GravityView filtered View
→ Elementor presentation
```

GNM admin Overview may show an aggregate/shortcut such as an Attention Required count, but it must not become a parallel case-management state store or duplicate GravityView/Elementor presentation system.

Gravity Forms Entry Detail retains the bounded independent operational status/Retry fallback already defined in the architecture.

## 7. React / JavaScript Boundary

GNM is not a SPA by default.

Use PHP/native WordPress admin rendering when it is sufficient.

Use React/WordPress component packages only where interaction complexity creates a material usability benefit, for example a richer Notification Points surface with live filtering/checking.

Rules:

- do not bundle a second React runtime when the supported WordPress-provided package/handle is appropriate;
- do not introduce React solely for visual modernity;
- keep state ownership on the server/domain boundary where appropriate;
- preserve accessible progressive behavior where practical.

## 8. Performance Boundary

Admin UI assets must be scoped to GNM admin surfaces whenever practical.

Expected behavior:

```text
Public frontend → no GNM admin bundle
Unrelated wp-admin screens → no GNM admin bundle
GNM screens → load only required GNM/admin dependencies
```

Do not enqueue a large global admin bundle merely to achieve consistent styling.

## 9. Semantic and Accessibility Rules

Important controls/states require:

- understandable labels;
- visible focus;
- keyboard behavior consistent with the control paradigm;
- sufficient readability/contrast;
- semantic status text;
- no color-only failure/success meaning;
- correct RTL/LTR handling for mixed Persian/technical content.

## 10. Design Direction Summary

Approved direction:

```text
Visual feel: modern WordPress admin / EDIS-like clarity
Foundation: stable WordPress Design System
Information architecture: GNM-specific and small
Primary style: panels + summary cards + semantic status + clear actions
React: selective, justified only by interaction benefit
Experimental Widget Dashboard: no production dependency
Central case Attention UI: GravityView + Elementor
```

## 11. Reference Snapshot

EDIS reference inspected:

```text
rezahh107/EDIS-WordPress-Evidence-Exporter
main@0785e6113c1b5390071311947aa849a537f066b7
```

Relevant observed reference patterns include its admin panel/card grid, summary stats, semantic badges, definition lists, action groupings, empty states, RTL/LTR handling and reduced-motion treatment.

A future EDIS change does not automatically alter this GNM UI contract.

## 12. Final Rule

Build GNM to feel like the same **quality family** as the preferred EDIS admin experience while remaining simpler, more native to current WordPress, and purpose-built for notification operations.