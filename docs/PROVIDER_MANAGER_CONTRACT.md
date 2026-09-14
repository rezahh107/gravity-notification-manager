# Provider Manager / IPPanel Contract Snapshot

Status: first post-v3.3.0 Provider Manager foundation and compatible IPPanel migration contract.

## Current Contract Snapshot — 2026-09-14

- Repository base: `main@dfbaaa6844a5ad438775be962f8cb97b7325e57b`.
- Branch: `feat/provider-manager-foundation`.
- WordPress integration matrix currently exercised by the repository: WordPress 7.0/7.1 with PHP 8.2/8.3; Composer requires PHP >=8.2.
- Owner-authorized real-runtime fixtures used by the existing integration workflow: Gravity Forms 3.1.1.1 and Gravity Flow 3.1.0.
- IPPanel official documentation inspected: current Edge API documentation at `https://apidoc.ippanel.com/`.
- IPPanel plain-send contract currently documented: `POST https://edge.ippanel.com/v1/api/send`, `Authorization: API TOKEN`, `sending_type=webservice`, E.164 `from_number`, and E.164 recipients. The documentation states that `from_number` must be a valid sender number assigned to the account.
- The current official documentation exposes number-management operations such as `POST /v1/api/numbers/assign`, but the inspected documentation did not establish a current account-authorized endpoint for enumerating the sender lines usable by the authenticated account.
- Inspection result for automatic sender-line discovery: **NOT PROVEN / NOT IMPLEMENTED**. Manual sender entry remains the truthful supported path.

No endpoint is inferred from legacy code, memory, or naming symmetry.

## Responsibility boundary

The synchronous delivery boundary remains unchanged:

```text
SmsProviderInterface
  identifier()
  capabilities()
  send(SmsRequest)
```

It does not own admin forms, credential persistence, enablement/order, readiness, provider construction, sender-line discovery, or test-action routing.

The separate Provider Manager/configuration-composition layer owns those administration/composition concerns and produces enabled `SmsProviderInterface` instances for the existing `SmsProviderRegistry` in deterministic configured order.

`SynchronousDispatcher`, capability-aware fallback semantics, Gravity Forms Feed ownership, Gravity Flow topology ownership, Entry Meta delivery state, Retry semantics, and synchronous execution are unchanged by this batch.

## Configuration storage and migration

Current settings option remains:

```text
gravity_notify_settings
```

The SMS provider portion is versioned and keyed by stable provider identifier:

```text
schema_version: 2
sms_providers:
  ippanel:
    enabled: bool
    api_key: secret
    sender: E.164 string
bale_bot_token: existing separate Bale setting
```

The pre-v3.3.0-compatible flat shape:

```text
ippanel_api_key
sms_from_number
bale_bot_token
```

is deterministically normalized in memory into exactly one `ippanel` configuration. An existing flat IPPanel API key makes that migrated provider enabled by default so a working installation does not silently stop sending after upgrade.

Reads are idempotent and side-effect-free: rendering or runtime composition may normalize old state in memory, but does not persist an upgrade merely because the option was read. The nested shape is persisted through an explicit Settings API save. Repeated normalization cannot create duplicate providers because configurations are keyed by stable provider ID.

Bale storage/behavior remains separate and is preserved in this batch.

## Provider Manager admin contract

The directly discoverable `SMS Providers / IPPanel` surface is the first Provider Manager / Senders surface.

For IPPanel it supports:

- viewing readiness without exposing credentials;
- replacing the write-only API key;
- enabling/disabling the provider;
- entering/updating the E.164 sender number;
- explicitly sending one real test SMS through the existing production `IPPanelProvider`/HTTP path.

Rendering, typing credentials, ordinary Settings API saving, Overview, Advisor, and Diagnostics do not contact IPPanel.

Real test sending is a separate explicit `admin-post.php` action protected by the existing `manage_options` capability boundary plus an action/provider/surface-bound nonce. Test sends do not run through Feed processing, Manual Retry, Gravity Flow execution, or Entry Meta delivery-state persistence.

Automated tests use deterministic fake/simulator transports and never send a real SMS.

## Sender-line discovery decision

The immutable legacy implementation exposed `Fetch Senders` and called:

```text
GET https://edge.ippanel.com/v1/api/number/numbers?page=1&per_page=100
```

Legacy code described that path as official and cached returned sender values. That historical implementation is evidence only.

During this Work Unit the current official IPPanel documentation was re-checked. The old enumeration endpoint above was not established by the inspected current contract. Therefore the legacy retrieval behavior is intentionally **not** carried forward. This prevents a guessed or stale provider endpoint from becoming production behavior.

If a future current official IPPanel contract establishes an authenticated usable-sender enumeration endpoint, discovery may be added as a narrow explicit operator action (`Fetch Lines`/`Refresh`) in the Provider Manager layer. It must not run on render, typing, or ordinary save.

## Complexity justification

```text
Proposed machinery: focused SmsProviderManager configuration/composition layer.
Target requirement: represent provider configuration independently from delivery, migrate IPPanel, compose enabled providers, expose readiness/admin operations.
Named failure prevented: coupling credentials/admin concerns into SmsProviderInterface or hard-coding IPPanel construction in ProductionRuntime would block clean multi-provider administration and make enable/disable/migration unsafe.
Native/platform alternative checked: WordPress Settings/Options APIs remain the persistence mechanism; existing SmsProviderRegistry remains the delivery registry.
Why simpler alternative is insufficient: flat IPPanel fields cannot represent enabled provider configurations/order without keeping ProductionRuntime provider-specific.
Runtime/maintenance cost: one small provider configuration/composition class plus focused admin surface/tests; no new dependency, scheduler, transport or persistent subsystem.
Decision: accepted minimum sufficient layer.
```

## Post-implementation Legacy Differential Review

Legacy authority inspected read-only:

```text
legacy-source-pre-greenfield-2026-09-02
7556f86ecc65f37d34d9563ce2087f16235bbca5
includes/Admin/Settings_Page.php
includes/Integration/IPPanel_Provider.php
```

Findings:

- useful current-valid behavior already present: write-only/explicit operator actions and manual sender fallback remain appropriate;
- legacy sender enumeration path existed, but its claimed endpoint is not established by the current official contract and is rejected for this target;
- legacy combined provider/admin/diagnostic/cron responsibilities are intentionally not incorporated;
- legacy cached-sender option, background health check, legacy API mode, logging subsystem, Rule builder, queue/retry architecture and broad settings topology are intentionally not reintroduced.

Result: **NO ADDITIONAL CURRENT-VALID DELIVERY/CONFIGURATION BEHAVIOR REQUIRED** beyond the focused Provider Manager implementation and manual sender fallback.