# WU-10 Final Qualification Dossier

> Scope: `GNM-001 / WU-10 / RUN-037`
> Project Contract revision: `4`
> Integration policy: `PR_REQUIRED`
> Status vocabulary: `PASS | NOT_PROVEN`; no inference from unexecuted evidence.

## Purpose

This dossier is the committed claim/evidence map for WU-10 under Contract revision 4. Exact final-Head outcome is determined only from GitHub Actions evidence attached to the PR Head being qualified. A later Head makes earlier exact-Head evidence stale.

RUN-037 reuses the compatible implementation and real-package harness already present on PR #15. RUN-036 and all WU-00..WU-09 history remain immutable. WordPress 5.9/6.8/6.9 results from RUN-036 are retained only as historical/informational evidence and cannot block Contract revision 4 acceptance.

The workflow logs and job summaries are the dynamic binding surface for the exact repository Head and GitHub run/job identity. The accompanying `EXECUTION_RESULT_HANDOFF_V1` records the final exact Head and CI references after qualification; a committed file cannot truthfully contain its own final commit SHA without changing that SHA.

## Current Contract Snapshot

- Repository authorization base: `main@a3281b94240868e0e32a1ea42a4612d8ba68c2f2`.
- Blocking WordPress baseline: `>=7.0`.
- Blocking WordPress targets: the 7.0 floor plus additional 7.x coverage using 7.1.
- Repository PHP floor: `>=8.2`; CI explicitly exercises PHP 8.2 and 8.3 where technically executable.
- Plugin metadata must not advertise WordPress support below 7.0.
- Authentic dependency path: `.github/workflows/real-gf-flow-integration.yml` → `tests/Integration/RealRuntime/run.sh`.
- Gravity Forms package SHA-256: `542f56ae0747f3661d1474996527298027db3fb8ed3e6469a6391aaabf61069b`.
- Gravity Flow package SHA-256: `ac0573b75831380417a21a455176e25eb746d718bbbd0bb70d6da6f48cba5404`.
- Package provenance classification is reported exactly as observed; `OWNER_AUTHORIZED_PUBLIC_SOURCE` is not promoted to a stronger provenance claim.
- Automated provider evidence uses only the deterministic loopback IPPanel simulator; no live SMS/Bale send is authorized by WU-10.

## Blocking compatibility matrix

| WordPress | PHP | Evidence mode | Qualification rule |
|---|---:|---|---|
| 7.0 | 8.2 | authentic GF/Flow compatibility | PASS only if the exact cell passes |
| 7.0 | 8.3 | authentic GF/Flow compatibility | PASS only if the exact cell passes |
| 7.1 | 8.2 | authentic GF/Flow compatibility | PASS only if the exact cell passes |
| 7.1 | 8.3 | authentic GF/Flow full regression | PASS only if the full manifest passes |

The 7.0 cells establish the accepted floor. The 7.1 cells provide additional 7.x evidence. PHP 8.2 and 8.3 are each exercised rather than inferred from one another. Untested combinations are not claimed as PASS.

## Historical/non-blocking compatibility evidence

RUN-036 exercised WordPress 5.9, 6.8 and 6.9. Those results are historical/informational only under Contract revision 4. In particular, the RUN-036 WordPress 5.9 failure caused by authentic Gravity Forms `3.1.1.1` requiring WordPress 6.5 does not block RUN-037 and does not define the current product floor.

Compatibility cells continue to use the same package admission, wp-env, real package loading and production GNM composition path as the full regression. No parallel mock harness is introduced.

## Claim map

| Claim / AC | Evidence required on exact final Head | PASS / NOT_PROVEN rule |
|---|---|---|
| AC-WU-10-01 objective implemented | PR diff + dossier + exact-Head CI | Contract rev 4 outputs exist and blocking evidence is complete |
| AC-WU-10-02 closed constraints/current contracts | authority docs + workflow/harness diff | no architecture reopen; version-sensitive behavior follows Contract rev 4 |
| AC-WU-10-03 truthful verification | WU-00 Qualification + Real GF Flow Integration | executed checks are represented exactly; missing evidence remains `NOT_PROVEN` |
| AC-WU-10-04 exact-Head authentic GF/Flow | Real GF Flow Integration exact-Head jobs | real packages load and claim-mapped tests pass on the exact final Head |
| AC-WU-10-05 WP/PHP compatibility | all four blocking 7.0/7.1 × PHP 8.2/8.3 cells | each cell records exact runtime identity and passes; no untested combination is inferred |
| AC-WU-10-06 dependency identity/provenance | package admission lines in real-package jobs | Head/run/job + product/version/SHA-256/source classification are present |
| AC-WU-10-07 lifecycle/diagnostics/cross-WU regression | WU-00 Qualification + 7.1/8.3 full RealRuntime manifest | unit/static suite and required real-runtime manifest pass on exact Head |
| AC-WU-10-08 deterministic IPPanel | `IPPANEL-CONTRACT-REAL-19` in full real-runtime manifest | strict loopback simulator path passes; no live send required |
| AC-WU-10-09 final qualification binding | this map + exact-Head CI logs/summaries + result handoff | all blocking results are bound to the same final PR Head |

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

Composer requires PHP `>=8.2`; the plugin header therefore remains `Requires PHP: 8.2`, subject to exact-Head PHP 8.2/8.3 evidence.

Contract revision 4 establishes the product WordPress floor at 7.0. The plugin header is therefore aligned to `Requires at least: 7.0` so it does not advertise support below the accepted baseline. Historical RUN-036 evidence below 7.0 is not reinterpreted as current support.

## Legacy differential review

WU-10 is qualification/compatibility/lifecycle/documentation work, not a new provider/runtime design. No new legacy implementation behavior is admitted by this repair. WU-09 retirement evidence remains the negative differential boundary; any repair must preserve the retired queue/sender architecture boundary.
