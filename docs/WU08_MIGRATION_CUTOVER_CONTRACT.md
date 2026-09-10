# WU-08 — Settings/Data Migration and Controlled Cutover Contract

Status: implementation contract for `GNM-001 / WU-08`.

## Authorities

- Repository base: `main@de0deb5f9d2d12fe24d010b78ae35944049d5352`.
- Immutable legacy authority: `legacy-source-pre-greenfield-2026-09-02@7556f86ecc65f37d34d9563ce2087f16235bbca5`.
- Target authorities remain Gravity Forms Feed for notification Rules, Gravity Flow for workflow topology, and Gravity Forms Entry Meta for delivery state.
- Contract inspection date: 2026-09-10. Stable WordPress Options/Settings, capability/nonce/admin-post APIs, Gravity Forms Feed Add-On/GFAPI Feed APIs and native Feed conditions, and Gravity Flow Feed-Step/Step inspection APIs were re-checked before implementation.

## Secret-safe inventory

Inventory output uses only bounded classifications and presence/conflict facts. It never includes provider credential values, recipient values from live entries, raw provider responses, or production destinations.

Classifications:

- `MIGRATE_VALUE`
- `MAP_DETERMINISTIC`
- `MANUAL_REQUIRED_AMBIGUOUS`
- `RETAIN_READ_ONLY_TEMPORARILY`
- `DO_NOT_MIGRATE_RETIRE_LATER`
- `NOT_APPLICABLE`

Surviving legacy values are limited to still-valid IPPanel credential presence/value during the privileged write itself and a syntactically valid E.164 sender. Queue/retry/backoff, fallback-provider orchestration, heavy locks, legacy logs as runtime authority, EventSnapshot/EventState/EventType, custom trigger/Rule authority, and `plato_user_mobile` do not migrate.

## Deterministic direct-Gravity-Forms mapping

Automatic conversion is fail-closed. A legacy direct-GF Rule is auto-mappable only when all facts needed by the target are exact without interpretation: positive Form ID, plain SMS semantics, fixed already-E.164 recipient, non-empty message whose semantics do not depend on legacy-only substitutions, valid sender compatible with the target global sender, no Pattern mapping, no unsupported legacy condition, and no workflow-position requirement.

The generated Feed is created inactive and carries a synthetic migration-source marker derived from the immutable legacy Rule identity. Re-running migration reuses only an equivalent marker+metadata match; marker collision with different target semantics fails closed. Ambiguous Rules are reported and never auto-created.

Example report shape (synthetic):

```text
scope: direct_gf:42:0:0123456789abcdef0123
classification: MAP_DETERMINISTIC
feed: inactive / ready for controlled cutover
```

Ambiguous example:

```text
scope: direct_gf:42:1:fedcba9876543210fedc
classification: MANUAL_REQUIRED_AMBIGUOUS
reason: submitter_contact_contract_retired
next_action: create a native GNM Feed manually using the approved recipient contract
```

## Explicit migration execution

Migration never runs on plugin load or page render. The Settings surface exposes explicit capability- and nonce-protected Preview and Execute actions. Preview performs no mutation. Execute uses native WordPress option APIs and Gravity Forms Feed APIs. It is idempotent and creates deterministic Feeds inactive so migration cannot itself introduce a second real sender.

## Flow Feed Step boundary

Gravity Flow remains topology authority. WU-08 only verifies an operator-selected Form, target Feed and target GNM Feed Step through the existing read-only configuration source. Code does not insert/reorder Steps, rewrite Approval routing, select an assignee, or infer a workflow position.

When a workflow notification cannot be mapped automatically, the operator must:

1. Create/confirm the target GNM Feed with exact message/recipient/channel semantics.
2. Open the native Gravity Flow editor for the Form.
3. Add or select the supported GNM Feed Step at the intended business position.
4. Select the exact target Feed and preserve intended routing/approval behavior.
5. Save in Gravity Flow.
6. Return to GNM and run read-only verification/Check Again.
7. Only after semantic equivalence is explicitly confirmed may controlled cutover be prepared.

Missing/mismatched placement remains `MANUAL_REQUIRED_AMBIGUOUS` / not ready; verification never repairs topology.

## Scope identity and no-dual-sender sequence

Direct-GF scope identity binds Form ID + immutable legacy array index + normalized Rule fingerprint. Operator-assisted Flow scope identity binds Form + exact legacy Step/workflow source + exact target Feed/Step identity.

For every scope:

1. `PREPARED`: target exists and is inactive; legacy still owns sending.
2. Set `LEGACY_DISABLED` for that exact scope.
3. Re-read/verify the legacy compatibility guard reports that scope inactive.
4. Only then activate the target Feed and record `GREENFIELD_ENABLED`.
5. Production greenfield execution is authorized only for a Feed whose registry state is `GREENFIELD_ENABLED` and whose legacy identity still matches.

A failed legacy-disable verification leaves the target Feed inactive. A migrated scope cannot suppress another Form/Rule/Step scope.

The cutover registry is one temporary WordPress option containing only scope authority facts. It is not a Rule database, delivery-state database, queue, retry engine, or WU-09 retirement mechanism.

## Legacy queued-work guard

During WU-08 the legacy Flow queue still exists for rollback/retirement sequencing. The compatibility guard prevents a pending legacy Flow payload for an already cut-over scope from reaching its legacy sender. It does not create, schedule, retry, or replay queue work.

Before a real live Flow cutover, the operator must independently verify that no pending legacy Action Scheduler/WP-Cron notification exists for the affected scope. Suppression is a no-dual-sender safety gate, not a delivery-preservation mechanism for old queued work.

## Rollback before WU-09

Rollback order is fixed:

1. close greenfield authorization (`GREENFIELD_ENABLED` -> `LEGACY_DISABLED`);
2. deactivate the target Feed and verify it is inactive;
3. restore registry state to `PREPARED`, allowing the exact legacy scope again.

If target deactivation cannot be verified, legacy is not restored. The procedure never intentionally enables both senders.

## External/live evidence required for WU-08 closure

Repository CI proves code paths without real provider calls. True WU-08 closure additionally requires authorized environment evidence for every actually cut-over scope:

- exact Form/Rule or Form/Flow-Step scope identity;
- target Feed/Flow Step identity and native configuration read-back;
- evidence that the legacy source was inactive before target activation;
- evidence that no pending legacy queue item remained for a Flow scope;
- target activation read-back after legacy disable;
- no overlapping legacy/greenfield real sender observation;
- rollback readiness until WU-09 retirement.

Without that environment evidence, repository implementation may qualify while live WU-08 closure remains unproven.

## Exact-head qualification

Qualification must bind to one exact PR Head and include the full current WU-00 workflow, focused WU-08 tests, and Real GF Flow Integration on that same Head. Automated tests use synthetic/redacted data and must make zero real SMS/Bale calls. PR remains open and unmerged; merge/auto-merge is outside WU-08.
