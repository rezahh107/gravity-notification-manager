# Live IPPanel Smoke Test

The live IPPanel workflow is an **operational smoke test**, not the deterministic PR software gate.

## Trigger

`.github/workflows/live-ippanel-pr.yml` is `workflow_dispatch`-only. It retains the `live-ippanel-pr` GitHub Environment, `contents: read`, bounded concurrency, exact-target identity verification, and one authorized live delivery attempt. It does not use `pull_request` or `pull_request_target`.

GitHub only exposes `workflow_dispatch` for a workflow present on the default branch. Until this workflow reaches `main`, an unmerged PR cannot truthfully claim a manual run of the converted workflow.

## What a successful live smoke proves

A manually authorized PASS proves current external connectivity through ArvanCloud/IPPanel, current account/credential acceptance, provider acceptance/reference behavior, delivery-report behavior, and real delivery to the configured smoke destination.

A live smoke failure is operational/external evidence. It does not redefine deterministic repository correctness.

## Security boundary

The live workflow reads `GNM_LIVE_IPPANEL_API_KEY`, `GNM_LIVE_SMS_FROM`, and `GNM_LIVE_SMS_TO` only from the `live-ippanel-pr` Environment. The deterministic simulator harness does not read these names. Repository YAML cannot prove that Required Reviewers or Environment secrets are actually configured in GitHub settings; those settings require separate external verification.

## Deterministic counterpart

PR software validation lives in `Real GF Flow Integration`. Required test `IPPANEL-CONTRACT-REAL-19` exercises real WordPress, Gravity Forms, Gravity Flow, `ProductionRuntime`, `IPPanelProvider`, `WordPressHttpTransport`, and a real loopback HTTP socket to a strict local simulator. It uses dummy data and zero real SMS-provider calls.
