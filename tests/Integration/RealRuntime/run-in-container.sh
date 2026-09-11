#!/usr/bin/env bash
set -Eeuo pipefail

readonly SIMULATOR_HOST='127.0.0.1'
readonly SIMULATOR_PORT='8765'
export GNM_IPPANEL_SIMULATOR_TOKEN='dummy-ippanel-token'
export GNM_IPPANEL_SIMULATOR_FROM='+989000000000'
export GNM_IPPANEL_SIMULATOR_TO='+989111111111'
export GNM_IPPANEL_SIMULATOR_MESSAGE='GNM deterministic simulator contract'
export GNM_IPPANEL_SIMULATOR_STATE='/tmp/gnm-ippanel-simulator-state.json'
export GNM_IPPANEL_SIMULATOR_SEND_ENDPOINT="http://${SIMULATOR_HOST}:${SIMULATOR_PORT}/v1/api/send"
export GNM_IPPANEL_SIMULATOR_REPORT_ENDPOINT="http://${SIMULATOR_HOST}:${SIMULATOR_PORT}/v1/api/report/recipients"
rm -f "$GNM_IPPANEL_SIMULATOR_STATE"

php -S "${SIMULATOR_HOST}:${SIMULATOR_PORT}" tests/Integration/RealRuntime/ippanel-simulator.php >/tmp/gnm-ippanel-simulator.log 2>&1 &
simulator_pid=$!
cleanup() {
	kill "$simulator_pid" >/dev/null 2>&1 || true
	wait "$simulator_pid" >/dev/null 2>&1 || true
}
trap cleanup EXIT

ready=0
for _attempt in $(seq 1 50); do
	if php -r '$body=@file_get_contents("http://127.0.0.1:8765/_health"); exit(is_string($body) && str_contains($body, "ready") ? 0 : 1);'; then
		ready=1
		break
	fi
	sleep 0.1
done
if [[ "$ready" != '1' ]]; then
	cat /tmp/gnm-ippanel-simulator.log >&2 || true
	echo 'GNM_REAL_INTEGRATION_STATE=SIMULATOR_INFRASTRUCTURE_FAILURE' >&2
	exit 6
fi

echo 'GNM_IPPANEL_SIMULATOR_SOCKET=READY address=127.0.0.1:8765'
php vendor/bin/phpunit --configuration tests/Integration/RealRuntime/phpunit.xml.dist \
	--log-junit .wp-env.runtime/real-runtime-junit.xml
