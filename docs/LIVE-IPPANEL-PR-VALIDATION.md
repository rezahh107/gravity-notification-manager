# Live IPPanel PR Validation

> Scope: WU-08 validation infrastructure only. This workflow is not a production-site cutover and does not authorize merge.

## Trigger and isolation

`.github/workflows/live-ippanel-pr.yml` runs for pull requests to `main` only when the pull-request head belongs to this repository. Fork pull requests do not receive the live job because the existing same-repository guard skips them. The workflow uses `contents: read` permission only.

Ordinary unit and real-runtime integration suites remain fail-closed/no-send. The live workflow uses the separate `tests/Integration/LiveRuntime/` harness and is the only automated path authorized to make the bounded real IPPanel call described here.

The workflow declares bounded per-PR concurrency with `cancel-in-progress: true`. When a newer commit updates the same pull request, a superseded live-validation run is cancelled instead of allowing multiple obsolete live-delivery jobs to continue unnecessarily.

## Protected environment and required secrets

The live job references the dedicated GitHub Environment `live-ippanel-pr`. That environment is part of the required security boundary and must be configured in GitHub repository settings with at least one required reviewer before live credentials are made available to the job.

The following credentials must exist as **environment secrets** on `live-ippanel-pr` using these exact names:

- `GNM_LIVE_IPPANEL_API_KEY`
- `GNM_LIVE_SMS_FROM`
- `GNM_LIVE_SMS_TO`

They must not remain available as ordinary repository-wide live credentials. Missing environment secrets or a missing/unprotected approval boundary is a configuration/evidence failure, not a passing skip.

### OWNER_ACTION_REQUIRED — environment settings

Repository code cannot create or verify the required GitHub Environment protection settings. Before treating this live gate as security-complete, the repository owner must:

1. Create or open the `live-ippanel-pr` GitHub Environment in repository settings.
2. Configure an environment protection rule requiring review by at least one authorized reviewer before deployment/job access.
3. Add `GNM_LIVE_IPPANEL_API_KEY`, `GNM_LIVE_SMS_FROM`, and `GNM_LIVE_SMS_TO` as secrets of that environment.
4. Remove the old repository-level copies of those three credentials after migration, or rotate them and remove the superseded repository-level values, so the live credentials are not broadly available to same-repository PR code.
5. Verify on the next live validation that GitHub places the `live-ippanel-pr` job behind the required environment approval before any secret-bearing live execution begins.

Do not infer these settings from this file alone; they are GitHub-side configuration and require separate verification.

## Runtime and evidence

The live harness uses WordPress 7.1, PHP 8.3, and the same owner-authorized Gravity Forms/Gravity Flow packages and hashes as the repository's Real GF Flow Integration workflow. It creates a real Gravity Forms form, one greenfield GNM target Feed Step, and a separate non-GNM Gravity Flow Step that supplies the representative legacy event scope. It performs the existing WU-08 controlled cutover, configures the existing `ProductionRuntime`, and exercises the current synchronous `GravityNotify` production delivery chain.

The SMS body contains only a non-sensitive correlation marker derived from the GitHub Actions run ID and the first 12 hexadecimal characters of the exact PR Head. On success, the job emits only bounded evidence fields: correlation marker, provider outbox reference, provider-attempt count, delivery outcome, and delivery poll number. On provider-attempt failure, it emits only provider-neutral attempt status, provider/capability identifiers, the existing safe diagnostic classification, numeric HTTP status, a transport-error flag, and provider-reference count. It does not emit destination, sender, API key, Authorization headers, provider response bodies, or persisted secret-bearing configuration.

### Safe diagnostic observability

For root-cause diagnosis, the live bootstrap performs one authenticated **non-sending** `GET /api/number/numbers?page=1&per_page=10` preflight using the same API key. This checks Edge reachability and API-key acceptance without creating an SMS. It logs only HTTP status, transport-error state, body shape/length/SHA-256, allowlisted response metadata, provider safe error code where present, and whether the configured sender was observed on the first documented result page. The sender value itself is never logged.

The production `POST /api/send` remains the only authorized SMS attempt. A test-only `http_api_debug` observer records structural request metadata only: method/host/path, presence of the Authorization header, content type, top-level JSON schema keys, `sending_type`, recipient count, booleans showing whether sender/recipient match the configured secrets, and request-body length/SHA-256. For the response it records only HTTP status, transport-error state, content type, body shape/length/SHA-256, allowlisted provider error code, `Server`, request/trace identifier headers, and `Retry-After` where present. Raw request values, raw response bodies, credentials, sender/recipient values, and SMS text are never printed.

No diagnostic retry or second send is introduced. A failing diagnostic run remains failing unless the existing acceptance/reference/delivery assertions succeed.

## IPPanel contract snapshot

Inspected 2026-09-11 against the current first-party IPPanel Edge documentation and `ippanelcom/Edge-Document@14fd969f6c582e7ecc4380d9282cffb27c80c878`:

- Base URL: `https://edge.ippanel.com/v1`
- Plain synchronous send: `POST /api/send`, `sending_type=webservice`
- Authentication: raw API key/token in the `Authorization` header
- Sender: E.164 and assigned to the account
- Recipient: E.164
- Documented acceptance evidence: `data.message_outbox_ids`
- Recipient delivery report: `GET /api/report/recipients?bulk_id={message_outbox_id}`
- Documented `message_status=2`: delivered to recipient
- Documented terminal non-delivery states used by this harness: `3` not delivered, `4` blacklisted
- Authenticated number listing used for the non-send differential: `GET /api/number/numbers?page=1&per_page=10`

The first-party documentation inspected for this run does not document a source-IP allowlist requirement, rate-limit contract, or special 502/WAF handling for this send endpoint. Absence from documentation is not treated as proof that such provider-side controls cannot exist.

The delivery report is polled for a bounded window. Provider/network/reporting rejection, transport failure, or failure to reach the documented delivered state does not become PASS.

## Security boundary

The workflow deliberately does not use `pull_request_target`. The live job is bound to the `live-ippanel-pr` GitHub Environment while preserving the same-repository PR guard and exact-Head checkout. After the required environment review is approved, the three environment secrets are injected only into the single live execution step. A temporary secret-bearing JSON file is written inside the ignored `.wp-env.runtime/` directory with mode `0600`, consumed inside the ephemeral wp-env test runtime, and removed by the harness cleanup trap. No secret-bearing file is uploaded as an artifact.
