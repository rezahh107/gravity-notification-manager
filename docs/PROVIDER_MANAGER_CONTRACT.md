# SMS Provider Manager Contract Snapshot

Status: approved-provider completion batch for IPPanel, Melipayamak, SMS.ir, and FarazSMS.

## Current Contract Snapshot — 2026-09-15

- Repository base: `main@cc4b5c88b015ddfdeb34910cca31e185d2d6cf12` (merge of PR #23 operational observability).
- PHP requirement remains `>=8.2`; WordPress HTTP/Settings APIs remain the platform boundaries.
- Gravity Forms Feed ownership, Gravity Flow topology ownership, synchronous `SmsProviderInterface` / `SmsProviderRegistry` / `SynchronousDispatcher`, Entry Meta delivery-state authority, Retry, operational logging, and Trace-ID ownership are unchanged.
- Automated verification remains no-send. No live provider credential or message operation is part of CI.

### IPPanel

Official contract inspected: current IPPanel Edge API documentation at `https://apidoc.ippanel.com/`.

- Delivery: `POST https://edge.ippanel.com/v1/api/send`, raw API token in `Authorization`, E.164 `from_number` and recipients; successful Edge responses expose `meta.status=true` plus `data.message_outbox_ids`.
- Explicit credential validation: `GET https://edge.ippanel.com/v1/api/acl/auth/check_token` with the same API token.
- Sender format: E.164.
- Sender discovery: **manual fallback**. Current official material did not establish a trustworthy account-authorized sender-line enumeration response contract. The historical `/api/number/numbers` path remains rejected as current authority.

### Melipayamak

First-party material inspected: `Melipayamak/melipayamak-php` and other repositories in the official `Melipayamak` GitHub organization.

- Delivery endpoint: `POST https://rest.payamak-panel.com/api/SendSMS/SendSMS`.
- Authentication/request shape: form-encoded `username`, `password`, `to`, `from`, `text`, `isflash`.
- Send result: first-party response model exposes `Value`, `RetStatus`, and `StrRetStatus`; `RetStatus=1` plus a positive `Value` RecId is the acceptance evidence used by the adapter.
- Explicit credential validation: `POST .../GetCredit` using username/password.
- Sender format: provider numeric line.
- Sender discovery: **manual fallback**. First-party SDKs expose `GetUserNumbers` / `getNumbers`, but the inspected current first-party material does not define a stable list representation for `Value` that can be parsed without guessing. No undocumented parser is implemented.

### SMS.ir

First-party/provider-maintained material inspected: `IPeCompany/SmsPanelV2.Laravel`.

- Base URL: `https://api.sms.ir/`; authentication header: `X-API-KEY`.
- Delivery: `POST /v1/send/bulk` with `lineNumber`, `MessageText`, `Mobiles`, and `SendDateTime`.
- Acceptance evidence: response `data.packId` and/or `data.messageIds`.
- Recipient representation: the first-party SDK forwards the caller-provided `Mobiles` strings and does not document a mandatory `9xxxxxxxxx` rewrite. GNM therefore keeps its canonical E.164 recipient unchanged instead of inventing a provider conversion.
- Explicit credential validation: `GET /v1/credit`.
- Sender discovery: **supported** through `GET /v1/line`; first-party `LineResponse` defines `data` as an array of line numbers.
- Sender format: provider numeric line.

### FarazSMS / IranPayamak

Official public API documentation inspected: `https://docs.iranpayamak.com/`.

- Plain delivery: `POST https://api.iranpayamak.com/ws/v1/sms/simple`, header `Api-Key`, fields `text`, `line_number`, `recipients`, and `number_format`.
- Pattern delivery: `POST /ws/v1/sms/pattern`, also using `Api-Key`.
- Documented success response uses `status="success"`; the adapter does not manufacture a message reference from `data=0`.
- Explicit credential validation: `GET /ws/v1/account/balance` using `Api-Key`.
- Sender discovery: **manual fallback**. The public `GET /ws/v1/lines/accessible` page currently documents Bearer authentication and shows an empty `{}` success example, while FarazSMS's provider-maintained n8n package documents the same endpoint family under the normal `Api-Key` credential and exposes dynamic sender-line choices. Because the current first-party sources conflict on authentication and the public endpoint page does not define the line response schema, GNM does not invent a parser or second credential flow.
- Sender format: provider numeric line.

## Configuration and migration

Settings remain in the existing option:

```text
gravity_notify_settings
```

Provider storage is schema version 3 and keyed by stable provider ID in deterministic runtime order:

```text
sms_providers:
  ippanel:
    enabled: bool
    api_key: write-only secret
    sender: E.164
  melipayamak:
    enabled: bool
    username: string
    password: write-only secret
    sender: numeric line
  smsir:
    enabled: bool
    api_key: write-only secret
    sender: numeric line
    discovered_lines: bounded numeric lines
  farazsms:
    enabled: bool
    api_key: write-only secret
    sender: numeric line
```

Existing flat `ippanel_api_key` / `sms_from_number` values are normalized in-memory into the IPPanel record without credential re-entry. Newly introduced providers are created disabled, so upgrading cannot silently add new outbound delivery paths. Read normalization remains side-effect-free; ordinary render/save never contacts a provider.

Blank write-only credential inputs preserve stored secrets. Secrets are never echoed into rendered HTML, operational logs, diagnostics, notices, or LLM debug reports.

## Provider Manager actions

`SMS Providers` is the directly discoverable administration surface for all four provider types.

Each provider exposes:

- enabled/disabled state;
- semantic readiness (`DISABLED`, `NEEDS_SETUP`, `CONFIGURED`);
- provider-specific credential fields;
- provider-specific sender validation;
- explicit `Check Connection` action;
- explicit real test SMS action.

SMS.ir additionally exposes explicit `Refresh Lines`. Unsupported/unverified discovery providers expose a manual sender field rather than attempting an undocumented endpoint.

These are separate protected POST actions. Rendering, typing credentials, and ordinary Settings API saving perform zero external requests. Every action uses the existing `manage_options` boundary plus an action/provider/surface-bound nonce.

A failed discovery request does not persist line metadata and cannot erase the existing sender. A successful zero-line refresh may replace the bounded discovered-line cache with an empty list while retaining the selected sender.

## Delivery and observability interaction

The dispatcher contract is unchanged. Provider-specific sender, authentication, request shape, and result parsing stay inside the provider adapter/configuration boundary.

The normalized `AttemptResult` now optionally carries the actual provider sender used. This is observational evidence only: the existing operational logger prefers that sender over the request-envelope sender, so fallback attempts across heterogeneous providers remain truthful. Entry Meta delivery-state and Retry authority are unchanged.

Explicit SMS TEST sends use the same production provider adapters but bypass Feed processing, Gravity Flow execution, Manual Retry, and Entry Meta history. They write one existing operational `TEST` event through the existing logging/Trace-ID subsystem.

## Complexity decision

No new framework, scheduler, queue, persistence subsystem, or provider plugin API is introduced. The only new administration abstractions are narrow optional connection/discovery boundaries because those operations have provider-specific endpoints/authentication/response contracts and do not belong on `SmsProviderInterface`.

The existing WordPress HTTP seam is extended from POST-only to explicit synchronous `GET` + `POST`, which is the smallest change needed for verified connection/discovery endpoints and keeps all automated provider tests deterministic.

## Post-implementation Legacy Differential Review

Legacy authority inspected read-only after the independent implementation/smoke pass:

```text
legacy-source-pre-greenfield-2026-09-02@7556f86ecc65f37d34d9563ce2087f16235bbca5
includes/Integration/IPPanel_Provider.php
includes/Integration/Wp_HTTP_Client.php
includes/Services/PhoneNumberNormalizer.php
```

Findings:

- Legacy confirms that provider-specific HTTP normalization belongs behind a transport seam and that Edge pattern sends require one recipient. Both are already present in the greenfield target/current official contract.
- Legacy supports the usefulness of method-generic HTTP transport, but its old broad `wp_remote_request()` abstraction, raw provider error strings, legacy API mode, cron diagnostics, retries, request logging, and combined responsibilities are intentionally not transplanted.
- Legacy sender enumeration claimed an IPPanel endpoint not established by the current official contract; it remains rejected.
- The legacy phone normalizer delegated all recipient formatting to the primary provider. That coupling is deliberately rejected for heterogeneous fallback: only providers with a verified format conversion perform one at their adapter boundary. The legacy code provides no current-valid basis for the removed SMS.ir `9xxxxxxxxx` rewrite.
- No current-valid missing behavior was found that requires expanding the approved implementation.

Result: `NO_MATERIAL_FINDING` beyond the already independently implemented GET-capable HTTP seam and existing Edge single-recipient pattern rule.
