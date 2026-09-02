# Gravity Notification Manager — Salvage Reference

> **Document ID:** `GNM-SALVAGE-REFERENCE-1.1.0`  
> **Status:** `ACTIVE / BOUNDED_REFERENCE`  
> **Repository:** `rezahh107/gravity-notification-manager`  
> **Immutable legacy tag:** `legacy-source-pre-greenfield-2026-09-02`  
> **Legacy commit:** `7556f86ecc65f37d34d9563ce2087f16235bbca5`  
> **Target authority:** `docs/TARGET_ARCHITECTURE.md`

## 1. Purpose

The target core is written greenfield from the closed architecture.

Legacy exists only to avoid rediscovering already-solved, still-valid low-level behavior.

```text
Target need
    ↓
Current official contract
    ↓
Is legacy knowledge useful?
    ↓
Consult this manifest
    ↓
Inspect exact legacy file from immutable tag
    ↓
Validate
    ↓
Transplant smallest valid behavior
    ↓
Target-focused tests
```

Legacy source is evidence, not architecture authority.

The greenfield identity is:

```text
Gravity Notification Manager
gravity-notification-manager
GravityNotify
```

Legacy names such as `Gravityflow-SMS-Ippanel`, `GFSMS`, old slug/text-domain names, and old option names are not salvage targets. They may be read only to locate/migrate old state.

## 2. Legacy Source Authority

Use only:

```text
tag: legacy-source-pre-greenfield-2026-09-02
commit: 7556f86ecc65f37d34d9563ce2087f16235bbca5
```

Do not use moving `main` as authority for what the old implementation did.

Do not create an in-tree `legacy/` copy.

Do not copy the whole old plugin into the greenfield namespace.

## 3. Salvage Classifications

### `REFERENCE_ONLY`

May be read to understand prior behavior, settings meaning, integration history, or migration obligations. Do not transplant the class as the new implementation.

### `TRANSPLANT_CANDIDATE`

A bounded implementation may be copied/adapted only after:

1. current official/API validation;
2. fit with target architecture;
3. focused tests.

### `BEHAVIOR_REFERENCE`

The behavior/algorithm may be useful, but the new class/API is designed from the target architecture.

### `DATA_MIGRATION_ONLY`

Only stored values/schema knowledge may move forward. Do not preserve the old code architecture that stored/consumed them.

### `SELECTIVE_CONTENT_REUSE`

Reuse non-code content such as translations only when the target UI concept still exists.

### `RETIRE`

Do not transplant. Preserve only long enough for cutover/rollback/deletion proof.

## 4. Approved Salvage Manifest

| Asset / knowledge | Legacy path | Classification | Allowed extraction | Validation |
|---|---|---|---|---|
| IPPanel Edge request/response knowledge | `includes/Integration/IPPanel_Provider.php` | `TRANSPLANT_CANDIDATE` | current-valid Edge auth/payload/header/response/error semantics | current official IPPanel Edge docs + provider contract tests |
| WordPress HTTP adapter | `includes/Integration/Wp_HTTP_Client.php` | `TRANSPLANT_CANDIDATE` | WordPress HTTP wrapper / normalized response behavior | verify target seam need; test `WP_Error`, status/body/headers |
| HTTP seam concept | `includes/Integration/HTTP_Client_Interface.php` | `BEHAVIOR_REFERENCE` | testability seam idea | exact interface survives only if independently justified |
| Phone normalization | `includes/Services/PhoneNumberNormalizer.php` | `BEHAVIOR_REFERENCE` | proven Iranian recipient normalization cases | target-focused tests; provider formatting stays separate |
| Pattern variable processing | `includes/Services/PatternVariableBuilder.php` | `BEHAVIOR_REFERENCE` | useful pattern-variable mapping | reconcile with new Feed settings/current Pattern API |
| Message / GF merge-tag knowledge | `includes/Services/MessageBuilder.php` and bounded old handler logic | `BEHAVIOR_REFERENCE` | proven cases not already solved natively | prefer current Gravity Forms merge-tag APIs first |
| Assignee/user/role resolution | `includes/Services/RecipientResolver.php` | `BEHAVIOR_REFERENCE` | assignee type handling, user lookup, role expansion, fixed/form-field concepts | rebuild behind greenfield resolver and selected `wudm_*` meta |
| Translation strings | `languages/**` and surviving labels | `SELECTIVE_CONTENT_REUSE` | Persian/RTL wording for surviving target concepts | obsolete queue/retry/log terminology must not survive |
| IPPanel credentials and still-valid global provider settings | legacy option/settings schema | `DATA_MIGRATION_ONLY` | values needed by new provider config | never expose secrets; explicit mapping only |
| Legacy tests with useful behavior cases | `tests/**` where applicable | `REFERENCE_ONLY` | useful inputs/outputs | rewrite against target contracts |

## 5. Explicitly Forbidden Salvage

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

Reading a retired file to understand cutover/deletion obligations is allowed. Transplanting its architecture is not.

## 6. Per-Transplant Gate

Before moving any legacy behavior into greenfield code, record:

```text
Legacy asset:
Legacy tag/path:
Target responsibility:
Why target needs it:
Current official contract checked:
Exact behavior transplanted:
Legacy behavior intentionally NOT transplanted:
Tests added:
Result:
```

Reject transplant if:

- native target capability already solves it;
- current official behavior contradicts legacy;
- it would preserve queue/retry/locking/custom-rule architecture;
- extraction costs more than clean rewrite;
- focused tests cannot be added.

## 7. IPPanel Extraction Rule

`IPPanel_Provider.php` is the highest-value old knowledge source but is never copied wholesale.

```text
1. Design greenfield SmsProvider contract.
2. Define required capabilities.
3. Read current official IPPanel Edge documentation.
4. Inspect legacy IPPanel provider from frozen tag.
5. Extract only current-valid auth/payload/endpoint/response/error knowledge.
6. Exclude diagnostics/cron, retry policy, legacy orchestration, provider-factory assumptions.
7. Prove behavior with deterministic HTTP fakes.
```

Legacy API mode is not carried forward unless a current Owner requirement independently justifies it.

## 8. Recipient Extraction Rule

New resolver target sources:

```text
Entry field
Fixed target
WordPress user
WordPress role
Gravity Flow assignee
Fixed Bale target
User-based Bale target
```

Staff contact authority:

```text
SMS  → wudm_notification_mobile
Bale → wudm_bale_chat_id
```

Do not transplant fallback searches for `billing_phone`, `mobile`, or `plato_user_mobile` unless a future Owner requirement explicitly adds them.

## 9. Message / Merge-Tag Rule

Before transplanting custom message construction:

1. use current Gravity Forms Feed/Add-On merge-tag facilities;
2. identify a concrete missing case;
3. only then transplant/rewrite the smallest legacy behavior closing that gap.

Do not recreate a custom merge-tag engine when Gravity Forms already provides required behavior.

## 10. Settings / Credential Migration Rule

Legacy settings are not an architectural dependency.

Migrate only required values such as:

```text
IPPanel API credential
valid sender configuration
still-valid provider preferences
```

Do not carry forward queue settings, retry timing, lock TTL, old trigger matrix, custom Rule schema, or obsolete logging/dashboard preferences unless target requirements explicitly need equivalent data.

Secrets must never appear in migration reports or committed artifacts.

## 11. Translation Reuse Rule

Reuse only wording that still matches target behavior.

Likely reusable concepts:

```text
ارسال مجدد
شماره موبایل اعلان
نیازمند توجه
```

Do not preserve queue/scheduler/retry terminology merely because a translation already exists.

## 12. Evidence Discipline

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

Those claims require the target contract and current authoritative evidence where applicable.

## 13. Successful Salvage

Salvage succeeds when:

```text
new target code is understandable without legacy architecture
+
useful behavior is preserved
+
old coupling is gone
+
tests prove behavior
+
legacy class can later be deleted without breaking target code
```

If target code still requires the legacy class, salvage is incomplete unless an explicit temporary compatibility Work Unit authorizes it.

## 14. Final Rule

Legacy is a **library of prior knowledge**, not a base class for Gravity Notification Manager.

```text
DEFAULT: WRITE GREENFIELD

EXCEPTION:
CONSULT LEGACY
→ VALIDATE
→ TRANSPLANT ONLY BOUNDED VALUE
```
