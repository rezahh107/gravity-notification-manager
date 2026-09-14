#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="${GNM_REPOSITORY_ROOT:-$(pwd)}"
OUTPUT_FILE="${GNM_PR_BASE_RELEASE_OUTPUT_FILE:-${GITHUB_OUTPUT:-}}"

: "${BASE_SHA:?BASE_SHA is required}"

cd "$ROOT"

fail() {
  local code="$1"
  shift
  echo "$code: $*" >&2
  exit 1
}

emit_output() {
  local key="$1"
  local value="$2"
  if [[ -n "$OUTPUT_FILE" ]]; then
    printf '%s=%s\n' "$key" "$value" >> "$OUTPUT_FILE"
  fi
}

if [[ ! "$BASE_SHA" =~ ^[0-9a-f]{40}$ ]]; then
  fail 'published-base-source-sha-invalid' "PR base Head is not an exact 40-character commit SHA: $BASE_SHA"
fi

for command in git grep sed; do
  command -v "$command" >/dev/null 2>&1 ||
    fail 'published-base-tool-missing' "required command is unavailable: $command"
done

git rev-parse --is-inside-work-tree >/dev/null 2>&1 ||
  fail 'published-base-checkout-invalid' "repository root is not a Git working tree: $ROOT"

git fetch --tags --force origin >/dev/null

PLUGIN_SOURCE="$(git show "$BASE_SHA:gravityflow-sms-ippanel.php" 2>/dev/null)" ||
  fail 'published-base-plugin-metadata-unavailable' "cannot read plugin metadata at PR base Head: $BASE_SHA"
PLUGIN_VERSION="$(printf '%s\n' "$PLUGIN_SOURCE" | grep -m1 -E '^[[:space:]]*\*[[:space:]]*Version:' | sed -E 's/.*Version:[[:space:]]*//; s/[[:space:]]+$//')"
SEMVER_RE='^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z]+([.-][0-9A-Za-z]+)*)?(\+[0-9A-Za-z]+([.-][0-9A-Za-z]+)*)?$'

if [[ -z "$PLUGIN_VERSION" ]]; then
  fail 'published-base-plugin-version-missing' "plugin Version metadata is missing at PR base Head: $BASE_SHA"
fi
if [[ ! "$PLUGIN_VERSION" =~ $SEMVER_RE ]]; then
  fail 'published-base-plugin-version-invalid' "plugin Version metadata is not SemVer at PR base Head: $PLUGIN_VERSION"
fi

TAG_NAME="v$PLUGIN_VERSION"
emit_output version "$PLUGIN_VERSION"
emit_output tag "$TAG_NAME"
emit_output source_head "$BASE_SHA"

if ! TAG_COMMIT="$(git rev-parse --verify "refs/tags/$TAG_NAME^{commit}" 2>/dev/null)"; then
  emit_output applicable 'false'
  emit_output reason 'release-tag-unresolved'
  emit_output tag_commit ''
  printf 'PUBLISHED_BASE_RELEASE_APPLICABILITY=NOT_APPLICABLE reason=release-tag-unresolved base=%s tag=%s\n' \
    "$BASE_SHA" "$TAG_NAME"
  exit 0
fi

emit_output tag_commit "$TAG_COMMIT"

if [[ "$TAG_COMMIT" != "$BASE_SHA" ]]; then
  emit_output applicable 'false'
  emit_output reason 'release-tag-does-not-identify-base'
  printf 'PUBLISHED_BASE_RELEASE_APPLICABILITY=NOT_APPLICABLE reason=release-tag-does-not-identify-base base=%s tag=%s tag_commit=%s\n' \
    "$BASE_SHA" "$TAG_NAME" "$TAG_COMMIT"
  exit 0
fi

emit_output applicable 'true'
emit_output reason 'exact-release-tag-match'
printf 'PUBLISHED_BASE_RELEASE_APPLICABILITY=APPLICABLE base=%s tag=%s tag_commit=%s\n' \
  "$BASE_SHA" "$TAG_NAME" "$TAG_COMMIT"
