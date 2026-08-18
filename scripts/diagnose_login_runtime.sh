#!/bin/sh
set -eu

BASE_URL=${1:-${APP_URL:-http://127.0.0.1}}
BASE_URL=${BASE_URL%/}

echo '=== Happy Family login/runtime diagnosis ==='
echo "URL: $BASE_URL"
echo

echo '--- Compose services ---'
docker compose ps || true
echo

echo '--- Restart/health state ---'
for service in web worker scheduler db redis; do
  cid=$(docker compose ps -q "$service" 2>/dev/null || true)
  if [ -z "$cid" ]; then
    echo "$service: no container"
    continue
  fi
  docker inspect "$cid" --format "$service status={{.State.Status}} health={{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}} exit={{.State.ExitCode}} oom={{.State.OOMKilled}} restart={{.HostConfig.RestartPolicy.Name}} restarts={{.RestartCount}}" || true
done
echo

echo '--- HTTP diagnostics ---'
for path in /up /health/live /health/ready /login; do
  code=$(curl -k -sS -o "/tmp/hf-diagnose-$(echo "$path" | tr '/' '_').out" -w '%{http_code}' --connect-timeout 5 --max-time 12 "$BASE_URL$path" 2>/tmp/hf-diagnose-curl.err || true)
  echo "$path -> ${code:-curl-failed}"
  if [ "${code:-000}" = 000 ]; then cat /tmp/hf-diagnose-curl.err 2>/dev/null || true; fi
done
echo

echo '--- Authentication preflight inside web ---'
docker compose exec -T web php artisan happy-family:auth-preflight --no-interaction || true
echo

echo '--- Session/cache tables ---'
docker compose exec -T web php -r '
require "vendor/autoload.php";
$app=require "bootstrap/app.php";
$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
foreach (["sessions","cache","cache_locks"] as $t) {
    echo $t.": ".(Illuminate\\Support\\Facades\\Schema::hasTable($t)?"present":"MISSING").PHP_EOL;
}
echo "session_driver=".config("session.driver").PHP_EOL;
echo "cache_store=".config("cache.default").PHP_EOL;
' || true
echo

echo '--- Recent authentication/server errors ---'
docker compose logs --no-color --tail=350 web 2>&1 | grep -Ei 'request_id|login|authentication|session|csrf|exception|error|critical|sqlstate|permission denied|failed' | tail -160 || true
echo
echo '=== End diagnosis ==='
