# Gravity Notification Manager — Legacy Differential Review Reference

> **Document ID:** `GNM-LEGACY-DIFFERENTIAL-REFERENCE-1.2.0`  
> **Status:** `ACTIVE / POST_IMPLEMENTATION_ONLY`  
> **Repository:** `rezahh107/gravity-notification-manager`  
> **Immutable legacy tag:** `legacy-source-pre-greenfield-2026-09-02`  
> **Legacy commit:** `7556f86ecc65f37d34d9563ce2087f16235bbca5`  
> **Target authority:** `docs/TARGET_ARCHITECTURE.md`  
> **Migration authority:** `docs/MIGRATION_PLAN.md`

## 1. Purpose

This file governs how pre-greenfield implementation knowledge may be used **after** a target component has first been designed, implemented and tested from current official contracts.

The legacy tree is a second-opinion implementation reference, not a design input.

Required normal sequence:

```text
Target responsibility
    ↓
Current official contract
    ↓
Greenfield implementation
    ↓
Target-focused tests
    ↓
Consult this manifest
    ↓
Inspect equivalent legacy asset from immutable tag
    ↓
Identify any material missed behavior/edge case
    ↓
Validate finding against current official contract
    ↓
Incorporate only current-valid bounded value
    ↓
Re-run tests
```

Legacy source is evidence about prior behavior, not architecture or current-API authority.

## 2. Why Legacy Is Reviewed After Implementation

The Owner explicitly selected a docs-first greenfield method to reduce anchoring on old architecture.

For normal greenfield component Work Units:

```text
DO NOT read equivalent legacy implementation before the initial design/code/test pass.
```

The purpose of the later review is only to ask:

```text
Did the old implementation contain a material edge case,
request/response detail,
normalization behavior,
migration fact,
or useful wording that the independent greenfield implementation missed?
```

This is not an invitation to reshape the new code around old classes.

## 3. Exceptions to Post-Implementation-Only Rule

A Work Unit may inspect legacy first only when the legacy boundary itself is the target, including:

- settings/data migration;
- cutover planning;
- sender inventory/deactivation;
- retirement/deletion proof;
- exact historical-state investigation.

These exceptions do not authorize legacy architecture in greenfield runtime.

## 4. Legacy Source Authority

Use only:

```text
tag: legacy-source-pre-greenfield-2026-09-02
commit: 7556f86ecc65f37d34d9563ce2087f16235bbca5
```

Do not use moving `main` as authority for what the old implementation did.

Do not create an in-tree `legacy/` copy.

Do not copy the whole old plugin into the greenfield namespace.

## 5. Review Classifications

### `DIFFERENTIAL_CANDIDATE`

May be inspected after initial greenfield implementation because it may contain low-level current-valid behavior worth incorporating.

### `BEHAVIOR_REFERENCE`

May reveal useful inputs/outputs/edge cases, but the target class/API remains independently designed.

### `DATA_MIGRATION_ONLY`

May be inspected first only in migration/cutover Work Units; only values/schema knowledge moves forward.

### `SELECTIVE_CONTENT_REUSE`

Non-code content may be reused only when the target concept survives and wording still matches.

### `REFERENCE_ONLY`

Read for historical/cutover understanding. Do not transplant class implementation.

### `RETIRE`

Do not transplant. Inspect only for cutover, deletion proof, or negative differential evidence.

## 6. Approved Differential Review Manifest

| Asset / knowledge | Legacy path | Classification | What to look for after greenfield implementation | Validation |
|---|---|---|---|---|
| IPPanel Edge request/response knowledge | `includes/Integration/IPPanel_Provider.php` | `DIFFERENTIAL_CANDIDATE` | missed Edge auth/payload/header/response/error cases | current official IPPanel Edge docs + provider tests |
| WordPress HTTP adapter | `includes/Integration/Wp_HTTP_Client.php` | `DIFFERENTIAL_CANDIDATE` | useful response normalization / `WP_Error` handling | current WordPress HTTP API + target seam tests |
| HTTP seam concept | `includes/Integration/HTTP_Client_Interface.php` | `BEHAVIOR_REFERENCE` | testability behavior only | exact interface survives only if independently justified |
| Phone normalization | `includes/Services/PhoneNumberNormalizer.php` | `BEHAVIOR_REFERENCE` | Iranian number edge cases missed by target tests | target-focused normalization tests |
| Pattern variable processing | `includes/Services/PatternVariableBuilder.php` | `BEHAVIOR_REFERENCE` | current-valid mapping edge cases | new Feed settings + current provider Pattern contract |
| Message / GF merge-tag knowledge | `includes/Services/MessageBuilder.php` and bounded old handler logic | `BEHAVIOR_REFERENCE` | missing message/merge-tag edge case not already handled natively | current Gravity Forms merge-tag APIs first |
| Assignee/user/role resolution | `includes/Services/RecipientResolver.php` | `BEHAVIOR_REFERENCE` | missed assignee/user/role/fixed/form-field cases | current GF/Flow/WP contracts + selected `wudm_*` meta |
| Translation strings | `languages/**` and surviving labels | `SELECTIVE_CONTENT_REUSE` | useful Persian wording for concepts that still exist | target semantics + `UI_UX_REFERENCE.md` |
| IPPanel credentials / still-valid global settings | legacy option/settings schema | `DATA_MIGRATION_ONLY` | exact stored values/schema needed for deterministic migration | secret-safe explicit mapping |
| Legacy tests with useful behavior cases | `tests/**` where applicable | `REFERENCE_ONLY` | missing input/output cases | rewrite assertions against target contracts |

## 7. Explicitly Forbidden Architectural Salvage

| Legacy asset | Classification | Reason |
|---|---|---|
| `includes/Integration/Event_Queue.php` | `RETIRE` | queue/Action Scheduler/WP-Cron/delayed retry outside target |
| `includes/Integration/Listener.php` | `RETIRE` | compatible Feed Step replaces lifecycle-hook delivery trigger |
| old `Dispatcher` orchestration | `RETIRE` | couples legacy events/rules/locks/queue/provider flow |
| old `GravityForms_Handler` execution model | `RETIRE` | native Feed Add-On replaces direct custom submit Rule engine |
| `includes/Integration/Sms_Sender.php` | `RETIRE` | greenfield synchronous dispatcher owns delivery |
| `includes/Infrastructure/ProviderFactory.php` | `RETIRE` | capability-aware Registry replaces it |
| `includes/Integration/Secondary_Provider.php` | `RETIRE` | not a genuinely independent provider |
| `includes/Services/LockManager.php` | `RETIRE` | heavy exactly-once locking outside target |
| EventSnapshot/EventState/EventType pipeline | `RETIRE` | queue event model outside target |
| old custom Rule/condition engine | `RETIRE` | native Feed configuration/conditional logic is authority |
| old delivery log table as target state | `RETIRE` | Entry Meta is target delivery-state authority |
| automatic retry/backoff/scheduler behavior | `RETIRE` | manual synchronous Retry is target |
| old `plato_user_mobile` recipient contract | `RETIRE` | selected staff contacts use independent `wudm_*` meta |

A retired component may still be inspected to prove cutover/deletion or to confirm what **not** to carry forward.

## 8. Differential Review Record

For each applicable Work Unit, record:

```text
Work Unit:
New implementation Head:
Legacy asset/tag/path inspected:
Material difference found: YES|NO
Finding:
Current official contract checked:
Target architecture compatibility:
Decision: INCORPORATE|REJECT_STALE|REJECT_NON_TARGET|NO_MATERIAL_FINDING
Exact behavior incorporated (if any):
Legacy behavior intentionally NOT incorporated:
Tests added/updated:
Revalidation result:
```

## 9. Finding Admission Gate

A legacy finding may enter new code only if all are true:

1. it solves a real target behavior/edge case;
2. current official behavior does not contradict it;
3. it fits the closed target architecture;
4. it does not reintroduce retired queue/retry/locking/custom-rule architecture;
5. it is smaller/safer than simply ignoring the historical behavior;
6. focused tests can prove the admitted behavior.

Reject the finding if native current capability already solves it.

## 10. IPPanel Differential Rule

Implement the greenfield provider first from the new `SmsProvider` contract and current official IPPanel Edge documentation.

Only after initial provider tests:

```text
inspect legacy IPPanel_Provider.php
→ compare auth/payload/endpoint/response/error behavior
→ identify missing current-valid cases
→ validate against current Edge docs
→ incorporate bounded value
→ re-run deterministic HTTP/provider tests
```

Never copy the old provider wholesale.

Exclude legacy diagnostics/cron, retry policy, orchestration, logging side effects and provider-factory assumptions.

Legacy API mode is not carried forward unless a separate current Owner requirement independently justifies it.

## 11. Recipient / Normalization Differential Rule

Build/test the new resolver and normalizer first.

Then compare old behavior only for missed edge cases.

Target staff contact authority remains:

```text
SMS  → wudm_notification_mobile
Bale → wudm_bale_chat_id
```

Do not reintroduce fallback searches for `billing_phone`, `mobile`, or `plato_user_mobile` unless a future explicit Owner requirement adds them.

## 12. Message / Merge-Tag Differential Rule

Initial implementation uses current Gravity Forms Feed/Add-On merge-tag facilities.

Only after this is working may old message construction be reviewed for a concrete missing case.

Do not recreate a custom merge-tag engine because the legacy implementation had one.

## 13. Settings / Credential Migration Rule

Legacy settings are not an architectural dependency.

Migration Work Units may inspect old settings before new mapping because the legacy data itself is the migration target.

Migrate only required values such as:

```text
IPPanel API credential
valid sender configuration
still-valid provider preferences
```

Do not carry forward queue settings, retry timing, lock TTL, old trigger matrix, custom Rule schema, or obsolete logging/dashboard preferences unless a current target requirement explicitly needs equivalent data.

Secrets must never appear in migration reports or committed artifacts.

## 14. UI / Translation Boundary

The old plugin admin UI is not the visual authority for GNM.

Visual/UX authority is `docs/UI_UX_REFERENCE.md`, which uses EDIS as the preferred design reference plus the stable WordPress Design System.

Legacy UI may be reviewed only for:

- setting meaning;
- migration obligations;
- useful Persian wording for a surviving concept.

Do not transplant obsolete queue/scheduler/retry/log-dashboard terminology merely because translations already exist.

## 15. Evidence Discipline

Legacy code can prove:

```text
the old plugin implemented X this way
```

It cannot by itself prove:

```text
X is still the correct/current API
X belongs in the target architecture
this class should be preserved
```

Current API claims require current authoritative evidence.

## 16. Successful Differential Review

The review succeeds when:

```text
new implementation remains independently designed
+
legacy is inspected only at the bounded post-implementation stage
+
useful current-valid edge cases are not lost
+
stale architecture is rejected
+
tests prove admitted behavior
+
legacy class can later be deleted without breaking target code
```

## 17. Final Rule

```text
DEFAULT:
WRITE + TEST GREENFIELD FIRST

THEN:
REVIEW EQUIVALENT LEGACY
→ VALIDATE DIFFERENCES
→ INCORPORATE ONLY CURRENT-VALID MATERIAL VALUE
→ RE-TEST
```

Legacy is a second-opinion knowledge source, never the base class for Gravity Notification Manager.