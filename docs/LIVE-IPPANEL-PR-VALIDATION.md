# Live IPPanel PR Validation

> Scope: WU-08 validation infrastructure only. This workflow is not a production-site cutover and does not authorize merge.

## Trigger and isolation

`.github/workflows/live-ippanel-pr.yml` runs for pull requests to `main` only when the pull-request head belongs to this repository. Fork pull requests do not receive repository secrets and the live job is skipped by the same-repository guard. The workflow uses `contents: read` permission only.

Ordinary unit and real-runtime integration suites remain fail-closed/no-send. The live workflow uses the separate `tests/Integration/LiveRuntime/` harness and is the only automated path authorized to make the bounded real IPPanel call described here.

## Required repository secrets

The job requires these exact secret names and never prints their values:

- `GNM_LIVE_IPPANEL_API_KEY`
- `GNM_LIVE_SMS_FROM`
- `GNM_LIVE_SMS_TO`

Missing secrets on an eligible same-repository PR are a configuration/evidence failure, not a passing skip.

## Runtime and evidence

The live harness uses WordPress 7.1, PHP 8.3, and the same owner-authorized Gravity Forms/Gravity Flow packages and hashes as the repository's Real GF Flow Integration workflow. It creates a real Gravity Forms form, a representative Gravity Flow Feed Step, performs the existing WU-08 controlled cutover, configures the existing `ProductionRuntime`, and exercises the current synchronous `GravityNotify` production delivery chain.

The SMS body contains only a non-sensitive correlation marker derived from the GitHub Actions run ID and the first 12 hexadecimal characters of the exact PR Head. The job emits only bounded evidence fields: correlation marker, provider outbox reference, provider-attempt count, delivery outcome, and delivery poll number. It does not emit destination, sender, API key, provider response bodies, or persisted secret-bearing configuration.

## IPPanel contract snapshot

Inspected 2026-09-10 against `ippanelcom/Edge-Document@14fd969f6c582e7ecc4380d9282cffb27c80c878`:

- Base URL: `https://edge.ippanel.com/v1`
- Plain synchronous send: `POST /api/send`, `sending_type=webservice`
- Documented acceptance evidence: `data.message_outbox_ids`
- Recipient delivery report: `GET /api/report/recipients?bulk_id={message_outbox_id}`
- Documented `message_status=2`: delivered to recipient
- Documented terminal non-delivery states used by this harness: `3` not delivered, `4` blacklisted

The delivery report is polled for a bounded window. Provider/network/reporting rejection, transport failure, or failure to reach the documented delivered state does not become PASS.

## Security boundary

The workflow deliberately does not use `pull_request_target`. Secrets are injected only into the single live execution step. A temporary secret-bearing JSON file is written inside the ignored `.wp-env.runtime/` directory with mode `0600`, consumed inside the ephemeral wp-env test runtime, and removed by the harness cleanup trap. No secret-bearing file is uploaded as an artifact.
