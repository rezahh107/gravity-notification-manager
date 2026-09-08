# Real Gravity Forms and Gravity Flow Integration Validation

## Purpose and scope

This is the test-infrastructure contract for `WU-GNM-REAL-INTEGRATION-VALIDATION-01`. Existing unit tests continue to prove deterministic component behavior through fakes. This separate suite tests the greenfield `GravityNotify` code against real WordPress, Gravity Forms, and Gravity Flow classes and lifecycle behavior.

The harness does not change `src/`, activate the production plugin entrypoint, perform production cutover, or redistribute premium packages.

## Current Contract Snapshot

| Item | Selected baseline |
| --- | --- |
| Repository base | `f9148cb30557e4317013733414ad24b89d2c2396` |
| WordPress | `7.1`, explicitly pinned in `.wp-env.json` |
| PHP | `8.3` |
| Gravity Forms | Owner-supplied, version detected after admission |
| Gravity Flow | Owner-supplied, version detected after admission |
| Inspection date | 2026-09-08 |

Official contracts consulted are the WordPress [`wp-env`](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/) reference, Gravity Forms [`GFFeedAddOn`](https://docs.gravityforms.com/gffeedaddon/) and [`GFAddOn`](https://docs.gravityforms.com/gfaddon/) references, and Gravity Flow [Feed Step](https://docs.gravityflow.io/step_feed_class/) and [Workflow Step Framework](https://docs.gravityflow.io/the-workflow-step-framework/) references. The repository's closed architecture identifies WordPress 7.1 as its stable current reference. Direct documentation retrieval was unavailable in the implementation environment, so no newer unverified baseline was substituted.

The PR #6 repairs use the documented `Gravity_Flow_API::add_step()`, `get_step()`, `process_workflow()`, and `get_status()` surfaces. A selected Feed is persisted as the Feed-Step checkbox setting `feed_<feed-id>` and read back from the real Step before workflow execution. The fixture does not manufacture `gravityflow_steps` form data or write workflow-position Entry Meta. Gravity Forms lifecycle tests enter through real `GFFeedAddOn::maybe_process_feed()`, and GF-REAL-06 reads the official `GFAPI::get_entry_feed_status( $entry_id, $feed_id )` result. The real-runtime PHPUnit configuration fails on skipped or incomplete tests, while a JUnit manifest validator independently requires every stable test ID and refuses errors, failures, or skipped tests before the wrapper can report `REAL_INTEGRATION_PASS`.

## Architecture and complexity decision

- **Proposed machinery:** `@wordpress/env` 11.14.0 as a development-only Docker harness.
- **Target requirement:** exercise real WordPress and premium-plugin runtime lifecycles without changing production activation.
- **Failure prevented / benefit:** detects incompatibilities hidden by unit stubs and provides a repeatable local/CI environment.
- **Native alternative checked:** repository PHPUnit is intentionally isolated and stub-based; it cannot load a real WordPress plugin lifecycle.
- **Why simpler is insufficient:** calling production methods directly cannot prove Feed registration, persistence, conditions, or Flow interception.
- **Runtime/maintenance cost:** one pinned npm development dependency, a wrapper, an isolated PHPUnit bootstrap, and a dispatch-only workflow.
- **Decision:** use official `wp-env`; do not add a custom Docker stack or browser framework.

## Premium-package admission

Both ZIPs must be supplied either as explicit owner-local files (`OWNER_LOCAL_PACKAGE`) or through owner-authorized CI secrets (`OWNER_AUTHORIZED_SECURE_SOURCE`). The wrapper rejects missing files, malformed or mismatched owner-provided SHA-256 values, unsafe ZIP paths, wrong plugin directory slugs, and missing plugin headers/versions. It reports filename, detected slug, detected version, verified SHA-256, and source class—but never a private source URL.

Gravity Forms and Gravity Flow ZIPs, extracted trees, private URLs, and credentials are never committed or uploaded as public artifacts. Missing packages produce `NOT_EXECUTED_ENVIRONMENT_UNAVAILABLE`, not a successful validation.

## Layered no-send safety

The separate bootstrap defines `GRAVITY_NOTIFY_TEST_NO_SEND=true` and `WP_HTTP_BLOCK_EXTERNAL=true`, loads only the greenfield Composer classes after the real plugins, and injects in-memory providers. `NoSendGuard` blocks `WordPressHttpTransport` before `wp_remote_post()`. A test-only `pre_http_request` filter independently fails closed if any unexpected WordPress HTTP request reaches core. Synthetic `+1555...` destinations are used and no provider credentials are admitted.

## Local execution

Prerequisites are Docker, Node/npm, PHP, Composer dependencies, `sha256sum`, `unzip`, and two owner-authorized ZIPs. Install the locked npm dependency and run:

```bash
npm ci
GNM_GF_ZIP=/secure/gravityforms.zip \
GNM_GF_SHA256=<owner-provided-64-hex-digest> \
GNM_GFLOW_ZIP=/secure/gravityflow.zip \
GNM_GFLOW_SHA256=<owner-provided-64-hex-digest> \
GNM_PACKAGE_SOURCE_CLASS=OWNER_LOCAL_PACKAGE \
npm run test:integration:real
```

The wrapper validates inputs, creates ignored ephemeral extraction/configuration paths, resets and starts wp-env, executes PHPUnit inside `tests-cli`, prints package/runtime evidence, and stops the environment. Set `GNM_KEEP_WP_ENV=1` only while troubleshooting.

## CI execution and exact target

Dispatch **Real GF Flow Integration** with a full `target_sha`. The workflow checks out that exact SHA, compares it with `git rev-parse HEAD` before setup, and records repository/ref/SHA/run/job identity. CI package URLs and expected hashes are accepted only through `GNM_GF_PACKAGE_URL`, `GNM_GFLOW_PACKAGE_URL`, `GNM_GF_SHA256`, and `GNM_GFLOW_SHA256` secrets. If any are absent, the workflow fails with `NOT_EXECUTED_ENVIRONMENT_UNAVAILABLE`.

## Results and troubleshooting

Environment states are `READY`, `NOT_EXECUTED_ENVIRONMENT_UNAVAILABLE`, `PACKAGE_INVALID`, `PACKAGE_HASH_MISMATCH`, and `PACKAGE_SOURCE_UNAUTHORIZED`. Tests report `PASS`, `FAIL`, `NOT_RUN`, or `NOT_ASSESSABLE`. Overall results are `REAL_INTEGRATION_PASS`, `REAL_INTEGRATION_DEFECT_FOUND`, `REAL_INTEGRATION_INCOMPLETE`, or `NOT_EXECUTED_ENVIRONMENT_UNAVAILABLE`.

- Missing prerequisite/package: install it or provide the explicit owner-authorized path; do not substitute a mirror.
- Hash mismatch: obtain the expected digest from the owner; never update it merely to accept an unexplained file.
- Invalid package: confirm the official ZIP contains `gravityforms/` or `gravityflow/` and an intact plugin header.
- Runtime test failure: retain the evidence and open a separate production-repair Work Unit; this validation Work Unit must not repair `src/`.
- Stale Docker state: let the wrapper's `wp-env clean all` reset it, then rerun against the same exact commit.

## Legacy differential review

No legacy implementation was needed or inspected. This Work Unit validates the current greenfield runtime contract and introduces no production behavior to compare or salvage.
