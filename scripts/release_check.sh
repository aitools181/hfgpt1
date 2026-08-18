#!/bin/sh
set -eu

cd "$(dirname "$0")/.."

echo "[1/13] PHP syntax"
find app bootstrap config database routes tests scripts -type f -name '*.php' -print0 | xargs -0 -n1 php -l >/tmp/happy-family-php-lint.txt

echo "[2/13] Static source integrity"
python3 scripts/static_integrity_check.py

echo "[3/13] Authentication runtime invariants"
scripts/test_auth_runtime_config.sh

echo "[4/13] Runtime supervisor behavior"
scripts/test_web_watchdog.sh

echo "[5/13] Runtime healthcheck + bootstrap behavior"
scripts/test_runtime_healthchecks.sh
scripts/test_bootstrap_validation.sh

echo "[6/13] Background worker/scheduler self-healing"
scripts/test_background_supervisor.sh

echo "[7/13] 100k-row import streaming / safety"
php scripts/test_tabular_streaming.php

echo "[8/13] Composer and NPM manifests"
php -r '$j=json_decode(file_get_contents("composer.json"), true, 512, JSON_THROW_ON_ERROR); if (!isset($j["require"]["laravel/framework"])) exit(1);'
node -e 'JSON.parse(require("fs").readFileSync("package.json","utf8"))'

echo "[9/13] Docker Compose YAML + failure-prevention invariants"
python3 - <<'PY'
import re
import yaml
with open('docker-compose.yml','r',encoding='utf-8') as f:
    data=yaml.safe_load(f)
assert isinstance(data,dict) and 'services' in data
services=data['services']
assert {'web','worker','scheduler','db','redis'} <= set(services)
assert 'app' not in services
assert str(services['web'].get('mem_limit','')).endswith('1280m}'), 'web default memory cap changed unexpectedly'
assert str(services['worker'].get('mem_limit','')).endswith('448m}'), 'worker default memory cap changed unexpectedly'
assert str(services['scheduler'].get('mem_limit','')).endswith('192m}'), 'scheduler default memory cap changed unexpectedly'
assert str(services['db'].get('mem_limit','')).endswith('768m}'), 'db default memory cap changed unexpectedly'
assert str(services['redis'].get('mem_limit','')).endswith('256m}'), 'redis default memory cap changed unexpectedly'
for service in ('web','worker','scheduler','db','redis'):
    cfg=services[service]
    assert 'healthcheck' in cfg, f'{service} healthcheck missing'
    assert cfg.get('restart') == 'always', f'{service} restart: always missing'
    assert cfg.get('mem_limit'), f'{service} memory cap missing'
    assert cfg.get('pids_limit'), f'{service} PID cap missing'
    nofile=(cfg.get('ulimits') or {}).get('nofile') or {}
    assert int(nofile.get('soft',0)) >= 65535 and int(nofile.get('hard',0)) >= 65535, f'{service} nofile ulimit missing/too low'
    logging=cfg.get('logging',{})
    assert logging.get('driver') == 'json-file', f'{service} log driver missing'
    assert logging.get('options',{}).get('max-size'), f'{service} log rotation missing'
    assert logging.get('options',{}).get('max-file'), f'{service} log file cap missing'

web=services['web']
web_test=' '.join(map(str,web['healthcheck']['test']))
assert 'happy-family-healthcheck' in web_test and 'web' in web_test
web_env=web.get('environment',{})
for key in (
    'WEB_WATCHDOG_ENABLED','WEB_WATCHDOG_URL','WEB_WATCHDOG_INTERVAL_SECONDS',
    'WEB_WATCHDOG_TIMEOUT_SECONDS','WEB_WATCHDOG_FAILURE_THRESHOLD',
    'WEB_WATCHDOG_START_GRACE_SECONDS','WEB_PROCESS_RESTART_MAX',
    'WEB_PROCESS_RESTART_WINDOW_SECONDS','WEB_PROCESS_STOP_GRACE_SECONDS','WEB_PROCESS_RESTART_BACKOFF_SECONDS','WEB_PROCESS_RESTART_MAX_BACKOFF_SECONDS',
    'WEB_INFRA_PROBE_INTERVAL_SECONDS','WEB_INFRA_PROBE_TIMEOUT_SECONDS','WEB_INFRA_PROBE_FAILURE_THRESHOLD','RUNTIME_LOG_MAX_BYTES','HEALTH_MIN_DISK_FREE_MB',
):
    assert key in web_env, f'{key} missing from web environment'
assert '__laravel_health' in str(web_env['WEB_WATCHDOG_URL'])
assert web_env.get('SESSION_DRIVER') == 'database', 'web sessions must use the database backend'
assert web_env.get('SESSION_CONNECTION') == 'pgsql', 'web database sessions must use pgsql'
assert web_env.get('SESSION_ENCRYPT') == 'false', 'database session payload encryption must remain disabled'
assert web_env.get('CACHE_STORE') == 'database', 'web cache/rate limiter must use the database backend'
assert 'redis' not in web.get('depends_on', {}), 'web startup must not depend on Redis queue availability'

worker_command=' '.join(map(str,services['worker'].get('command',[])))
scheduler_command=' '.join(map(str,services['scheduler'].get('command',[])))
assert 'happy-family-background worker' in worker_command, 'worker background supervisor missing'
assert 'happy-family-background scheduler' in scheduler_command, 'scheduler background supervisor missing'
background=open('docker/background-start.sh',encoding='utf-8').read()
for token in ('--timeout=900','--memory=240','--max-jobs=200','--max-time=1800','schedule:work','child exited rc=','BACKGROUND_LOG_MAX_BYTES'):
    assert token in background, f'background workload invariant missing {token}'
for service in ('worker','scheduler'):
    assert services[service].get('depends_on',{}).get('web',{}).get('condition') == 'service_healthy', f'{service} must wait for migrated healthy web'
    assert 'BACKGROUND_LOG_MAX_BYTES' in services[service].get('environment',{}), f'{service} background log cap missing'

redis_command=' '.join(map(str,services['redis'].get('command',[])))
assert '--maxmemory' in redis_command and 'noeviction' in redis_command
postgres_command=' '.join(map(str,services['db'].get('command',[])))
for token in ('shared_buffers=','work_mem=','statement_timeout=','idle_in_transaction_session_timeout=','max_connections=${POSTGRES_MAX_CONNECTIONS:-30}'):
    assert token in postgres_command, f'PostgreSQL safety setting missing {token}'

ini=open('docker/php/conf.d/zz-production.ini',encoding='utf-8').read()
m=re.search(r'^memory_limit=(\d+)M$',ini,re.M)
assert m and int(m.group(1)) <= 128, 'web PHP memory_limit must be <=128M'
fpm=open('docker/php/fpm/zz-happy-family.conf',encoding='utf-8').read()
assert '[control]' in fpm and 'listen = 127.0.0.1:9001' in fpm and 'ping.path = /fpm-ping' in fpm
for pool in ('www','control','reports','health'):
    block=re.search(r'\['+re.escape(pool)+r'\]([\s\S]*?)(?=\n\[|\Z)',fpm)
    assert block and 'user = www-data' in block.group(1) and 'group = www-data' in block.group(1), f'{pool} pool user/group missing'
assert 'pm.max_children = 4' in fpm
assert '[reports]' in fpm and 'listen = 127.0.0.1:9002' in fpm
assert '[health]' in fpm and 'listen = 127.0.0.1:9003' in fpm
nginx=open('docker/nginx/default.conf',encoding='utf-8').read()
assert 'location = /monitoring/reports/export' in nginx and 'fastcgi_pass 127.0.0.1:9002' in nginx
assert 'location = /__fpm_health' in nginx and 'fastcgi_pass 127.0.0.1:9001' in nginx
assert 'location = /__laravel_health' in nginx and 'fastcgi_pass 127.0.0.1:9003' in nginx and 'fastcgi_param REQUEST_URI /up' in nginx
assert 'location = /health/live' in nginx and 'location = /health/ready' in nginx, 'public health endpoints must use isolated health pool'
assert 'access_log /dev/stdout' in nginx and 'error_log /dev/stderr' in nginx
web_supervisor=open('docker/web-start.sh',encoding='utf-8').read()
for token in ('WEB_INFRA_PROBE_INTERVAL_SECONDS','Nginx PID was alive but health endpoint was unresponsive','PHP-FPM master PID was alive but control pool was unresponsive','WEB_PROCESS_RESTART_BACKOFF_SECONDS'):
    assert token in web_supervisor, f'web infrastructure recovery invariant missing {token}'
health_controller=open('app/Http/Controllers/HealthController.php',encoding='utf-8').read()
assert 'setex' in health_controller and 'health:ready:redis' in health_controller, 'Redis readiness must test queue write capability'
entrypoint=open('docker/entrypoint.sh',encoding='utf-8').read()
assert 'validate_app_key' in entrypoint and 'validate_app_url' in entrypoint
PY

echo "[10/13] Shell syntax"
find docker scripts -type f -name '*.sh' -print0 | xargs -0 -n1 sh -n

echo "[11/13] Runtime dependencies"
if [ ! -f vendor/autoload.php ]; then
  echo "vendor/ is missing. Run: composer install" >&2
  exit 2
fi
if [ ! -d node_modules ]; then
  echo "node_modules/ is missing. Run: npm install" >&2
  exit 2
fi

echo "[12/13] Automated tests and frontend checks"
php artisan config:clear
php artisan test
npm run types:check
npm run build
php artisan route:cache
php artisan config:cache

echo "[13/13] Release check complete"
echo "PASS"
