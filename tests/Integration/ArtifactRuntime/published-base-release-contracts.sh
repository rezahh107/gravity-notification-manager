#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
RESOLVER="$ROOT/tools/resolve-pr-base-release-applicability.sh"
VALIDATOR="$ROOT/tools/validate-release-workflow-run-identity.sh"
SMOKE_WORKFLOW="$ROOT/.github/workflows/artifact-install-smoke.yml"
V320_HEAD='360bd70747944c7bc246ded3831cb7330abe33d2'
POST_RELEASE_HEAD='e07e6c2c84fed7572924208db5253fb05c7586e9'

cd "$ROOT"

fail() {
  local test_id="$1"
  shift
  echo "$test_id=FAIL: $*" >&2
  exit 1
}

read_output() {
  local key="$1"
  local file="$2"
  sed -n -E "s/^${key}=(.*)$/\\1/p" "$file" | tail -n1
}

for required in "$RESOLVER" "$VALIDATOR" "$SMOKE_WORKFLOW"; do
  test -f "$required" || fail 'T-PR-BASE-REL-APPLICABILITY-01' "required contract surface is missing: $required"
done

POSITIVE_OUTPUT="$(mktemp)"
POST_RELEASE_OUTPUT="$(mktemp)"
POST_RELEASE_LOG="$(mktemp)"
MISMATCH_ERR="$(mktemp)"
POSITIVE_WORKTREE=''
POST_RELEASE_WORKTREE=''
cleanup() {
  rm -f "$POSITIVE_OUTPUT" "$POST_RELEASE_OUTPUT" "$POST_RELEASE_LOG" "$MISMATCH_ERR"
  if [[ -n "$POSITIVE_WORKTREE" && -d "$POSITIVE_WORKTREE" ]]; then
    git worktree remove --force "$POSITIVE_WORKTREE" >/dev/null 2>&1 || true
  fi
  if [[ -n "$POST_RELEASE_WORKTREE" && -d "$POST_RELEASE_WORKTREE" ]]; then
    git worktree remove --force "$POST_RELEASE_WORKTREE" >/dev/null 2>&1 || true
  fi
}
trap cleanup EXIT

git fetch --tags --force origin >/dev/null

OBSERVED_V320_HEAD="$(git rev-parse 'refs/tags/v3.2.0^{commit}')"
[[ "$OBSERVED_V320_HEAD" == "$V320_HEAD" ]] ||
  fail 'T-PR-BASE-REL-APPLICABILITY-01' "v3.2.0 fixture moved: expected=$V320_HEAD observed=$OBSERVED_V320_HEAD"
git cat-file -e "$POST_RELEASE_HEAD^{commit}" 2>/dev/null ||
  fail 'T-PR-BASE-REL-NOT-APPLICABLE-02' "post-release fixture is unavailable: $POST_RELEASE_HEAD"

BASE_SHA="$V320_HEAD" \
GNM_REPOSITORY_ROOT="$ROOT" \
GNM_PR_BASE_RELEASE_OUTPUT_FILE="$POSITIVE_OUTPUT" \
bash "$RESOLVER" >/dev/null

[[ "$(read_output applicable "$POSITIVE_OUTPUT")" == 'true' ]] ||
  fail 'T-PR-BASE-REL-APPLICABILITY-01' 'exact release commit was not marked applicable'
[[ "$(read_output tag "$POSITIVE_OUTPUT")" == 'v3.2.0' ]] ||
  fail 'T-PR-BASE-REL-APPLICABILITY-01' 'exact release commit did not resolve the expected tag'
[[ "$(read_output source_head "$POSITIVE_OUTPUT")" == "$V320_HEAD" ]] ||
  fail 'T-PR-BASE-REL-APPLICABILITY-01' 'exact release commit did not preserve the base source identity'
[[ "$(read_output reason "$POSITIVE_OUTPUT")" == 'exact-release-tag-match' ]] ||
  fail 'T-PR-BASE-REL-APPLICABILITY-01' 'exact release commit did not emit the applicable reason'
printf 'T-PR-BASE-REL-APPLICABILITY-01=PASS\n'

BASE_SHA="$POST_RELEASE_HEAD" \
GNM_REPOSITORY_ROOT="$ROOT" \
GNM_PR_BASE_RELEASE_OUTPUT_FILE="$POST_RELEASE_OUTPUT" \
bash "$RESOLVER" >"$POST_RELEASE_LOG"

[[ "$(read_output applicable "$POST_RELEASE_OUTPUT")" == 'false' ]] ||
  fail 'T-PR-BASE-REL-NOT-APPLICABLE-02' 'post-release base was not marked non-applicable'
[[ "$(read_output tag "$POST_RELEASE_OUTPUT")" == 'v3.2.0' ]] ||
  fail 'T-PR-BASE-REL-NOT-APPLICABLE-02' 'post-release base did not resolve its metadata-derived expected tag'
[[ "$(read_output tag_commit "$POST_RELEASE_OUTPUT")" == "$V320_HEAD" ]] ||
  fail 'T-PR-BASE-REL-NOT-APPLICABLE-02' 'post-release base did not report the existing release tag commit'
[[ "$(read_output reason "$POST_RELEASE_OUTPUT")" == 'release-tag-does-not-identify-base' ]] ||
  fail 'T-PR-BASE-REL-NOT-APPLICABLE-02' 'post-release base did not emit the deterministic non-applicable reason'
grep -Fq 'PUBLISHED_BASE_RELEASE_APPLICABILITY=NOT_APPLICABLE' "$POST_RELEASE_LOG" ||
  fail 'T-PR-BASE-REL-NOT-APPLICABLE-02' 'post-release base did not emit inspectable NOT_APPLICABLE evidence'
printf 'T-PR-BASE-REL-NOT-APPLICABLE-02=PASS\n'

# The PR applicability resolver intentionally does not turn a post-release base
# into a release claim. When strict release validation is actually applicable,
# the existing production validator remains fail-closed.
POSITIVE_WORKTREE="$(mktemp -d)"
rmdir "$POSITIVE_WORKTREE"
git worktree add --detach "$POSITIVE_WORKTREE" "$V320_HEAD" >/dev/null
WORKFLOW_EVENT='push' \
TAG_NAME='v3.2.0' \
SOURCE_HEAD="$V320_HEAD" \
GNM_REPOSITORY_ROOT="$POSITIVE_WORKTREE" \
GNM_RELEASE_IDENTITY_OUTPUT_FILE='' \
bash "$VALIDATOR" >/dev/null

POST_RELEASE_WORKTREE="$(mktemp -d)"
rmdir "$POST_RELEASE_WORKTREE"
git worktree add --detach "$POST_RELEASE_WORKTREE" "$POST_RELEASE_HEAD" >/dev/null
if WORKFLOW_EVENT='push' \
  TAG_NAME='v3.2.0' \
  SOURCE_HEAD="$POST_RELEASE_HEAD" \
  GNM_REPOSITORY_ROOT="$POST_RELEASE_WORKTREE" \
  GNM_RELEASE_IDENTITY_OUTPUT_FILE='' \
  bash "$VALIDATOR" >/dev/null 2>"$MISMATCH_ERR"; then
  fail 'T-PR-BASE-REL-STRICT-03' 'strict release identity validator accepted a tag/source mismatch'
fi
grep -Fq 'release-tag-source-mismatch' "$MISMATCH_ERR" ||
  fail 'T-PR-BASE-REL-STRICT-03' 'strict mismatch did not preserve the deterministic fail-closed diagnostic'
printf 'T-PR-BASE-REL-STRICT-03=PASS\n'

# Wiring controls: the PR job must remain present/successful, resolve
# applicability explicitly, and gate all published-artifact actions on it.
grep -Fq 'bash tools/resolve-pr-base-release-applicability.sh' "$SMOKE_WORKFLOW" ||
  fail 'T-PR-BASE-REL-WIRING-04' 'PR published-base job does not execute the applicability resolver'
[[ "$(grep -F -c "if: steps.release.outputs.applicable == 'true'" "$SMOKE_WORKFLOW" || true)" -ge 4 ]] ||
  fail 'T-PR-BASE-REL-WIRING-04' 'published release download/install path is not fully gated by applicability'
grep -Fq 'PUBLISHED_BASE_RELEASE=NOT_APPLICABLE' "$SMOKE_WORKFLOW" ||
  fail 'T-PR-BASE-REL-WIRING-04' 'PR job does not record explicit NOT_APPLICABLE evidence'
grep -Fq 'gh release view "$TAG_NAME" --repo "$GITHUB_REPOSITORY"' "$SMOKE_WORKFLOW" ||
  fail 'T-PR-BASE-REL-WIRING-04' 'applicable PR base no longer requires the exact GitHub Release to exist'
printf 'T-PR-BASE-REL-WIRING-04=PASS\n'
printf 'PUBLISHED_BASE_RELEASE_CONTRACTS=PASS\n'
