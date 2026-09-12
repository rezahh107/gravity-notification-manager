# Real Integration Validation

`Real GF Flow Integration` is the deterministic repository-controlled integration gate. It uses real WordPress, real Gravity Forms, real Gravity Flow, and production GNM composition while automated no-send safeguards remain active.

## WU-10 compatibility matrix

WU-10 keeps one real-package harness and extends it with explicit target cells rather than adding a parallel integration framework.

Required compatibility evidence is executed on the exact PR Head for:

- WordPress 5.9 with PHP 8.2 and PHP 8.3;
- WordPress 6.8 with PHP 8.2 and PHP 8.3;
- WordPress 6.9 with PHP 8.2 and PHP 8.3.

The existing WordPress 7.1 / PHP 8.3 full real-runtime regression is retained as a newer-target regression cell. Every cell logs the exact repository Head, GitHub run/job identity, WordPress target, host/container PHP runtime, Gravity Forms and Gravity Flow observed package versions, package SHA-256 values, and the exact source/provenance classification.

The compatibility cells use `WU10-COMPAT-REAL-01` to prove that the requested WordPress/PHP runtime is actually active, the admitted Gravity Forms/Gravity Flow packages are real rather than test stubs, the GNM `GFFeedAddOn` is registered, and the Gravity Flow notification Step is discoverable.

A failed or unavailable cell leaves that compatibility claim not proven; it is never promoted from another target.

## Authentic package evidence

The workflow admits the same owner-authorized package inputs in every real-package cell. `tests/Integration/RealRuntime/run.sh` verifies the expected SHA-256 before extraction, detects the plugin header and observed version from each actual package, and records the source classification. The classification is reported verbatim; `OWNER_AUTHORIZED_PUBLIC_SOURCE` does not imply vendor-authenticated, secure, or official provenance.

## Deterministic IPPanel Contract Integration

Required manifest identifier `IPPANEL-CONTRACT-REAL-19` proves the path:

`Real WordPress → Real Gravity Forms → Real Gravity Flow → ProductionRuntime → IPPanelProvider → WordPressHttpTransport → real 127.0.0.1 HTTP socket → strict IPPanel simulator`.

The production IPPanel send URL remains `https://edge.ippanel.com/v1/api/send`. Tests may inject only an `http://127.0.0.1:<port>` endpoint while `GRAVITY_NOTIFY_TEST_NO_SEND=true`; `WordPressHttpTransport` rejects any other automated outbound URL. `WP_HTTP_BLOCK_EXTERNAL=true` remains active and only `127.0.0.1` is allowlisted for the simulator.

The simulator validates authorization, method, path, JSON content type, JSON decoding, `sending_type`, sender, message, recipient shape, and duplicate sends. A valid send returns `data.message_outbox_ids`, which is parsed by the normal production `IPPanelProvider`. The deterministic report endpoint returns the documented recipient-report shape and the success case reaches `message_status=2`.

Negative coverage includes missing/wrong authorization, wrong method/path/content type, malformed JSON, invalid request shape, provider rejection, acceptance without a usable reference, terminal non-delivery, bounded never-delivered state, and unexpected duplicate send.

This gate proves software/contract behavior. It does **not** prove that IPPanel is currently online, that ArvanCloud currently permits GitHub traffic, that live credentials are valid, or that a real SMS can currently be delivered.

## Live provider smoke

`.github/workflows/live-ippanel-pr.yml` is the separate manually triggered `Live IPPanel Smoke Test`. A successful manual run proves current external/provider conditions. Its result is intentionally not the deterministic PR merge-validation mechanism. WU-10 does not authorize or require this live send.
