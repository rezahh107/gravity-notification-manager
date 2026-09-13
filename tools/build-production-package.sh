#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="${GNM_REPOSITORY_ROOT:-$(pwd)}"
OUTPUT_FILE="${GNM_PACKAGE_OUTPUT_FILE:-${GITHUB_OUTPUT:-}}"
EXPECTED_VERSION="${GNM_PACKAGE_VERSION:-}"
PLUGIN_ROOT='gravity-notification-manager'
PLUGIN_FILE='gravityflow-sms-ippanel.php'
STAGE="dist/$PLUGIN_ROOT"

cd "$ROOT"

fail() {
  echo "production-package-build: $*" >&2
  exit 1
}

for command in git php composer zip unzip sha256sum grep sed find touch; do
  command -v "$command" >/dev/null 2>&1 || fail "required command is unavailable: $command"
done

git rev-parse --is-inside-work-tree >/dev/null 2>&1 || fail "repository root is not a Git working tree: $ROOT"
SOURCE_HEAD="$(git rev-parse HEAD)"
SOURCE_DATE_EPOCH="$(git show -s --format=%ct "$SOURCE_HEAD")"

HEADER_VERSION="$(grep -m1 -E '^[[:space:]]*\*[[:space:]]*Version:' "$PLUGIN_FILE" | sed -E 's/.*Version:[[:space:]]*//; s/[[:space:]]+$//')"
CONSTANT_VERSION="$(grep -m1 "define( 'GFSMS_PLUGIN_VERSION'" "$PLUGIN_FILE" | sed -E "s/.*'GFSMS_PLUGIN_VERSION',[[:space:]]*'([^']+)'.*/\1/")"
REQUIRES_PHP="$(grep -m1 -E '^[[:space:]]*\*[[:space:]]*Requires PHP:' "$PLUGIN_FILE" | sed -E 's/.*Requires PHP:[[:space:]]*//; s/[[:space:]]+$//')"
REQUIRES_WP="$(grep -m1 -E '^[[:space:]]*\*[[:space:]]*Requires at least:' "$PLUGIN_FILE" | sed -E 's/.*Requires at least:[[:space:]]*//; s/[[:space:]]+$//')"
COMPOSER_PHP="$(php -r '$c=json_decode(file_get_contents("composer.json"), true, 512, JSON_THROW_ON_ERROR); echo $c["require"]["php"] ?? "";')"
SEMVER_RE='^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z]+([.-][0-9A-Za-z]+)*)?(\+[0-9A-Za-z]+([.-][0-9A-Za-z]+)*)?$'

[[ -n "$HEADER_VERSION" && "$HEADER_VERSION" == "$CONSTANT_VERSION" ]] ||
  fail "plugin header Version and GFSMS_PLUGIN_VERSION disagree"
[[ "$HEADER_VERSION" =~ $SEMVER_RE ]] ||
  fail "plugin version is not valid SemVer: $HEADER_VERSION"
[[ "$REQUIRES_PHP" == '8.2' ]] ||
  fail "expected plugin Requires PHP: 8.2, found: $REQUIRES_PHP"
[[ "$REQUIRES_WP" == '7.0' ]] ||
  fail "expected plugin Requires at least: 7.0, found: $REQUIRES_WP"
[[ "$COMPOSER_PHP" == '>=8.2' ]] ||
  fail "expected Composer PHP requirement >=8.2, found: $COMPOSER_PHP"
grep -Fq 'Plugin Name: Gravity Notification Manager' "$PLUGIN_FILE" ||
  fail 'canonical Gravity Notification Manager plugin name is missing'
grep -Fq 'Text Domain: gravity-notification-manager' "$PLUGIN_FILE" ||
  fail 'canonical gravity-notification-manager text domain is missing'
grep -Fq 'Domain Path: /languages' "$PLUGIN_FILE" ||
  fail 'canonical plugin language path is missing'
test -s languages/gravity-notification-manager-fa_IR.po ||
  fail 'canonical Persian PO catalog is missing or empty'
test -s languages/gravity-notification-manager-fa_IR.mo ||
  fail 'canonical Persian MO catalog is missing or empty'

VERSION="$HEADER_VERSION"
if [[ -n "$EXPECTED_VERSION" && "$EXPECTED_VERSION" != "$VERSION" ]]; then
  fail "requested package version does not match plugin source: requested=$EXPECTED_VERSION source=$VERSION"
fi

ZIP_NAME="$PLUGIN_ROOT-$VERSION.zip"
CHECKSUM_NAME="$ZIP_NAME.sha256"
ARTIFACT_NAME="$PLUGIN_ROOT-$VERSION-installable"

rm -rf vendor
composer install \
  --no-dev \
  --prefer-dist \
  --optimize-autoloader \
  --no-interaction \
  --no-progress

test -f vendor/autoload.php || fail 'production Composer autoloader is missing'
test ! -e vendor/bin/phpunit || fail 'development phpunit binary survived production Composer install'
test ! -d vendor/phpunit || fail 'development phpunit package survived production Composer install'
test ! -d vendor/phpstan || fail 'development phpstan package survived production Composer install'
test ! -d vendor/squizlabs || fail 'development phpcs package survived production Composer install'
test ! -d vendor/wp-coding-standards || fail 'development WPCS package survived production Composer install'

rm -rf dist
mkdir -p "$STAGE"

cp gravityflow-sms-ippanel.php uninstall.php "$STAGE/"
cp -a src includes languages vendor "$STAGE/"
mkdir -p "$STAGE/assets"
cp -a assets/admin "$STAGE/assets/admin"

# These repository-era files are not part of the Composer-autoloaded production package.
rm -f "$STAGE/includes/Autoloader.php" "$STAGE/includes/autoloader_mapping.txt"

ENTRYPOINT="$STAGE/gravityflow-sms-ippanel.php"

for required in \
  "$ENTRYPOINT" \
  "$STAGE/uninstall.php" \
  "$STAGE/vendor/autoload.php" \
  "$STAGE/src/Migration/ProductionRuntime.php" \
  "$STAGE/src/Admin/AdminController.php" \
  "$STAGE/src/Support/NoSendGuard.php" \
  "$STAGE/assets/admin/gnm-admin.css" \
  "$STAGE/languages/gravity-notification-manager-fa_IR.po" \
  "$STAGE/languages/gravity-notification-manager-fa_IR.mo"; do
  test -f "$required" || fail "required staged production file is missing: $required"
done

test -d "$STAGE/languages" || fail 'staged languages directory is missing'
test -d "$STAGE/includes/Lifecycle" || fail 'staged lifecycle directory is missing'
grep -Fq 'ProductionRuntime::boot();' "$ENTRYPOINT" ||
  fail 'production entrypoint does not boot ProductionRuntime'
test ! -e "$STAGE/includes/Core/Bootstrap.php" ||
  fail 'retired includes/Core/Bootstrap.php is present in the staged package'

if grep -n -E 'GFSMS.*Core.*Bootstrap' "$ENTRYPOINT"; then
  fail 'retired GFSMS\Core\Bootstrap is referenced by the packaged entrypoint'
fi
if grep -R -n -E --include='*.php' 'GFSMS.*Core.*Bootstrap' "$STAGE"; then
  fail 'retired GFSMS\Core\Bootstrap is referenced by the staged package'
fi

php -r '
  $root = $argv[1];
  require $root . "/vendor/autoload.php";
  foreach ([
      "GravityNotify\\Migration\\ProductionRuntime",
      "GravityNotify\\Admin\\AdminController",
      "GravityNotify\\Support\\NoSendGuard",
  ] as $class) {
      if (!class_exists($class, true)) {
          fwrite(STDERR, "Packaged Composer autoloader cannot resolve {$class}.\n");
          exit(1);
      }
  }
' "$STAGE"

find "$STAGE" -exec touch -d "@$SOURCE_DATE_EPOCH" {} +

FILE_LIST="$(mktemp)"
LISTING="$(mktemp)"
EXTRACTED="$(mktemp -d)"
cleanup() {
  rm -f "$FILE_LIST" "$LISTING"
  rm -rf "$EXTRACTED"
}
trap cleanup EXIT

(
  cd dist
  find "$PLUGIN_ROOT" -type f -print | LC_ALL=C sort > "$FILE_LIST"
  zip -X -q "$ZIP_NAME" -@ < "$FILE_LIST"
)

ZIP_PATH="dist/$ZIP_NAME"
CHECKSUM_PATH="dist/$CHECKSUM_NAME"
test -s "$ZIP_PATH" || fail "installable ZIP was not created: $ZIP_PATH"

unzip -tq "$ZIP_PATH"
unzip -Z1 "$ZIP_PATH" > "$LISTING"

mapfile -t TOP_LEVELS < <(cut -d/ -f1 "$LISTING" | sed '/^$/d' | LC_ALL=C sort -u)
if (( ${#TOP_LEVELS[@]} != 1 )) || [[ "${TOP_LEVELS[0]}" != "$PLUGIN_ROOT" ]]; then
  fail "ZIP must contain exactly one top-level directory named $PLUGIN_ROOT"
fi

REQUIRED_ZIP_PATHS=(
  "$PLUGIN_ROOT/gravityflow-sms-ippanel.php"
  "$PLUGIN_ROOT/uninstall.php"
  "$PLUGIN_ROOT/vendor/autoload.php"
  "$PLUGIN_ROOT/src/Migration/ProductionRuntime.php"
  "$PLUGIN_ROOT/src/Admin/AdminController.php"
  "$PLUGIN_ROOT/src/Support/NoSendGuard.php"
  "$PLUGIN_ROOT/assets/admin/gnm-admin.css"
  "$PLUGIN_ROOT/languages/gravity-notification-manager-fa_IR.po"
  "$PLUGIN_ROOT/languages/gravity-notification-manager-fa_IR.mo"
)
for required in "${REQUIRED_ZIP_PATHS[@]}"; do
  grep -Fxq "$required" "$LISTING" || fail "required ZIP entry missing: $required"
done

FORBIDDEN_PREFIXES=(
  "$PLUGIN_ROOT/.git/"
  "$PLUGIN_ROOT/.github/"
  "$PLUGIN_ROOT/tests/"
  "$PLUGIN_ROOT/tools/"
  "$PLUGIN_ROOT/docs/"
  "$PLUGIN_ROOT/node_modules/"
  "$PLUGIN_ROOT/coverage/"
  "$PLUGIN_ROOT/.wp-env.runtime/"
)
for forbidden in "${FORBIDDEN_PREFIXES[@]}"; do
  if grep -Fq "$forbidden" "$LISTING"; then
    fail "forbidden development path present in ZIP: $forbidden"
  fi
done

FORBIDDEN_FILES=(
  "$PLUGIN_ROOT/composer.json"
  "$PLUGIN_ROOT/composer.lock"
  "$PLUGIN_ROOT/package.json"
  "$PLUGIN_ROOT/package-lock.json"
  "$PLUGIN_ROOT/phpunit.xml.dist"
  "$PLUGIN_ROOT/phpstan.neon.dist"
  "$PLUGIN_ROOT/phpstan-stubs.php"
  "$PLUGIN_ROOT/phpcs.xml"
  "$PLUGIN_ROOT/phpcompat.xml"
  "$PLUGIN_ROOT/.wp-env.json"
  "$PLUGIN_ROOT/AGENTS.md"
  "$PLUGIN_ROOT/README.md"
  "$PLUGIN_ROOT/.gitignore"
  "$PLUGIN_ROOT/.gitattributes"
  "$PLUGIN_ROOT/includes/Autoloader.php"
  "$PLUGIN_ROOT/includes/autoloader_mapping.txt"
)
for forbidden in "${FORBIDDEN_FILES[@]}"; do
  if grep -Fxq "$forbidden" "$LISTING"; then
    fail "forbidden repository-only file present in ZIP: $forbidden"
  fi
done

unzip -q "$ZIP_PATH" -d "$EXTRACTED"
EXTRACTED_ROOT="$EXTRACTED/$PLUGIN_ROOT"

test -f "$EXTRACTED_ROOT/gravityflow-sms-ippanel.php" ||
  fail 'extracted package entrypoint is missing'
test -f "$EXTRACTED_ROOT/vendor/autoload.php" ||
  fail 'extracted package Composer autoloader is missing'
test -f "$EXTRACTED_ROOT/src/Migration/ProductionRuntime.php" ||
  fail 'extracted ProductionRuntime is missing'
test -s "$EXTRACTED_ROOT/languages/gravity-notification-manager-fa_IR.po" ||
  fail 'extracted canonical Persian PO catalog is missing or empty'
test -s "$EXTRACTED_ROOT/languages/gravity-notification-manager-fa_IR.mo" ||
  fail 'extracted canonical Persian MO catalog is missing or empty'
test ! -e "$EXTRACTED_ROOT/tests" ||
  fail 'tests directory is present in extracted production package'
test ! -e "$EXTRACTED_ROOT/.github" ||
  fail '.github directory is present in extracted production package'
test ! -e "$EXTRACTED_ROOT/includes/Core/Bootstrap.php" ||
  fail 'retired includes/Core/Bootstrap.php is present in extracted production package'

if grep -R -n -E --include='*.php' 'GFSMS.*Core.*Bootstrap' "$EXTRACTED_ROOT"; then
  fail 'retired GFSMS\Core\Bootstrap is referenced by the extracted package'
fi

php -r '
  $root = $argv[1];
  require $root . "/vendor/autoload.php";
  foreach ([
      "GravityNotify\\Migration\\ProductionRuntime",
      "GravityNotify\\Admin\\AdminController",
      "GravityNotify\\Support\\NoSendGuard",
  ] as $class) {
      if (!class_exists($class, true)) {
          fwrite(STDERR, "Extracted package autoloader cannot resolve {$class}.\n");
          exit(1);
      }
  }
' "$EXTRACTED_ROOT"

(
  cd dist
  sha256sum "$ZIP_NAME" > "$CHECKSUM_NAME"
  sha256sum -c "$CHECKSUM_NAME"
)

ZIP_SHA="$(sha256sum "$ZIP_PATH" | awk '{print $1}')"

emit_output() {
  local key="$1"
  local value="$2"
  if [[ -n "$OUTPUT_FILE" ]]; then
    printf '%s=%s\n' "$key" "$value" >> "$OUTPUT_FILE"
  fi
}

emit_output version "$VERSION"
emit_output zip_name "$ZIP_NAME"
emit_output checksum_name "$CHECKSUM_NAME"
emit_output artifact_name "$ARTIFACT_NAME"
emit_output zip_sha "$ZIP_SHA"
emit_output source_head "$SOURCE_HEAD"
emit_output stage "$STAGE"

printf 'PRODUCTION_PACKAGE_BUILD=PASS\n'
printf 'SOURCE_HEAD=%s\n' "$SOURCE_HEAD"
printf 'PLUGIN_VERSION=%s\n' "$VERSION"
printf 'ZIP_NAME=%s\n' "$ZIP_NAME"
printf 'ZIP_SHA256=%s\n' "$ZIP_SHA"