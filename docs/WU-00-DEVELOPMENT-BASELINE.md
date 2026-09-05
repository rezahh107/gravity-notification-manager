# WU-00 — Greenfield Development Baseline + No-Send Harness

> Project: Gravity Notification Manager  
> Work Unit: `WU-00`  
> Run: `RUN-001`  
> Inspection date: `2026-09-05`  
> Base repository Head: `f11ff49d9cd17d2e40ac8007c0c36241e783283f`

## Purpose

This document records the version-sensitive development baseline, automated no-send contract, and exact-target qualification procedure for WU-00. It does not reopen the closed architecture and does not implement Feed Rules, provider delivery, recipient resolution, Gravity Flow execution, migration, cutover, or real sends.

## Current Contract Snapshot

### Repository and PHP

- Repository base used for WU-00: `rezahh107/gravity-notification-manager@f11ff49d9cd17d2e40ac8007c0c36241e783283f`.
- `composer.json` requires PHP `>=8.2`.
- PHP 8.2, 8.3, 8.4, and 8.5 are supported PHP branches on the inspection date. PHP 8.2 is in security-support-only status through 2026-12-31; PHP 8.3 is supported through 2027-12-31.
- WU-00 CI uses PHP 8.3 because the repository tooling already targets PHP 8.3+ and current Gravity Forms guidance recommends PHP 8.3.

Official source inspected: `https://www.php.net/supported-versions.php`.

### WordPress

- WordPress 7.1 is the current stable major release on the inspection date.
- WordPress 7.1 was released on 2026-08-19.

Official sources inspected:

- `https://wordpress.org/documentation/wordpress-version/version-7-1/`
- `https://wordpress.org/documentation/article/wordpress-versions/`

### Gravity Forms

Current Gravity Forms system requirements on the inspection date state:

- WordPress: recommended 7.1; support baseline 7.0; minimum 6.5.
- PHP: recommended 8.3; minimum 7.4.

The GNM repository requirement of PHP `>=8.2` is therefore stricter than Gravity Forms' minimum PHP requirement. WU-00 consumes the supported-environment contract only; it does not depend on a version-specific Gravity Forms runtime API.

Official source inspected: `https://docs.gravityforms.com/gravity-forms-system-requirements/`.

### Gravity Flow

Gravity Flow states that it requires Gravity Forms and follows the same WordPress/PHP requirements. WU-00 does not consume a version-specific Gravity Flow runtime API.

Official sources inspected:

- `https://docs.gravityflow.io/what-are-the-minimum-requirements/`
- `https://docs.gravityflow.io/what-versions-of-php-does-gravity-flow-support/`

### GitHub Actions security baseline

The WU-00 workflow follows these constraints:

- `GITHUB_TOKEN` is limited to `contents: read`.
- No repository/provider secrets are required by WU-00.
- `pull_request_target` is not used.
- Third-party Actions are pinned to verified full commit SHAs.
- Workflow success must come from real command exit status; validation is not masked with `continue-on-error`.

Official sources inspected:

- `https://docs.github.com/en/actions/reference/security/secure-use`
- `https://docs.github.com/en/actions/reference/security/securely-using-pull_request_target`

## Greenfield bootstrap/test foundation

- New greenfield PHP code uses namespace root `GravityNotify`.
- Composer PSR-4 autoload maps `GravityNotify\\` to `src/`.
- Test PSR-4 autoload maps `GravityNotify\\Tests\\` to `tests/`.
- PHPUnit loads `tests/bootstrap.php`.
- Legacy `GFSMS` mappings remain unchanged for coexistence until their later controlled cutover/retirement Work Units.

## Deterministic no-send contract

Automated tests are fail-closed for outbound notification boundaries:

1. `tests/bootstrap.php` enables `GRAVITY_NOTIFY_TEST_NO_SEND`.
2. It also defines `WP_HTTP_BLOCK_EXTERNAL=true`, so a later WordPress HTTP implementation inherits an external-request block during tests.
3. `GravityNotify\Support\NoSendGuard` throws before an outbound boundary is allowed when test no-send mode is active.
4. `GravityNotify\Tests\Support\NoSendTransport` is the deterministic test double for HTTP/SMS/Bale boundary attempts and always passes through the guard.
5. Tests prove HTTP, SMS, and Bale attempts fail before any real network/provider operation can occur.

Future external adapters must call the no-send guard at their outbound boundary before any real network/provider action. WU-00 does not implement those adapters.

## Exact-target qualification procedure

For every implementation Run:

1. Record the exact base commit before mutation.
2. Perform the bounded implementation on a dedicated branch.
3. Record the exact final branch Head after all changes.
4. Run required validation against that exact final Head.
5. Bind CI evidence to the workflow run/job/check for the exact final Head.
6. Any code change after a green run invalidates that run for exact-target qualification and requires fresh validation.

Qualification vocabulary:

- `PASS`: the required check executed successfully against the exact target and has supporting evidence.
- `FAIL`: the required check executed and failed.
- `PARTIAL`: some material portion ran, but the whole requirement is not proven.
- `NOT_RUN`: the check did not execute.
- `NOT_PROVEN`: configuration or intent exists, but runtime evidence is insufficient.

Static workflow YAML, a workflow name, or a green run on another commit is never exact-target proof.

## WU-00 qualification checks

Blocking checks for this Run are:

- Composer package/autoload integrity.
- PHPUnit no-send harness behavior.
- PHPCS on WU-00 greenfield PHP/test surfaces.
- GitHub Actions execution on the exact final Head with normal blocking failure semantics.

PHPStan is present as a repository development dependency, but the current repository has no PHPStan configuration/path contract. WU-00 does not invent a parallel static-analysis scope; its Result must report PHPStan as not executed unless a valid repository-specific path/config is established during the Run.
