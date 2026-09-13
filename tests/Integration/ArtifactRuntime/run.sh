#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
RUNTIME_DIR="$ROOT/.wp-env.artifact-runtime"
CONFIG_FILE="$RUNTIME_DIR/wp-env.artifact.json"
EVIDENCE_FILE="${ARTIFACT_SMOKE_EVIDENCE:-$RUNTIME_DIR/evidence.txt}"
PLUGIN_SLUG='gravity-notification-manager'
PLUGIN_ENTRYPOINT="$PLUGIN_SLUG/gravityflow-sms-ippanel.php"

: "${GNM_PLUGIN_ZIP:?GNM_PLUGIN_ZIP is required}"
: "${GNM_PLUGIN_SHA256:?GNM_PLUGIN_SHA256 is required}"
: "${GNM_REPOSITORY_HEAD:?GNM_REPOSITORY_HEAD is required}"

GNM_WP_VERSION="${GNM_WP_VERSION:-7.0}"
GNM_PHP_VERSION="${GNM_PHP_VERSION:-8.2}"

if [[ ! "$GNM_REPOSITORY_HEAD" =~ ^[0-9a-f]{40}$ ]]; then
  echo "GNM_REPOSITORY_HEAD must be an exact 40-character commit SHA." >&2
  exit 1
fi

for command in docker node npm unzip sha256sum realpath; do
  command -v "$command" >/dev/null 2>&1 || {
    echo "Required command is unavailable: $command" >&2
    exit 1
  }
done

docker info >/dev/null 2>&1 || {
  echo 'Docker is required and must be running for the artifact smoke test.' >&2
  exit 1
}

ZIP_PATH="$(realpath "$GNM_PLUGIN_ZIP")"
ZIP_NAME="$(basename "$ZIP_PATH")"
ZIP_DIR="$(dirname "$ZIP_PATH")"
EXPECTED_SHA="${GNM_PLUGIN_SHA256,,}"
ACTUAL_SHA="$(sha256sum "$ZIP_PATH" | awk '{print $1}')"

if [[ ! "$EXPECTED_SHA" =~ ^[0-9a-f]{64}$ ]]; then
  echo 'GNM_PLUGIN_SHA256 must be a SHA-256 hex digest.' >&2
  exit 1
fi

if [[ "$ACTUAL_SHA" != "$EXPECTED_SHA" ]]; then
  echo "Artifact checksum mismatch: expected=$EXPECTED_SHA actual=$ACTUAL_SHA" >&2
  exit 1
fi

unzip -tq "$ZIP_PATH" >/dev/null
mapfile -t ZIP_ENTRIES < <(unzip -Z1 "$ZIP_PATH")
mapfile -t TOP_LEVELS < <(printf '%s\n' "${ZIP_ENTRIES[@]}" | cut -d/ -f1 | sed '/^$/d' | LC_ALL=C sort -u)

if (( ${#TOP_LEVELS[@]} != 1 )) || [[ "${TOP_LEVELS[0]}" != "$PLUGIN_SLUG" ]]; then
  echo "Artifact must contain exactly one top-level directory named $PLUGIN_SLUG." >&2
  exit 1
fi

for entry in "${ZIP_ENTRIES[@]}"; do
  if [[ "$entry" == /* || "$entry" == *'/../'* || "$entry" == '../'* || "$entry" == *'/..' ]]; then
    echo "Unsafe ZIP path detected: $entry" >&2
    exit 1
  fi
done

for required in \
  "$PLUGIN_ENTRYPOINT" \
  "$PLUGIN_SLUG/vendor/autoload.php" \
  "$PLUGIN_SLUG/src/Migration/ProductionRuntime.php" \
  "$PLUGIN_SLUG/src/Admin/AdminController.php" \
  "$PLUGIN_SLUG/src/Support/NoSendGuard.php"; do
  printf '%s\n' "${ZIP_ENTRIES[@]}" | grep -Fxq "$required" || {
    echo "Required packaged runtime file is missing: $required" >&2
    exit 1
  }
done

rm -rf "$RUNTIME_DIR"
mkdir -p "$RUNTIME_DIR" "$(dirname "$EVIDENCE_FILE")"

php -r '
  $path = $argv[1];
  $zipDir = $argv[2];
  $wpVersion = $argv[3];
  $phpVersion = $argv[4];
  $config = [
    "core" => "WordPress/WordPress#" . $wpVersion,
    "phpVersion" => $phpVersion,
    "autoPort" => true,
    "config" => [
      "GRAVITY_NOTIFY_TEST_NO_SEND" => true,
      "WP_HTTP_BLOCK_EXTERNAL" => true,
    ],
    "mappings" => [
      "wp-content/artifacts" => $zipDir,
    ],
  ];
  file_put_contents($path, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
' "$CONFIG_FILE" "$ZIP_DIR" "$GNM_WP_VERSION" "$GNM_PHP_VERSION"

export WP_ENV_HOME="$RUNTIME_DIR/wp-env-home"

wp_env() {
  npx --no-install wp-env "$@" --config="$CONFIG_FILE"
}

cleanup() {
  wp_env stop >/dev/null 2>&1 || true
}
trap cleanup EXIT

wp_env start --update

WP_VERSION_OBSERVED="$(wp_env run cli wp core version | grep -Eo '[0-9]+\.[0-9]+(\.[0-9]+)?' | head -n 1)"
PHP_VERSION_OBSERVED="$(wp_env run cli php -r 'echo PHP_MAJOR_VERSION, ".", PHP_MINOR_VERSION, ".", PHP_RELEASE_VERSION;' | grep -Eo '[0-9]+\.[0-9]+\.[0-9]+' | head -n 1)"

if [[ "$WP_VERSION_OBSERVED" != "$GNM_WP_VERSION" && "$WP_VERSION_OBSERVED" != "$GNM_WP_VERSION".* ]]; then
  echo "Unexpected WordPress runtime: expected=$GNM_WP_VERSION observed=$WP_VERSION_OBSERVED" >&2
  exit 1
fi

if [[ "$PHP_VERSION_OBSERVED" != "$GNM_PHP_VERSION".* ]]; then
  echo "Unexpected PHP runtime: expected=$GNM_PHP_VERSION.x observed=$PHP_VERSION_OBSERVED" >&2
  exit 1
fi

if wp_env run cli wp plugin is-installed "$PLUGIN_SLUG" >/dev/null 2>&1; then
  echo 'Artifact smoke environment already contains the plugin before ZIP installation; refusing source-tree substitution.' >&2
  exit 1
fi

wp_env run cli sha256sum "/var/www/html/wp-content/artifacts/$ZIP_NAME" | grep -Fq "$EXPECTED_SHA" || {
  echo 'Mapped ZIP checksum inside WordPress does not match the bound artifact checksum.' >&2
  exit 1
}

wp_env run cli wp plugin install "/var/www/html/wp-content/artifacts/$ZIP_NAME" --activate
wp_env run cli wp plugin is-active "$PLUGIN_SLUG"

wp_env run cli wp eval '
  $root = WP_PLUGIN_DIR . "/gravity-notification-manager";
  $entrypoint = $root . "/gravityflow-sms-ippanel.php";

  if (!is_file($entrypoint) || !is_file($root . "/vendor/autoload.php")) {
      fwrite(STDERR, "Installed production plugin files are incomplete.\n");
      exit(1);
  }

  if (!defined("GFSMS_PLUGIN_VERSION") || GFSMS_PLUGIN_VERSION !== "3.2.0") {
      fwrite(STDERR, "Installed plugin entrypoint did not boot the expected version.\n");
      exit(1);
  }

  foreach ([
      "GravityNotify\\Migration\\ProductionRuntime",
      "GravityNotify\\Admin\\AdminController",
      "GravityNotify\\Support\\NoSendGuard",
  ] as $class) {
      if (!class_exists($class, true)) {
          fwrite(STDERR, "Installed production autoloader cannot resolve {$class}.\n");
          exit(1);
      }
  }

  if (!defined("GRAVITY_NOTIFY_TEST_NO_SEND") || GRAVITY_NOTIFY_TEST_NO_SEND !== true) {
      fwrite(STDERR, "No-send test guard constant is not active.\n");
      exit(1);
  }

  if (!defined("WP_HTTP_BLOCK_EXTERNAL") || WP_HTTP_BLOCK_EXTERNAL !== true) {
      fwrite(STDERR, "WordPress external HTTP blocking is not active.\n");
      exit(1);
  }

  if (!\GravityNotify\Support\NoSendGuard::is_enabled()) {
      fwrite(STDERR, "Packaged no-send guard is not enabled.\n");
      exit(1);
  }

  try {
      \GravityNotify\Support\NoSendGuard::assert_outbound_allowed("artifact-install-smoke");
      fwrite(STDERR, "No-send guard unexpectedly allowed an external-provider operation.\n");
      exit(1);
  } catch (\RuntimeException $expected) {
      // Expected fail-closed behavior: no provider send may escape this smoke test.
  }

  echo "PACKAGED_RUNTIME_BOOT=PASS\n";
  echo "NO_SEND_GUARD=PASS\n";
'

cat > "$EVIDENCE_FILE" <<EOF
ARTIFACT_INSTALL_SMOKE=PASS
REPOSITORY_HEAD=$GNM_REPOSITORY_HEAD
ZIP_NAME=$ZIP_NAME
ZIP_SHA256=$ACTUAL_SHA
WORDPRESS_VERSION=$WP_VERSION_OBSERVED
PHP_VERSION=$PHP_VERSION_OBSERVED
PLUGIN_SLUG=$PLUGIN_SLUG
ZIP_INSTALL=PASS
PLUGIN_ACTIVATION=PASS
PACKAGED_AUTOLOADER_RUNTIME_BOOT=PASS
EXTERNAL_PROVIDER_SEND_GUARD=PASS
EOF

cat "$EVIDENCE_FILE"
