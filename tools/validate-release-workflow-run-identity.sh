#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="${GNM_REPOSITORY_ROOT:-$(pwd)}"
OUTPUT_FILE="${GNM_RELEASE_IDENTITY_OUTPUT_FILE:-${GITHUB_OUTPUT:-}}"

: "${WORKFLOW_EVENT:?WORKFLOW_EVENT is required}"
: "${TAG_NAME:?TAG_NAME is required}"
: "${SOURCE_HEAD:?SOURCE_HEAD is required}"

cd "$ROOT"

fail() {
  local code="$1"
  shift
  echo "$code: $*" >&2
  exit 1
}

if [[ "$WORKFLOW_EVENT" != 'push' ]]; then
  fail 'release-event-not-proven' "triggering workflow event must be push, got: $WORKFLOW_EVENT"
fi

if [[ ! "$SOURCE_HEAD" =~ ^[0-9a-f]{40}$ ]]; then
  fail 'release-source-sha-invalid' "source Head is not an exact 40-character commit SHA: $SOURCE_HEAD"
fi

SEMVER_RE='^v[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z]+([.-][0-9A-Za-z]+)*)?(\+[0-9A-Za-z]+([.-][0-9A-Za-z]+)*)?$'
if [[ ! "$TAG_NAME" =~ $SEMVER_RE ]]; then
  fail 'release-tag-format-invalid' "candidate tag is not a full v-prefixed SemVer: $TAG_NAME"
fi

for command in git grep sed; do
  command -v "$command" >/dev/null 2>&1 ||
    fail 'release-identity-tool-missing' "required command is unavailable: $command"
done

git rev-parse --is-inside-work-tree >/dev/null 2>&1 ||
  fail 'release-checkout-invalid' "repository root is not a Git working tree: $ROOT"

git fetch --tags --force origin >/dev/null

OBSERVED_HEAD="$(git rev-parse HEAD)"
if [[ "$OBSERVED_HEAD" != "$SOURCE_HEAD" ]]; then
  fail 'release-checkout-head-mismatch' "checked-out Head does not equal workflow source Head: checkout=$OBSERVED_HEAD source=$SOURCE_HEAD"
fi

if ! TAG_COMMIT="$(git rev-parse --verify "refs/tags/$TAG_NAME^{commit}" 2>/dev/null)"; then
  fail 'release-tag-unresolved' "candidate Git tag cannot be resolved to a commit: $TAG_NAME"
fi

if [[ "$TAG_COMMIT" != "$SOURCE_HEAD" ]]; then
  fail 'release-tag-source-mismatch' "tag commit does not equal workflow source Head: tag=$TAG_COMMIT source=$SOURCE_HEAD"
fi

PLUGIN_SOURCE="$(git show "$SOURCE_HEAD:gravityflow-sms-ippanel.php")" ||
  fail 'release-plugin-metadata-unavailable' "cannot read plugin metadata at source Head: $SOURCE_HEAD"
PLUGIN_VERSION="$(printf '%s\n' "$PLUGIN_SOURCE" | grep -m1 -E '^[[:space:]]*\*[[:space:]]*Version:' | sed -E 's/.*Version:[[:space:]]*//; s/[[:space:]]+$//')"
TAG_VERSION="${TAG_NAME#v}"

if [[ -z "$PLUGIN_VERSION" ]]; then
  fail 'release-plugin-version-missing' "plugin Version metadata is missing at source Head: $SOURCE_HEAD"
fi

if [[ "$TAG_VERSION" != "$PLUGIN_VERSION" ]]; then
  fail 'release-tag-version-mismatch' "tag version does not equal plugin Version: tag=$TAG_VERSION plugin=$PLUGIN_VERSION"
fi

ZIP_NAME="gravity-notification-manager-$PLUGIN_VERSION.zip"

emit_output() {
  local key="$1"
  local value="$2"
  if [[ -n "$OUTPUT_FILE" ]]; then
    printf '%s=%s\n' "$key" "$value" >> "$OUTPUT_FILE"
  fi
}

emit_output version "$PLUGIN_VERSION"
emit_output tag "$TAG_NAME"
emit_output zip_name "$ZIP_NAME"
emit_output source_head "$SOURCE_HEAD"
emit_output tag_commit "$TAG_COMMIT"

printf 'RELEASE_WORKFLOW_IDENTITY=PASS\n'
printf 'WORKFLOW_EVENT=%s\n' "$WORKFLOW_EVENT"
printf 'TAG_NAME=%s\n' "$TAG_NAME"
printf 'SOURCE_HEAD=%s\n' "$SOURCE_HEAD"
printf 'TAG_COMMIT=%s\n' "$TAG_COMMIT"
printf 'PLUGIN_VERSION=%s\n' "$PLUGIN_VERSION"
