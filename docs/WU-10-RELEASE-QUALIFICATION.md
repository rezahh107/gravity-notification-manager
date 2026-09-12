# WU-10 Final Qualification Dossier

> Scope: `GNM-001 / WU-10 / RUN-036`
> Integration policy: `PR_REQUIRED`
> Status vocabulary: `PASS | NOT_PROVEN`; no inference from unexecuted evidence.

## Purpose

This dossier is the committed claim/evidence map for WU-10. Exact final-Head outcome is determined only from the GitHub Actions run attached to the PR Head being qualified. A later Head makes earlier exact-Head evidence stale.

The workflow logs and job summaries are the dynamic binding surface for the exact repository Head and GitHub run/job identity. The accompanying `EXECUTION_RESULT_HANDOFF_V1` records the final exact Head and CI references after qualification; a committed file cannot truthfully contain its own final commit SHA without changing that SHA.

## Current Contract Snapshot

- Repository authorization base: `main@a3281b94240868e0e32a1ea42a4612d8ba68c2f2`.
- Owner-requested WordPress targets: 5.9 and 6.8 bridge targets; 6.9 current-host target.
- Repository PHP floor: `>=8.2`; CI exercises PHP 8.2 and 8.3 where technically executable.
- Authentic dependency path: `.github/workflows/real-gf-flow-integration.yml` → `tests/Integration/RealRuntime/run.sh`.
- Gravity Forms package SHA-256: `542f56ae0747f3661d1474996527298027db3fb8ed3e6469a6391aaabf61069b`.
- Gravity Flow package SHA-256: `ac0573b75831380417a21a455176e25eb746d718bbbd0bb70d6da6f48cba5404`.
- Package provenance classification is reported exactly as observed; the workflow input `OWNER_AUTHORIZED_PUBLIC_SOURCE` is not promoted to a stronger provenance claim.
- Automated provider evidence uses only the deterministic loopback IPPanel simulator; no live SMS/Bale send is authorized by WU-10.

## Compatibility matrix and qualification semantics

| WordPress | PHP | Evidence mode | Qualification rule |
|---|---:|---|---|
| 5.9 | 8.2 | authentic GF/Flow compatibility | explicit execution required; incompatibility remains `NOT_PROVEN` |
| 5.9 | 8.3 | authentic GF/Flow compatibility | explicit execution required; incompatibility remains `NOT_PROVEN` |
| 6.8 | 8.2 | authentic GF/Flow compatibility | PASS only if exact cell passes |
| 6.8 | 8.3 | authentic GF/Flow compatibility | PASS only if exact cell passes |
| 6.9 | 8.2 | authentic GF/Flow compatibility | PASS only if exact cell passes |
| 6.9 | 8.3 | authentic GF/Flow compatibility | PASS only if exact cell passes |
| 7.1 | 8.3 | preserved full real-runtime regression | PASS only if full manifest passes |

The first exact-Head qualification pass established a material dependency constraint rather than a GNM assertion failure: authentic Gravity Forms `3.1.1.1` refuses activation on WordPress 5.9 and reports a minimum WordPress requirement of `6.5`. WU-10 therefore aligns plugin metadata to `Requires at least: 6.5` instead of fabricating 5.9 support. WordPress 5.9 remains an explicitly exercised but incompatible/`NOT_PROVEN` target for this dependency set.

Compatibility cells use the same package admission, wp-env, real package loading and production GNM composition path as the existing full regression. They do not create a parallel fake harness.

## Claim map

| Claim / AC | Evidence required on exact final Head | PASS / NOT_PROVEN rule |
|---|---|---|
| AC-WU-10-01 objective implemented | PR diff + dossier + exact-Head CI | required WU-10 outputs exist; unresolved required compatibility is reported, not inferred |
| AC-WU-10-02 closed constraints/current contracts | authority docs + workflow/harness diff | no architecture reopen; version-sensitive matrix is explicit |
| AC-WU-10-03 truthful verification | WU-00 Qualification + Real GF Flow Integration | executed checks are represented exactly; unavailable/incompatible evidence is not converted to PASS |
| AC-WU-10-04 exact-Head authentic GF/Flow | Real GF Flow Integration exact-Head jobs | real packages load and claim-mapped tests pass for supported cells |
| AC-WU-10-05 WP/PHP compatibility | all required 5.9/6.8/6.9 cells | each cell records exact identity; 5.9 dependency rejection is explicit `NOT_PROVEN`, not a synthetic PASS |
| AC-WU-10-06 dependency identity/provenance | package admission lines in real-package jobs | Head/run/job + product/version/SHA-256/source classification are present |
| AC-WU-10-07 lifecycle/diagnostics/cross-WU regression | WU-00 Qualification + 7.1/8.3 full RealRuntime manifest | unit/static suite and required real-runtime manifest pass on exact Head |
| AC-WU-10-08 deterministic IPPanel | `IPPANEL-CONTRACT-REAL-19` in full real-runtime manifest | strict loopback simulator path passes; no live send required |
| AC-WU-10-09 final qualification binding | this map + exact-Head CI logs/summaries + result handoff | all results are bound to the same final PR Head; 5.9 remains explicit where incompatible |

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

## Compatibility metadata repair

The authorized base declared Composer PHP `>=8.2` while the plugin header declared PHP `8.1`; this is repaired to `Requires PHP: 8.2`.

The authentic Gravity Forms `3.1.1.1` package used by the required workflow declares WordPress `6.5` as its minimum and refuses activation on WordPress 5.9. The plugin header is therefore aligned to `Requires at least: 6.5`. This does not silently remove the Owner-requested 5.9 qualification cell: the workflow still executes it so the incompatibility remains visible and evidence-bound.

## Legacy differential review

WU-10 is qualification/compatibility/lifecycle/documentation work, not a new provider/runtime design. No new legacy implementation behavior is admitted by this change. WU-09 retirement evidence remains the negative differential boundary; any later repair must preserve the retired queue/sender architecture boundary.
