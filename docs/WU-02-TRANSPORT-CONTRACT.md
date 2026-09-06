# WU-02 Transport Contract Snapshot

Run: `GNM-001-WU-02-RUN-008`

This document records only the current official transport facts relied upon by the WU-02 implementation. It is not a general provider specification and does not authorize background delivery, retries, recipient resolution, persistence, or production cutover.

## IPPanel Edge SMS

Official sources:

- https://ippanelcom.github.io/Edge-Document/docs/send/
- https://ippanelcom.github.io/Edge-Document/docs/send/webservice/
- https://ippanelcom.github.io/Edge-Document/docs/send/pattern/

Relied-upon facts:

- The current SMS base URL is `https://edge.ippanel.com/v1`; both implemented modes use `POST /api/send`.
- Authentication is sent in the `Authorization` header and requests use JSON.
- Plain/webservice mode uses `sending_type=webservice`, `from_number`, `message`, and `params.recipients`.
- The official webservice example and request schema support multiple recipients, so WU-02 exposes `plain` and `multi_recipient_plain`.
- Pattern mode uses `sending_type=pattern`, `from_number`, `code`, `recipients`, and `params`.
- Pattern sending explicitly permits only one recipient, so WU-02 exposes `pattern` but not `multi_recipient_pattern`.
- Both `from_number` and recipient addresses are documented in E.164 form; WU-02 validates that envelope before HTTP I/O and does not perform local-number normalization.
- Documented successful responses include `meta.status=true` and `data.message_outbox_ids`; WU-02 preserves those IDs as safe provider references.
- Documented provider errors use `meta.status=false` and HTTP error responses. A 2xx response whose documented acceptance shape cannot be established is classified `AMBIGUOUS`.
- WU-02 does not use scheduled send fields.

## WordPress HTTP API

Official sources:

- https://developer.wordpress.org/reference/functions/wp_remote_post/
- https://developer.wordpress.org/reference/functions/is_wp_error/
- https://developer.wordpress.org/reference/functions/wp_remote_retrieve_response_code/
- https://developer.wordpress.org/reference/functions/wp_remote_retrieve_body/

Relied-upon facts:

- `wp_remote_post()` performs a POST and returns an array or `WP_Error`.
- `is_wp_error()` is the supported check for a WordPress error object.
- `wp_remote_retrieve_response_code()` retrieves the HTTP response code.
- `wp_remote_retrieve_body()` retrieves the response body.
- Production transport is therefore a narrow adapter around these supported WordPress functions; tests inject a fake seam and make no real network request.

## Bale Bot API

Official source:

- https://docs.bale.ai/

Relied-upon facts:

- Bot API requests use HTTPS at `https://tapi.bale.ai/bot<token>/METHOD_NAME`.
- POST with `application/json` parameters is supported.
- API responses are JSON and use boolean `ok`; `ok=true` provides `result`, while `ok=false` provides `error_code`.
- `sendMessage` requires `chat_id` and text of 1-4096 characters and returns the sent Message on success.
- WU-02 therefore treats `ok=true` plus a returned Message `message_id` as documented acceptance, `ok=false` plus `error_code` as rejection, and malformed/acceptance-uncertain 2xx responses as `AMBIGUOUS`.
- Bale is implemented as a separate channel and is never registered as an SMS provider.

## Safety and semantic boundaries

- No real provider/Bale send is used by tests or CI.
- Credentials and resolved destination identifiers are input-only transport data and are not included in attempt diagnostics.
- Pattern and Plain are distinct semantics; dispatch never converts between them to gain provider eligibility.
- Delivery is synchronous only. WU-02 introduces no queue, cron, worker, delayed retry, reconciliation, persistence, or Entry Meta writes.

## Bounded legacy differential review

- Greenfield implementation/test head reviewed first: `ac58ce27fcab078bbca066f28d97b636a04dcbfb`.
- Manifest authority: `docs/SALVAGE_REFERENCE.md` (`GNM-LEGACY-DIFFERENTIAL-REFERENCE-1.2.0`).
- Immutable legacy reference: tag `legacy-source-pre-greenfield-2026-09-02`, commit `7556f86ecc65f37d34d9563ce2087f16235bbca5`.
- Inspected manifest-approved assets: `includes/Integration/IPPanel_Provider.php`, `includes/Integration/Wp_HTTP_Client.php`, and `includes/Integration/HTTP_Client_Interface.php`.
- Outcome: `MATERIAL_GAP`.
- Current-valid finding: the legacy provider performed provider-address validation/normalization, and the current official IPPanel Edge contract requires both `from_number` and recipients to be E.164. WU-02 accepts already-resolved/normalized transport inputs, so it now validates the E.164 envelope and fails before HTTP I/O when that invariant is violated.
- Intentionally not incorporated: legacy local/Iranian phone normalization, legacy API mode, Bearer-auth alternative, scheduled-send handling, retry classification, diagnostics/logging, raw response retention, and legacy orchestration. Those are stale, out of scope, undocumented by the current relied-upon contract, or owned by later Work Units.
- WordPress seam review found no additional current-valid material gap: the greenfield seam already preserves the relevant `WP_Error`/status/body normalization while intentionally discarding raw potentially sensitive error text.
- Bale had no manifest-approved equivalent legacy asset, so no unapproved legacy Bale implementation was inspected.
