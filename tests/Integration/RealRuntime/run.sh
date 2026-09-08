#!/usr/bin/env bash
set -Eeuo pipefail

readonly RESULT_UNAVAILABLE=78
readonly ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
readonly RUNTIME_DIR="${ROOT}/.wp-env.runtime"
readonly OVERRIDE_FILE="${ROOT}/.wp-env.override.json"

state() { printf 'GNM_REAL_INTEGRATION_STATE=%s\n' "$1"; }
fail() { state "$1"; printf '%s\n' "$2" >&2; exit "${3:-1}"; }
need() { command -v "$1" >/dev/null 2>&1 || fail NOT_EXECUTED_ENVIRONMENT_UNAVAILABLE "Missing prerequisite: $1" "$RESULT_UNAVAILABLE"; }

cleanup() {
	if [[ "${GNM_KEEP_WP_ENV:-0}" != "1" && -f "$OVERRIDE_FILE" ]]; then
		(cd "$ROOT" && npx wp-env stop >/dev/null 2>&1) || true
	fi
}
trap cleanup EXIT

need docker
need npm
need php
need sha256sum
need unzip

for required_input in GNM_GF_ZIP GNM_GF_SHA256 GNM_GFLOW_ZIP GNM_GFLOW_SHA256; do
	[[ -n "${!required_input:-}" ]] || fail NOT_EXECUTED_ENVIRONMENT_UNAVAILABLE "Missing required input: $required_input" "$RESULT_UNAVAILABLE"
done

source_class="${GNM_PACKAGE_SOURCE_CLASS:-OWNER_LOCAL_PACKAGE}"
case "$source_class" in
	OWNER_LOCAL_PACKAGE|OWNER_AUTHORIZED_SECURE_SOURCE|OWNER_AUTHORIZED_PUBLIC_SOURCE) ;;
	*) fail PACKAGE_SOURCE_UNAUTHORIZED 'Package source class is not owner-authorized.' ;;
esac

rm -rf "$RUNTIME_DIR"
mkdir -p "$RUNTIME_DIR/vendor"
export WP_ENV_HOME="${RUNTIME_DIR}/wp-env-home"

admit_package() {
	local label="$1" zip_path="$2" expected="$3" slug="$4" destination="$5"
	[[ -r "$zip_path" ]] || fail NOT_EXECUTED_ENVIRONMENT_UNAVAILABLE "$label package is missing or unreadable." "$RESULT_UNAVAILABLE"
	[[ "$expected" =~ ^[[:xdigit:]]{64}$ ]] || fail PACKAGE_INVALID "$label expected SHA-256 is malformed."
	local actual
	actual="$(sha256sum "$zip_path" | cut -d' ' -f1)"
	[[ "${actual,,}" == "${expected,,}" ]] || fail PACKAGE_HASH_MISMATCH "$label package SHA-256 does not match owner input."
	if unzip -Z1 "$zip_path" | awk '/(^\/|(^|\/)\.\.($|\/))/{bad=1} END{exit bad?0:1}'; then
		fail PACKAGE_INVALID "$label ZIP contains an unsafe path."
	fi
	unzip -q "$zip_path" -d "$destination"
	local plugin_dir header version
	plugin_dir="$(find "$destination" -mindepth 1 -maxdepth 1 -type d -name "$slug" -print -quit)"
	[[ -n "$plugin_dir" ]] || fail PACKAGE_INVALID "$label ZIP does not contain the expected $slug plugin directory."
	header="$(find "$plugin_dir" -maxdepth 1 -type f -name '*.php' -exec awk '/^[[:space:]]*(\*[[:space:]]*)?Plugin Name:/{found=1} END{exit found?0:1}' {} \; -print -quit)"
	[[ -n "$header" ]] || fail PACKAGE_INVALID "$label plugin header was not detected."
	version="$(awk -F: '/^[[:space:]]*(\*[[:space:]]*)?Version:/{sub(/^[[:space:]]+/,"",$2); print $2; exit}' "$header")"
	[[ -n "$version" ]] || fail PACKAGE_INVALID "$label plugin version was not detected."
	printf 'PACKAGE label=%s filename=%s slug=%s version=%s sha256=%s source=%s\n' "$label" "$(basename "$zip_path")" "$slug" "$version" "$actual" "$source_class"
}

admit_package 'Gravity Forms' "$GNM_GF_ZIP" "$GNM_GF_SHA256" gravityforms "$RUNTIME_DIR/vendor/gf"
admit_package 'Gravity Flow' "$GNM_GFLOW_ZIP" "$GNM_GFLOW_SHA256" gravityflow "$RUNTIME_DIR/vendor/gflow"

php -r '
$config = ["plugins" => [$argv[1], $argv[2]]];
$json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if ($json === false || file_put_contents($argv[3], $json . PHP_EOL) === false) { exit(1); }
' "$RUNTIME_DIR/vendor/gf/gravityforms" "$RUNTIME_DIR/vendor/gflow/gravityflow" "$OVERRIDE_FILE"

cd "$ROOT"
state READY
npx wp-env start --update
set +e
npx wp-env run tests-cli --env-cwd=wp-content/plugins/gravity-notification-manager-source \
	php vendor/bin/phpunit --configuration tests/Integration/RealRuntime/phpunit.xml.dist \
	--log-junit .wp-env.runtime/real-runtime-junit.xml
phpunit_status=$?
set -e

set +e
php tests/Integration/RealRuntime/validate-junit.php "$RUNTIME_DIR/real-runtime-junit.xml"
manifest_status=$?
set -e

if [[ "$manifest_status" -ne 0 ]]; then
	exit "$manifest_status"
fi
if [[ "$phpunit_status" -ne 0 ]]; then
	state HARNESS_FAILURE
	exit "$phpunit_status"
fi
state REAL_INTEGRATION_PASS
