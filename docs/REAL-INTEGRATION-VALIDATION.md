# Real Gravity Forms and Gravity Flow Integration Validation

## Purpose and scope

This is the test-infrastructure contract for `WU-GNM-REAL-INTEGRATION-VALIDATION-01`. Existing unit tests continue to prove deterministic component behavior through fakes. This separate suite tests the greenfield `GravityNotify` code against real WordPress, Gravity Forms, and Gravity Flow classes and lifecycle behavior.

The harness does not change `src/`, activate the production plugin entrypoint, perform production cutover, or commit premium-package ZIPs to the repository.

## Current Contract Snapshot

| Item | Selected baseline |
| --- | --- |
| Repository baseline after PR #6 | `cbc0bb0d015527efe03aa72d9e34045c5067f2b0` |
| WordPress | `7.1`, explicitly pinned in `.wp-env.json` |
| PHP | `8.3` |
| Gravity Forms | `3.1.1.1` |
| Gravity Forms SHA-256 | `542f56ae0747f3661d1474996527298027db3fb8ed3e6469a6391aaabf61069b` |
| Gravity Flow | `3.1.0` |
| Gravity Flow SHA-256 | `ac0573b75831380417a21a455176e25eb746d718bbbd0bb70d6da6f48cba5404` |
| CI package source | Owner-authorized public Google Drive files |
| Inspection date | 2026-09-08 |

Official contracts consulted are the WordPress [`wp-env`](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/) reference, Gravity Forms [`GFFeedAddOn`](https://docs.gravityforms.com/gffeedaddon/) and [`GFAddOn`](https://docs.gravityforms.com/gfaddon/) references, and Gravity Flow [Feed Step](https://docs.gravityflow.io/step_feed_class/) and [Workflow Step Framework](https://docs.gravityflow.io/the-workflow-step-framework/) references.

The PR #6 repairs use the documented `Gravity_Flow_API::add_step()`, `get_step()`, `process_workflow()`, and `get_status()` surfaces. A selected Feed is persisted as the Feed-Step checkbox setting `feed_<feed-id>` and read back from the real Step before workflow execution. The fixture does not manufacture `gravityflow_steps` form data or write workflow-position Entry Meta. Gravity Forms lifecycle tests enter through real `GFFeedAddOn::maybe_process_feed()`, and GF-REAL-06 reads the latest official feed status through `GFAPI::get_entry_feed_status( $entry_id, $feed_id, true )`. The real-runtime PHPUnit configuration fails on skipped or incomplete tests, while a JUnit manifest validator independently requires every stable test ID and refuses errors, failures, or skipped tests before the wrapper can report `REAL_INTEGRATION_PASS`.

## Architecture and complexity decision

- **Harness:** `@wordpress/env` 11.14.0 as a development-only Docker environment.
- **Target requirement:** exercise real WordPress and premium-plugin runtime lifecycles without changing production activation.
- **Failure prevented / benefit:** detect incompatibilities hidden by unit stubs and provide repeatable local/CI validation.
- **Native alternative checked:** repository PHPUnit is intentionally isolated and stub-based; it cannot load a real WordPress plugin lifecycle.
- **Why simpler is insufficient:** direct production-method calls cannot prove Feed registration, persistence, conditions, or Flow interception.
- **Runtime/maintenance cost:** one pinned npm development dependency, a wrapper, an isolated PHPUnit bootstrap, and one GitHub Actions workflow.
- **Decision:** use official `wp-env`; do not add a custom Docker stack or browser framework.

## Premium-package admission

The harness accepts three explicitly owner-authorized source classes:

- `OWNER_LOCAL_PACKAGE`
- `OWNER_AUTHORIZED_SECURE_SOURCE`
- `OWNER_AUTHORIZED_PUBLIC_SOURCE`

The current GitHub Actions configuration uses `OWNER_AUTHORIZED_PUBLIC_SOURCE`. The owner has explicitly authorized the current Gravity Forms and Gravity Flow Google Drive files to be publicly downloadable for CI use. The workflow pins the expected SHA-256 values, downloads the files, verifies the hashes, validates that both downloads are ZIP archives, and only then passes them into the existing harness. The harness independently repeats SHA-256, path-safety, slug, plugin-header, and version admission checks.

The ZIP bytes themselves are not committed to this repository. GravityView is not part of this harness and is not downloaded or activated by this workflow.

A changed, inaccessible, HTML-substituted, or otherwise incorrect download must fail closed. A SHA mismatch is never accepted merely because a public URL still resolves.

## Layered no-send safety

The separate bootstrap defines `GRAVITY_NOTIFY_TEST_NO_SEND=true` and `WP_HTTP_BLOCK_EXTERNAL=true`, loads only the greenfield Composer classes after the real plugins, and injects in-memory providers. `NoSendGuard` blocks `WordPressHttpTransport` before `wp_remote_post()`. A test-only `pre_http_request` filter independently fails closed if any unexpected WordPress HTTP request reaches core. Synthetic `+1555...` destinations are used and no provider credentials are admitted.

## Local execution

Prerequisites are Docker, Node/npm, PHP, Composer dependencies, `sha256sum`, `unzip`, and two owner-authorized ZIPs. Install the locked npm dependency and run:

```bash
npm ci
GNM_GF_ZIP=/path/to/gravityforms.zip \
GNM_GF_SHA256=542f56ae0747f3661d1474996527298027db3fb8ed3e6469a6391aaabf61069b \
GNM_GFLOW_ZIP=/path/to/gravityflow.zip \
GNM_GFLOW_SHA256=ac0573b75831380417a21a455176e25eb746d718bbbd0bb70d6da6f48cba5404 \
GNM_PACKAGE_SOURCE_CLASS=OWNER_LOCAL_PACKAGE \
npm run test:integration:real
```

The wrapper validates inputs, creates ignored ephemeral extraction/configuration paths, resets and starts wp-env, executes PHPUnit inside `tests-cli`, prints package/runtime evidence, and stops the environment. Set `GNM_KEEP_WP_ENV=1` only while troubleshooting.

## CI execution and exact target

The **Real GF Flow Integration** workflow has three supported triggers:

1. every pull request targeting `main`;
2. every `push` to `main`, including normal PR merges;
3. manual `workflow_dispatch` for an optional exact `target_sha`.

For pull requests, the workflow deliberately validates `github.event.pull_request.head.sha` rather than GitHub's synthetic merge SHA. For automatic `main` runs, the exact triggering `github.sha` is validated. On a manual run, `target_sha` is used when supplied; otherwise the workflow falls back to the triggering SHA. Before environment setup, the workflow checks out the resolved exact SHA, compares it with `git rev-parse HEAD`, and records repository/ref/SHA/run/job identity.

The current owner-authorized public Google Drive package IDs and expected hashes are pinned in the workflow. No package URL/hash Secrets are required for this public-source configuration. Downloads use Google Drive's direct-download endpoint and must pass both SHA-256 verification and `unzip -t` before the real-runtime harness begins.

Because the package source is public and no repository Secrets are required, the real-runtime contract can be validated before merge on the exact PR Head and then rerun automatically after merge on the resulting `main` SHA.

## Results and troubleshooting

Environment states include `READY`, `NOT_EXECUTED_ENVIRONMENT_UNAVAILABLE`, `PACKAGE_INVALID`, `PACKAGE_HASH_MISMATCH`, and `PACKAGE_SOURCE_UNAUTHORIZED`. Tests report `PASS`, `FAIL`, `NOT_RUN`, or `NOT_ASSESSABLE`. Overall results are `REAL_INTEGRATION_PASS`, `REAL_INTEGRATION_DEFECT_FOUND`, `REAL_INTEGRATION_INCOMPLETE`, `HARNESS_FAILURE`, or `NOT_EXECUTED_ENVIRONMENT_UNAVAILABLE`.

- Public Drive download failure: confirm the file remains accessible and the file ID did not change.
- Hash mismatch: compare the downloaded bytes with the pinned owner-authorized package; do not update the digest merely to make CI green.
- Invalid package: confirm the expected ZIP contains `gravityforms/` or `gravityflow/` and an intact plugin header.
- Runtime test failure: retain the evidence and open a separate production-repair Work Unit; this validation Work Unit must not repair `src/`.
- Stale Docker state: let the wrapper's `wp-env clean all` reset it, then rerun against the same exact commit.

## Legacy differential review

No legacy implementation is used by this validation harness. The Work Unit validates the current greenfield runtime contract and introduces no production behavior to compare or salvage.
