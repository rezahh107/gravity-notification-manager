#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
RELEASE_WORKFLOW="$ROOT/.github/workflows/release.yml"
SMOKE_WORKFLOW="$ROOT/.github/workflows/artifact-install-smoke.yml"
BUILDER="$ROOT/tools/build-production-package.sh"
VALIDATOR="$ROOT/tools/validate-release-workflow-run-identity.sh"
V320_HEAD='360bd70747944c7bc246ded3831cb7330abe33d2'

cd "$ROOT"

fail() {
  local test_id="$1"
  shift
  echo "$test_id=FAIL: $*" >&2
  exit 1
}

count_fixed() {
  local needle="$1"
  local file="$2"
  grep -F -c "$needle" "$file" || true
}

for required in "$RELEASE_WORKFLOW" "$SMOKE_WORKFLOW" "$BUILDER" "$VALIDATOR"; do
  test -f "$required" || fail 'T-PKG-SSOT-01' "required contract surface is missing: $required"
done

# T-PKG-SSOT-01: both production-package callers execute the same builder and
# no workflow keeps an inline production Composer/staging/ZIP recipe.
[[ "$(count_fixed 'bash tools/build-production-package.sh' "$RELEASE_WORKFLOW")" == '1' ]] ||
  fail 'T-PKG-SSOT-01' 'release workflow must invoke the shared production-package builder exactly once'
[[ "$(count_fixed 'bash tools/build-production-package.sh' "$SMOKE_WORKFLOW")" == '1' ]] ||
  fail 'T-PKG-SSOT-01' 'artifact smoke workflow must invoke the shared production-package builder exactly once'

for workflow in "$RELEASE_WORKFLOW" "$SMOKE_WORKFLOW"; do
  if grep -Fq 'rm -rf vendor' "$workflow"; then
    fail 'T-PKG-SSOT-01' "duplicate production vendor reset remains in workflow: ${workflow#$ROOT/}"
  fi
  if grep -Fq 'cp -a src includes languages vendor' "$workflow"; then
    fail 'T-PKG-SSOT-01' "duplicate production staging recipe remains in workflow: ${workflow#$ROOT/}"
  fi
  if grep -Fq 'zip -X -q' "$workflow"; then
    fail 'T-PKG-SSOT-01' "duplicate deterministic ZIP recipe remains in workflow: ${workflow#$ROOT/}"
  fi
done

grep -Fq 'rm -rf vendor' "$BUILDER" ||
  fail 'T-PKG-SSOT-01' 'shared builder does not start from a clean vendor tree'
grep -Fq 'cp -a src includes languages vendor' "$BUILDER" ||
  fail 'T-PKG-SSOT-01' 'shared builder does not own production staging'
grep -Fq 'zip -X -q' "$BUILDER" ||
  fail 'T-PKG-SSOT-01' 'shared builder does not own deterministic ZIP creation'
grep -Fq 'sha256sum -c "$CHECKSUM_NAME"' "$BUILDER" ||
  fail 'T-PKG-SSOT-01' 'shared builder does not verify the generated checksum'
printf 'T-PKG-SSOT-01=PASS\n'

# The stale-vendor integration mutation must surround the real builder in the
# generated-artifact job. The actual mutation/build/result is executed by CI.
grep -Fq 'vendor/.gnm-stale-vendor-sentinel' "$SMOKE_WORKFLOW" ||
  fail 'T-PKG-STALE-VENDOR-02' 'generated-artifact job does not seed the stale-vendor sentinel'
grep -Fq 'T-PKG-STALE-VENDOR-02=PASS' "$SMOKE_WORKFLOW" ||
  fail 'T-PKG-STALE-VENDOR-02' 'generated-artifact job does not assert stale-vendor elimination'

# T-REL-EVENT-GATE-01: a successful v-looking head is not enough.
grep -Fq "github.event.workflow_run.conclusion == 'success'" "$SMOKE_WORKFLOW" ||
  fail 'T-REL-EVENT-GATE-01' 'published-release gate does not require successful triggering workflow'
grep -Fq "github.event.workflow_run.event == 'push'" "$SMOKE_WORKFLOW" ||
  fail 'T-REL-EVENT-GATE-01' 'published-release gate does not require a push-triggered workflow'
printf 'T-REL-EVENT-GATE-01=PASS\n'

# T-REL-ORDER-03: the production validator must execute in the downstream job
# before any GitHub Release download command.
PUBLISHED_SECTION="$(mktemp)"
POSITIVE_WORKTREE=''
TAG_MISMATCH_ERR=''
MALFORMED_ERR=''
EVENT_ERR=''
cleanup() {
  rm -f "$PUBLISHED_SECTION"
  [[ -z "$TAG_MISMATCH_ERR" ]] || rm -f "$TAG_MISMATCH_ERR"
  [[ -z "$MALFORMED_ERR" ]] || rm -f "$MALFORMED_ERR"
  [[ -z "$EVENT_ERR" ]] || rm -f "$EVENT_ERR"
  if [[ -n "$POSITIVE_WORKTREE" && -d "$POSITIVE_WORKTREE" ]]; then
    git worktree remove --force "$POSITIVE_WORKTREE" >/dev/null 2>&1 || true
  fi
}
trap cleanup EXIT

awk 'found { print } /^  published-release:/ { found=1; print }' "$SMOKE_WORKFLOW" > "$PUBLISHED_SECTION"
VALIDATE_LINE="$(grep -n -m1 'bash tools/validate-release-workflow-run-identity.sh' "$PUBLISHED_SECTION" | cut -d: -f1 || true)"
DOWNLOAD_LINE="$(grep -n -m1 'gh release download' "$PUBLISHED_SECTION" | cut -d: -f1 || true)"

[[ -n "$VALIDATE_LINE" ]] ||
  fail 'T-REL-ORDER-03' 'published-release job does not execute the shared release identity validator'
[[ -n "$DOWNLOAD_LINE" ]] ||
  fail 'T-REL-ORDER-03' 'published-release job does not contain the expected GitHub Release download'
(( VALIDATE_LINE < DOWNLOAD_LINE )) ||
  fail 'T-REL-ORDER-03' "release download precedes identity validation: validate_line=$VALIDATE_LINE download_line=$DOWNLOAD_LINE"
printf 'T-REL-ORDER-03=PASS\n'

# T-REL-TAG-BIND-02: execute the exact production predicate with one positive
# and negative tag/source, malformed-tag, and non-push-event controls.
OBSERVED_V320_HEAD="$(git rev-parse 'refs/tags/v3.2.0^{commit}')"
[[ "$OBSERVED_V320_HEAD" == "$V320_HEAD" ]] ||
  fail 'T-REL-TAG-BIND-02' "v3.2.0 fixture moved: expected=$V320_HEAD observed=$OBSERVED_V320_HEAD"

POSITIVE_WORKTREE="$(mktemp -d)"
rmdir "$POSITIVE_WORKTREE"
git worktree add --detach "$POSITIVE_WORKTREE" "$V320_HEAD" >/dev/null

WORKFLOW_EVENT='push' \
TAG_NAME='v3.2.0' \
SOURCE_HEAD="$V320_HEAD" \
GNM_REPOSITORY_ROOT="$POSITIVE_WORKTREE" \
GNM_RELEASE_IDENTITY_OUTPUT_FILE='' \
bash "$VALIDATOR" >/dev/null

CURRENT_HEAD="$(git rev-parse HEAD)"
TAG_MISMATCH_ERR="$(mktemp)"
MALFORMED_ERR="$(mktemp)"
EVENT_ERR="$(mktemp)"

if WORKFLOW_EVENT='push' \
  TAG_NAME='v3.2.0' \
  SOURCE_HEAD="$CURRENT_HEAD" \
  GNM_REPOSITORY_ROOT="$ROOT" \
  GNM_RELEASE_IDENTITY_OUTPUT_FILE='' \
  bash "$VALIDATOR" >/dev/null 2>"$TAG_MISMATCH_ERR"; then
  fail 'T-REL-TAG-BIND-02' 'same tag with a different source Head was incorrectly accepted'
fi
grep -Fq 'release-tag-source-mismatch' "$TAG_MISMATCH_ERR" ||
  fail 'T-REL-TAG-BIND-02' 'tag/source mismatch did not return the deterministic mismatch diagnostic'

if WORKFLOW_EVENT='push' \
  TAG_NAME='release-3.2.0' \
  SOURCE_HEAD="$V320_HEAD" \
  GNM_REPOSITORY_ROOT="$POSITIVE_WORKTREE" \
  GNM_RELEASE_IDENTITY_OUTPUT_FILE='' \
  bash "$VALIDATOR" >/dev/null 2>"$MALFORMED_ERR"; then
  fail 'T-REL-TAG-BIND-02' 'malformed non-version tag was incorrectly accepted'
fi
grep -Fq 'release-tag-format-invalid' "$MALFORMED_ERR" ||
  fail 'T-REL-TAG-BIND-02' 'malformed tag did not return the deterministic format diagnostic'

if WORKFLOW_EVENT='workflow_dispatch' \
  TAG_NAME='v3.2.0' \
  SOURCE_HEAD="$V320_HEAD" \
  GNM_REPOSITORY_ROOT="$POSITIVE_WORKTREE" \
  GNM_RELEASE_IDENTITY_OUTPUT_FILE='' \
  bash "$VALIDATOR" >/dev/null 2>"$EVENT_ERR"; then
  fail 'T-REL-EVENT-GATE-01' 'non-push workflow event was incorrectly accepted by the production validator'
fi
grep -Fq 'release-event-not-proven' "$EVENT_ERR" ||
  fail 'T-REL-EVENT-GATE-01' 'non-push event did not return the deterministic event diagnostic'

printf 'T-REL-TAG-BIND-02=PASS\n'
printf 'T-REL-EVENT-GATE-01-NEGATIVE=PASS\n'
printf 'ARTIFACT_RUNTIME_CONTRACTS=PASS\n'
