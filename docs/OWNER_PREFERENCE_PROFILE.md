# Gravity Notification Manager — Owner Preference Profile

> **Document ID:** `GNM-OWNER-PREFERENCE-PROFILE-1.0.0`  
> **Status:** `ACTIVE / SCOPED_OVERLAY`  
> **Decision date:** `2026-09-03`  
> **Repository:** `rezahh107/gravity-notification-manager`  
> **Source repository:** `rezahh107/Personal-Preference-Decision-Model`  
> **Inspected source Head:** `f439b438ba4a40c1fff4df9666c87288155a49d0`

## 1. Purpose

This file records only the Owner preferences and decision rules that materially apply to Gravity Notification Manager.

It is a **scoped project overlay**, not a runtime dependency and not a copy of the Personal Preference Decision Model.

The source repository remains the canonical source for the meaning of its preference/rule IDs. This file records only their practical effect on GNM.

## 2. Authority Boundary

Preference guidance never overrides a stronger current project authority.

```text
Current explicit Owner/task instruction
→ PRODUCT_IDENTITY.md
→ TARGET_ARCHITECTURE.md
→ MIGRATION_PLAN.md
→ applicable project constraints/contracts
→ OWNER_PREFERENCE_PROFILE.md
→ UI_UX_REFERENCE.md where UI-specific
→ SALVAGE_REFERENCE.md
→ AGENTS.md
→ implementation source/tests
```

If the source preference repository later changes, GNM does not silently change with it. A newer preference snapshot must be explicitly reviewed and adopted into this file.

There is no runtime network, package, Composer, WordPress, or build dependency on `Personal-Preference-Decision-Model`.

## 3. Applicable Canonical Records

The following records were selected because their documented scope/trigger materially matches this project:

```text
CP-03
CP-04
CP-05
DEF-ENG-01
DEF-ENG-02
DEF-WP-01
DEF-QUAL-01
DEF-UI-03
AL-02
AL-03
AL-04
MG-01
```

No other preference/rule is activated merely because it exists in the source repository.

## 4. Project Effects

### 4.1 `CP-03` — Minimum Sufficient Complexity

GNM first satisfies correctness, mandatory behavior and operational usability; after that, choose the smallest coherent implementation.

Any new machinery must name either:

- the concrete failure mode it prevents; or
- the material benefit it provides.

This directly guards against reintroducing unnecessary queues, schedulers, locks, event buses, duplicate dashboards, speculative abstractions, or enterprise-grade infrastructure.

### 4.2 `CP-04` — Controllable Automation

Automate repetitive/error-prone work when doing so reduces time or mistakes while preserving control and observable outcomes.

For GNM this supports:

- Point Manager guidance and `Check Again` verification;
- reusable notification Feed configuration;
- explicit status/diagnostic surfaces;
- automated validation/tests;
- manual Retry where human control is intentionally retained.

Automation is not a goal by itself.

### 4.3 `CP-05` — Compare, Decide, Freeze

Architecture and other material choices are compared before commitment and then frozen.

A closed choice is reopened only by:

- explicit Owner requirement change;
- current official-contract invalidation;
- direct implementation contradiction;
- another authority conflict already recognized by `TARGET_ARCHITECTURE.md`.

Implementation aesthetic preference alone is not a reopen reason.

### 4.4 `DEF-ENG-01` — Current Stable / Official / Compatible

For version-sensitive implementation choices, prefer the current **stable, officially supported, non-deprecated, standards-aligned** mechanism compatible with the actual project baseline.

`Current` does not mean beta/preview/experimental merely because it is newer.

Each Work Unit that touches a version-sensitive API must record a **Current Contract Snapshot** before implementation.

### 4.5 `DEF-ENG-02` — Native / Platform Primitive First

When WordPress, Gravity Forms, Gravity Flow, GravityView or Elementor already provides a supported primitive that satisfies the requirement, prefer it over parallel custom infrastructure.

External dependencies or custom subsystems need a named capability gap or material benefit.

This rule does not prohibit third-party dependencies when they are the smallest sufficient solution.

### 4.6 `DEF-WP-01` — WordPress / PHP Supported Engineering Baseline

Use current supported WordPress/PHP practices proportionate to this personal/internal plugin, including where relevant:

- official WordPress APIs;
- modern PHP compatible with the selected baseline;
- structured namespacing/autoloading where useful;
- validation/sanitization/escaping at correct boundaries;
- capability and nonce checks for state-changing actions;
- WordPress HTTP API;
- prepared database access if direct DB access is genuinely required;
- i18n/RTL support within product scope;
- lint/static analysis/testing proportional to risk.

Do not interpret this as a requirement for maximum enterprise formalism.

### 4.7 `DEF-QUAL-01` — Exact Artifact / Truthful Qualification

Qualification claims must match their evidence.

If a Work Unit or PR is claimed `PASS`, the exact target artifact/Head being qualified must be the one validated to the extent required by that claim.

Use truthful states such as:

```text
PASS
FAIL
PARTIAL
NOT_RUN
NOT_EXECUTED_ENVIRONMENT_UNAVAILABLE
NOT_PROVEN
```

Unit/static/synthetic evidence remains useful, but it does not become exact-target runtime proof.

### 4.8 `DEF-UI-03` — Semantic and Accessible Operational States

Important operational states must not be communicated by color alone.

GNM status UI combines readable text with semantic visual cues such as icon/symbol and color where useful.

Target semantic states include, as applicable:

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

Focus visibility, understandable labels, readable contrast and keyboard behavior must not be broken without reason.

### 4.9 `AL-02` — Real Completion / Usability Path

Technical correctness alone is insufficient for operational surfaces.

A user must be able to understand:

- what is configured;
- what happened;
- what failed or remains uncertain;
- what the next safe action is;
- whether Retry/setup verification succeeded.

### 4.10 `AL-03` — Complexity Inspection

When infrastructure or complexity is proposed, explicitly answer:

```text
What exact failure does this prevent?
What material benefit does it provide?
Can the current native/platform primitive satisfy the requirement instead?
```

A weak answer is evidence against adding the machinery.

### 4.11 `AL-04` / `MG-01` — Proportional Change Governance

For consequential changes to architecture, contracts or authoritative project rules, inspect:

- baseline;
- affected scope;
- downstream Work Units/consumers;
- traceability;
- rollback/supersession impact.

Use the smallest governance control set proportional to the risk. Do not turn governance itself into the delivery bottleneck.

## 5. Work Unit Gate Derived from Preferences

Every implementation Work Unit follows:

```text
Current Contract Snapshot
        ↓
Greenfield implementation from closed target
        ↓
Target-focused tests
        ↓
Post-implementation Legacy Differential Review
        ↓
Validate any legacy finding against current official contract
        ↓
Incorporate only current-valid material value
        ↓
Re-run affected tests
        ↓
Exact-target / exact-Head qualification
```

## 6. Complexity Justification Template

Use when a Work Unit proposes new infrastructure, abstraction, dependency or persistent state beyond the closed target:

```text
Proposed machinery:
Target requirement:
Named failure prevented / material benefit:
Native/platform alternative checked:
Why the simpler alternative is insufficient:
Maintenance/runtime cost:
Decision:
```

If the proposal contradicts a closed architecture choice, this template cannot self-authorize a reopen.

## 7. Refresh Rule

This overlay was derived from:

```text
rezahh107/Personal-Preference-Decision-Model
main@f439b438ba4a40c1fff4df9666c87288155a49d0
```

A later source commit does not automatically supersede this file.

Refresh only when:

- the Owner explicitly asks to refresh preferences; or
- a material GNM decision requires checking whether the accepted preference model changed.

## 8. Final Rule

Use this preference profile to shape **how** the closed GNM target is implemented and validated.

Do not use it to reopen architecture that is already validly closed.