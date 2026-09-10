#!/usr/bin/env bash
set -Eeuo pipefail

readonly ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
readonly RUNTIME_DIR="${ROOT}/.wp-env.runtime"
readonly OVERRIDE_FILE="${ROOT}/.wp-env.override.json"
readonly POLYFILLS_COMMIT="134921bfca9b02d8f374c48381451da1d98402f9"

state() { printf 'GNM_LIVE_VALIDATION_STATE=%s\n' "$1"; }
fail() { state "$1"; printf '%s\n' "$2" >&2; exit "${3:-1}"; }
need() { command -v "$1" >/dev/null 2>&1 || fail TOOLING_OR_CI_FAILURE "Missing prerequisite: $1"; }

cleanup() {
	if [[ -f "$OVERRIDE_FILE" ]]; then
		(cd "$ROOT" && npx wp-env stop >/dev/null 2>&1) || true
	fi
	rm -f "$OVERRIDE_FILE"
	rm -rf "$RUNTIME_DIR"
}
trap cleanup EXIT

need docker
need git
need npm
need php
need sha256sum
need unzip

for required_input in GNM_GF_ZIP GNM_GF_SHA256 GNM_GFLOW_ZIP GNM_GFLOW_SHA256 EXPECTED_HEAD GNM_LIVE_IPPANEL_API_KEY GNM_LIVE_SMS_FROM GNM_LIVE_SMS_TO; do
	[[ -n "${!required_input:-}" ]] || fail CONFIGURATION_FAILURE "Missing required live validation input: $required_input"
done
[[ "$EXPECTED_HEAD" =~ ^[0-9a-fA-F]{40}$ ]] || fail TOOLING_OR_CI_FAILURE 'Expected Head is not a full commit SHA.'
[[ "$GNM_LIVE_SMS_FROM" =~ ^\+[1-9][0-9]{1,14}$ ]] || fail CONFIGURATION_FAILURE 'Configured live sender is not E.164.'
[[ "$GNM_LIVE_SMS_TO" =~ ^\+[1-9][0-9]{1,14}$ ]] || fail CONFIGURATION_FAILURE 'Configured live recipient is not E.164.'

rm -rf "$RUNTIME_DIR"
mkdir -p "$RUNTIME_DIR/vendor"
export WP_ENV_HOME="${RUNTIME_DIR}/wp-env-home"

polyfills_dir="${RUNTIME_DIR}/phpunit-polyfills"
git init -q "$polyfills_dir"
git -C "$polyfills_dir" remote add origin https://github.com/Yoast/PHPUnit-Polyfills.git
git -C "$polyfills_dir" fetch --quiet --depth=1 origin "$POLYFILLS_COMMIT"
git -C "$polyfills_dir" checkout --quiet --detach FETCH_HEAD
[[ "$(git -C "$polyfills_dir" rev-parse HEAD)" == "$POLYFILLS_COMMIT" ]] || fail TOOLING_OR_CI_FAILURE 'PHPUnit Polyfills checkout did not match the pinned commit.'
rm -rf "$polyfills_dir/.git"

admit_package() {
	local label="$1" zip_path="$2" expected="$3" slug="$4" destination="$5"
	[[ -r "$zip_path" ]] || fail TOOLING_OR_CI_FAILURE "$label package is missing or unreadable."
	local actual
	actual="$(sha256sum "$zip_path" | cut -d' ' -f1)"
	[[ "${actual,,}" == "${expected,,}" ]] || fail TOOLING_OR_CI_FAILURE "$label package SHA-256 mismatch."
	unzip -q "$zip_path" -d "$destination"
	[[ -d "$destination/$slug" ]] || fail TOOLING_OR_CI_FAILURE "$label package did not expose the expected plugin directory."
}

admit_package 'Gravity Forms' "$GNM_GF_ZIP" "$GNM_GF_SHA256" gravityforms "$RUNTIME_DIR/vendor/gf"
admit_package 'Gravity Flow' "$GNM_GFLOW_ZIP" "$GNM_GFLOW_SHA256" gravityflow "$RUNTIME_DIR/vendor/gflow"

php -r '
$config = ["plugins" => [$argv[1], $argv[2]]];
$json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if ($json === false || file_put_contents($argv[3], $json . PHP_EOL) === false) { exit(1); }
' "$RUNTIME_DIR/vendor/gf/gravityforms" "$RUNTIME_DIR/vendor/gflow/gravityflow" "$OVERRIDE_FILE"

export GNM_LIVE_CONFIG_PATH="$RUNTIME_DIR/live-config.json"
php -r '
$data = [
    "api_key" => getenv("GNM_LIVE_IPPANEL_API_KEY"),
    "from" => getenv("GNM_LIVE_SMS_FROM"),
    "to" => getenv("GNM_LIVE_SMS_TO"),
    "run_id" => getenv("GITHUB_RUN_ID"),
    "head" => getenv("EXPECTED_HEAD"),
];
$path = getenv("GNM_LIVE_CONFIG_PATH");
$json = json_encode($data, JSON_UNESCAPED_SLASHES);
if (!is_string($path) || $path === "" || $json === false || file_put_contents($path, $json) === false) { exit(1); }
chmod($path, 0600);
'

cd "$ROOT"
state READY
npx wp-env start --update
set +e
npx wp-env run tests-cli --env-cwd=wp-content/plugins/gravity-notification-manager-source \
	php vendor/bin/phpunit --configuration tests/Integration/LiveRuntime/phpunit.xml.dist
phpunit_status=$?
set -e

if [[ "$phpunit_status" -ne 0 ]]; then
	state LIVE_VALIDATION_FAILED
	exit "$phpunit_status"
fi
state LIVE_IPPANEL_DELIVERED
