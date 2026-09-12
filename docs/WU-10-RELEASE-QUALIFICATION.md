# WU-10 Final Qualification Dossier

> Scope: `GNM-001 / WU-10 / RUN-036`
> Integration policy: `PR_REQUIRED`
> Status vocabulary: `PASS | NOT_PROVEN`; no inference from unexecuted evidence.

## Purpose

This dossier is the committed claim/evidence map for WU-10. Exact final-Head outcome is determined only from the GitHub Actions run attached to the PR Head being qualified. A later Head makes earlier exact-Head evidence stale.

The workflow logs and job summaries are part of this dossier: they record repository Head, GitHub run/job identity, WordPress target, exact PHP runtime, authentic Gravity Forms/Gravity Flow package version, SHA-256 and provenance classification.

## Current Contract Snapshot

- Repository authorization base: `main@a3281b94240868e0e32a1ea42a4612d8ba68c2f2`.
- Owner-required WordPress targets: 5.9 and 6.8 bridge targets; 6.9 current-host target.
- Repository PHP floor at authorization: `>=8.2`; existing CI runtime: PHP 8.3.
- Authentic dependency path: `.github/workflows/real-gf-flow-integration.yml` → `tests/Integration/RealRuntime/run.sh`.
- Gravity Forms expected package SHA-256: `542f56ae0747f3661d1474996527298027db3fb8ed3e6469a6391aaabf61069b`.
- Gravity Flow expected package SHA-256: `ac0573b75831380417a21a455176e25eb746d718bbbd0bb70d6da6f48cba5404`.
- Package provenance classification is reported exactly as observed; the current workflow input is `OWNER_AUTHORIZED_PUBLIC_SOURCE` and is not promoted to a stronger provenance claim.
- Automated provider evidence uses only the deterministic loopback IPPanel simulator; no live SMS/Bale send is authorized by WU-10.

## Required compatibility cells

| WordPress | PHP | Evidence mode | Required result |
|---|---:|---|---|
| 5.9 | 8.2 | authentic GF/Flow compatibility | PASS or claim remains NOT_PROVEN |
| 5.9 | 8.3 | authentic GF/Flow compatibility | PASS or claim remains NOT_PROVEN |
| 6.8 | 8.2 | authentic GF/Flow compatibility | PASS or claim remains NOT_PROVEN |
| 6.8 | 8.3 | authentic GF/Flow compatibility | PASS or claim remains NOT_PROVEN |
| 6.9 | 8.2 | authentic GF/Flow compatibility | PASS or claim remains NOT_PROVEN |
| 6.9 | 8.3 | authentic GF/Flow compatibility | PASS or claim remains NOT_PROVEN |
| 7.1 | 8.3 | preserved full real-runtime regression | PASS for the existing full regression claim |

Compatibility cells use the same package admission, wp-env, real package loading and production GNM composition path as the existing full regression. They do not create a parallel fake harness.

## Claim map

| Claim / AC | Evidence required on exact final Head | PASS condition |
|---|---|---|
| AC-WU-10-01 objective implemented | PR diff + WU-10 dossier + CI | required WU-10 outputs exist and no blocking result is NOT_PROVEN |
| AC-WU-10-02 closed constraints/current contracts | authoritative docs + workflow/harness diff | no architecture reopen; version-sensitive matrix is explicit |
| AC-WU-10-03 truthful verification | WU-00 Qualification + Real GF Flow Integration | relevant executed checks pass; unavailable evidence remains NOT_PROVEN |
| AC-WU-10-04 exact-Head authentic GF/Flow | Real GF Flow Integration exact-Head jobs | real packages load and claim-mapped tests pass on exact PR Head |
| AC-WU-10-05 WP/PHP compatibility | six required 5.9/6.8/6.9 matrix cells | each cell records exact WordPress and PHP identity and passes |
| AC-WU-10-06 dependency identity/provenance | package admission lines in every real-package job | Head/run/job + product/version/SHA-256/source classification are present |
| AC-WU-10-07 lifecycle/diagnostics/cross-WU regression | WU-00 Qualification + 7.1/8.3 full RealRuntime manifest | unit/static suite and required real-runtime manifest pass on exact Head |
| AC-WU-10-08 deterministic IPPanel | `IPPANEL-CONTRACT-REAL-19` in full real-runtime manifest | strict loopback simulator path passes; no live send required |
| AC-WU-10-09 final qualification binding | this map + exact-Head CI run/job logs/summaries | all blocking claims are bound to the same final PR Head |

## Cross-WU invariants

The final exact-Head evidence must retain the already-closed behavior exercised by the repository suites, including:

- `GFFeedAddOn` Feed as the logical notification Rule;
- Gravity Flow Feed-Step integration and workflow continuation on provider failure;
- synchronous dispatch with no queue/cron/background baseline;
- Gravity Forms Entry Meta delivery-state authority and explicit manual Retry;
- Attention Required/Entry Detail presentation contracts;
- WU-08 cutover authority behavior;
- WU-09 absence of legacy sender bootstrap/classes/hooks;
- deterministic IPPanel simulator contract including duplicate/contract rejection coverage.

## Metadata repair gate

At the authorized base, Composer requires PHP `>=8.2` while the plugin header declares `Requires PHP: 8.1` and `Requires at least: 6.0`.

WU-10 does not pre-judge the correction. First execute the required compatibility matrix. If WordPress 5.9 and the PHP 8.2 floor are proven with authentic dependencies, align package metadata to the proven floor/target using the smallest truthful change. If a required cell is unavailable or incompatible, leave the affected compatibility claim `NOT_PROVEN` and do not weaken the evidence standard.

## Legacy differential review

WU-10 is qualification/compatibility/lifecycle/documentation work, not a new provider/runtime design. No new legacy implementation behavior is admitted by this change. WU-09 retirement evidence remains the negative differential boundary; any later code repair discovered by qualification must still preserve the retired queue/sender architecture boundary.
