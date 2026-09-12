#!/usr/bin/env bash
set -Eeuo pipefail

retired_paths=(
  includes/Core/Bootstrap.php
  includes/Plugin.php
  includes/Integration
  includes/Domain
  includes/Infrastructure
  includes/Services
  includes/Admin/DoctorPage.php
  includes/Admin/Logs_Table.php
  includes/Admin/Settings_Fields.php
  includes/Admin/Settings_Page.php
)

for path in "${retired_paths[@]}"; do
  if [[ -e "$path" ]]; then
    echo "WU09_RETIRE_PATH=FAIL path=$path" >&2
    exit 1
  fi
  echo "WU09_RETIRE_PATH=PASS path=$path"
done

assert_zero() {
  local id="$1"
  local pattern="$2"
  shift 2
  local output
  if output="$(git grep -nE "$pattern" -- "$@" 2>/dev/null)"; then
    echo "WU09_ZERO_CALLSITE=FAIL target=$id" >&2
    printf '%s\n' "$output" >&2
    exit 1
  fi
  echo "WU09_ZERO_CALLSITE=PASS target=$id matches=0"
}

# Production entrypoint/root boot paths. Historical migration/test code is not a sender root.
assert_zero legacy_boot 'LegacyRuntimeGuard::boot|MigrationAdminController::boot|GFSMS\\Core\\Bootstrap::init|GFSMS\\Plugin' gravityflow-sms-ippanel.php

# Retired notification execution primitives across executable production surfaces.
assert_zero event_queue 'GFSMS\\Queue\\Event_Queue|gfsms_process_payload|gfsms_retry_payload' gravityflow-sms-ippanel.php src ':!src/Migration/LegacyRuntimeGuard.php' includes
assert_zero flow_dispatch 'GFSMS\\Integration\\(Listener|Dispatcher)' gravityflow-sms-ippanel.php src ':!src/Migration/LegacyRuntimeGuard.php' includes
assert_zero direct_gf_sender 'GFSMS\\Integration\\GravityForms_Handler' gravityflow-sms-ippanel.php src ':!src/Migration/LegacyRuntimeGuard.php' includes
assert_zero legacy_sms_sender 'GFSMS\\Integration\\Sms_Sender' gravityflow-sms-ippanel.php src ':!src/Migration/LegacyRuntimeGuard.php' includes
assert_zero legacy_provider_factory 'GFSMS\\Infrastructure\\ProviderFactory|GFSMS\\Integration\\(IPPanel_Provider|Secondary_Provider)' gravityflow-sms-ippanel.php src ':!src/Migration/LegacyRuntimeGuard.php' includes
assert_zero legacy_delivery_locks 'GFSMS\\Services\\LockManager' gravityflow-sms-ippanel.php src ':!src/Migration/LegacyRuntimeGuard.php' includes
assert_zero background_notification_execution 'as_enqueue_async_action|as_schedule_single_action|wp_schedule_single_event' gravityflow-sms-ippanel.php src includes

echo 'WU09_RETIREMENT_AUDIT=PASS'
