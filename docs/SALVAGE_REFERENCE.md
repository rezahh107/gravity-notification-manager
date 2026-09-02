# Gravityflow SMS IPPanel — Salvage Reference

> **Document ID:** `GFSMS-SALVAGE-REFERENCE-1.0.0`  
> **Status:** `ACTIVE / BOUNDED_REFERENCE`  
> **Date:** `2026-09-02`  
> **Repository:** `rezahh107/Gravityflow-SMS-Ippanel`  
> **Immutable legacy tag:** `legacy-source-pre-greenfield-2026-09-02`  
> **Legacy commit:** `7556f86ecc65f37d34d9563ce2087f16235bbca5`  
> **Target authority:** `docs/TARGET_ARCHITECTURE.md`  
> **Migration authority:** `docs/MIGRATION_PLAN.md`

---

## 1. Purpose

This document defines the **only approved way** to consult the pre-greenfield implementation.

The target core is written from scratch from `TARGET_ARCHITECTURE.md`.

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

---

## 2. Legacy Source Authority

Use only:

```text
tag: legacy-source-pre-greenfield-2026-09-02
commit: 7556f86ecc65f37d34d9563ce2087f16235bbca5
```

Do not use a moving branch such as `main` as the authority for “what the old implementation did”.

Do not create an in-tree `legacy/` copy.

Do not copy the entire old plugin into the greenfield namespace.

---

## 3. Salvage Classifications

`REFERENCE_ONLY` — read for prior behavior/history; do not transplant the class.

`TRANSPLANT_CANDIDATE` — bounded code may be copied/adapted only after current validation and focused tests.

`BEHAVIOR_REFERENCE` — preserve the useful behavior, but design the new class/API from the target architecture.

`DATA_MIGRATION_ONLY` — carry forward values/schema knowledge only, not the legacy code architecture.

`SELECTIVE_CONTENT_REUSE` — reuse non-code content only when the target concept still exists.

`RETIRE` — do not transplant; preserve only until cutover/deletion proof.

---

## 4. Approved Salvage Manifest

| Asset / knowledge | Legacy path | Classification | Allowed extraction | Mandatory validation |
|---|---|---|---|---|
| IPPanel Edge request/response knowledge | `includes/Integration/IPPanel_Provider.php` | `TRANSPLANT_CANDIDATE` | Edge URL/payload/header/response parsing/error semantics that remain current | Check current official IPPanel Edge docs; write provider contract tests |
| WordPress HTTP adapter | `includes/Integration/Wp_HTTP_Client.php` | `TRANSPLANT_CANDIDATE` | WordPress HTTP wrapper and response normalization | Confirm target seam needs it; test `WP_Error`, HTTP status/body/headers |
| HTTP seam concept | `includes/Integration/HTTP_Client_Interface.php` | `BEHAVIOR_REFERENCE` | Testability seam idea | Preserve exact interface only if independently justified |
| Phone normalization | `includes/Services/PhoneNumberNormalizer.php` | `BEHAVIOR_REFERENCE` | Proven Iranian recipient normalization cases | Write target-focused tests; keep provider formatting separate |
| Pattern variable processing | `includes/Services/PatternVariableBuilder.php` | `BEHAVIOR_REFERENCE` | Useful pattern-variable mapping rules | Reconcile with new Feed settings and current IPPanel Pattern contract |
| Message construction / GF merge-tag knowledge | `includes/Services/MessageBuilder.php`, bounded portions of `includes/Integration/GravityForms_Handler.php` | `BEHAVIOR_REFERENCE` | Proven message/merge-tag cases not already solved natively | Prefer current Gravity Forms merge-tag APIs first |
| Assignee / user / role recipient resolution | `includes/Services/RecipientResolver.php` | `BEHAVIOR_REFERENCE` | Assignee type handling, user lookup, role expansion, fixed/form-field concepts | Rebuild behind greenfield resolver; use selected `wudm_*` meta |
| Translation strings | `languages/**`, relevant surviving admin strings | `SELECTIVE_CONTENT_REUSE` | Persian/RTL wording for surviving target UI concepts | Obsolete queue/retry/log terminology must not survive |
| IPPanel credentials and still-valid global provider settings | legacy options/settings schema | `DATA_MIGRATION_ONLY` | Existing values needed by new provider config | Never expose secrets; map explicitly to new schema |
| Existing tests for salvage behavior, if any | `tests/**` where applicable | `REFERENCE_ONLY` | Useful input/output cases | Rewrite against target contracts |

---

## 5. Explicitly Forbidden Salvage

| Legacy asset | Classification | Reason |
|---|---|---|
| `includes/Integration/Event_Queue.php` | `RETIRE` | Queue, Action Scheduler, WP-Cron and delayed retry are outside target |
| `includes/Integration/Listener.php` | `RETIRE` | Native compatible Feed Step replaces lifecycle-hook delivery triggering |
| `includes/Integration/Dispatcher.php` orchestration | `RETIRE` | Couples legacy events/rules/locks/queue/provider flow |
| `includes/Integration/GravityForms_Handler.php` execution model | `RETIRE` | `GFFeedAddOn` replaces direct custom submit Rule engine |
| `includes/Integration/Sms_Sender.php` | `RETIRE` | Greenfield synchronous dispatcher owns delivery |
| `includes/Infrastructure/ProviderFactory.php` | `RETIRE` | Capability-aware `SmsProviderRegistry` replaces it |
| `includes/Integration/Secondary_Provider.php` | `RETIRE` | Not a genuinely independent provider |
| `includes/Services/LockManager.php` | `RETIRE` | Heavy exactly-once locking is outside target |
| `includes/Domain/EventSnapshot.php` | `RETIRE` | Queue/event snapshot pipeline is not target |
| `includes/Domain/EventState.php` | `RETIRE` | Legacy event state model is not target |
| `includes/Domain/EventType.php` | `RETIRE` | Native Feed/Step identity replaces event taxonomy |
| legacy custom Rule schema/condition engine | `RETIRE` | Native Feed configuration/conditional logic is authoritative |
| legacy delivery log table as state authority | `RETIRE` | Entry Meta is target delivery-state authority |
| automatic retry/backoff/scheduler behavior | `RETIRE` | Manual synchronous Retry is target |
| old `plato_user_mobile` resolution contract | `RETIRE` | Staff notification contacts use independent `wudm_*` meta |

Reading a retired file to understand a deletion/cutover obligation is allowed. Transplanting its architecture is not.

---

## 6. Per-Transplant Gate

Before moving any legacy behavior into greenfield code, record:

```text
Legacy asset:
Legacy tag/path:
Target responsibility:
Why target needs it:
Current official contract checked:
What exact behavior is transplanted:
What legacy behavior is intentionally NOT transplanted:
Tests added:
Result:
```

Reject the transplant if:

- native target capability already solves the behavior;
- current official behavior contradicts legacy;
- it would preserve queue/retry/locking/custom-rule architecture;
- extraction costs more than a clean rewrite;
- focused tests cannot be added.

---

## 7. IPPanel Extraction Rule

`IPPanel_Provider.php` is the highest-value legacy knowledge source, but it is not copied wholesale.

```text
1. Design greenfield SmsProvider contract.
2. Define required capabilities.
3. Read current official IPPanel Edge documentation.
4. Inspect legacy IPPanel provider at the frozen tag.
5. Extract only current-valid authentication, payload, endpoint, response and error knowledge.
6. Exclude diagnostics/cron, retry policy, legacy orchestration and provider-factory assumptions.
7. Prove with deterministic HTTP fakes.
```

Legacy API mode is not carried forward unless separately justified by a current owner requirement.

---

## 8. Recipient Resolution Extraction Rule

The new resolver is designed around:

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

Do not transplant fallback searches for `billing_phone`, `mobile`, or `plato_user_mobile` unless a future owner requirement explicitly adds them.

---

## 9. Message / Merge-Tag Extraction Rule

Before transplanting custom message construction:

1. use current Gravity Forms Feed/Add-On merge-tag facilities;
2. use current official variable replacement behavior;
3. identify a concrete missing case;
4. only then transplant/rewrite the smallest legacy behavior that closes the gap.

Do not recreate a custom merge-tag engine when Gravity Forms already provides the required behavior.

---

## 10. Settings and Credential Migration Rule

Legacy settings are not an architectural dependency.

Only necessary values are migrated, such as:

```text
IPPanel API credential
valid sender configuration
still-valid provider preferences
```

Do not carry forward queue settings, retry timing, lock TTL, legacy trigger matrix, custom Rule schema, or obsolete logging/dashboard preferences unless a target requirement explicitly needs an equivalent value.

Secrets must never be printed into migration reports or committed artifacts.

---

## 11. Translation Reuse Rule

Reuse translations only after checking the target UI.

Likely reusable examples:

```text
ارسال مجدد
شماره موبایل اعلان
نیازمند توجه
```

Legacy queue/scheduler/retry terminology must not survive merely because a translation already exists.

---

## 12. Evidence Discipline

Legacy code can prove only:

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

---

## 13. Definition of Successful Salvage

Salvage succeeds when:

```text
new target code is understandable without legacy architecture
+
useful behavior is preserved
+
old coupling is gone
+
tests prove the behavior
+
the legacy class can later be deleted without breaking target code
```

If target code still requires the legacy class, salvage is incomplete unless an explicit temporary compatibility Work Unit authorizes it.

---

## 14. Final Rule

The legacy tag is a **library of prior knowledge**, not a base class for the new plugin.

Default:

```text
WRITE GREENFIELD
```

Exception:

```text
CONSULT LEGACY
→ VALIDATE
→ TRANSPLANT ONLY THE BOUNDED VALUE
```
