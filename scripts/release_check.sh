#!/bin/sh
set -eu

cd "$(dirname "$0")/.."

echo "[1/9] PHP syntax"
find app bootstrap config database routes tests -type f -name '*.php' -print0 | xargs -0 -n1 php -l >/tmp/happy-family-php-lint.txt

echo "[2/9] Static source integrity"
python3 scripts/static_integrity_check.py

echo "[3/9] Web watchdog behavior"
scripts/test_web_watchdog.sh

echo "[4/9] Composer manifest"
php -r '$j=json_decode(file_get_contents("composer.json"), true, 512, JSON_THROW_ON_ERROR); if (!isset($j["require"]["laravel/framework"])) exit(1);'

echo "[5/9] NPM manifest"
node -e 'JSON.parse(require("fs").readFileSync("package.json","utf8"))'

echo "[6/9] Docker Compose YAML"
python3 - <<'PY'
import yaml
with open('docker-compose.yml','r',encoding='utf-8') as f:
    data=yaml.safe_load(f)
assert isinstance(data,dict) and 'services' in data and {'web','worker','scheduler','db','redis'} <= set(data['services'])
assert 'app' not in data['services']
for service in ('web','worker','scheduler','db','redis'):
    assert 'healthcheck' in data['services'][service], f'{service} healthcheck missing'
assert '/up' in ' '.join(data['services']['web']['healthcheck']['test'])
for service in ('web','worker','scheduler','db','redis'):
    assert data['services'][service].get('restart') == 'unless-stopped', f'{service} restart policy missing'
web_env = data['services']['web'].get('environment', {})
for key in ('WEB_WATCHDOG_ENABLED','WEB_WATCHDOG_URL','WEB_WATCHDOG_INTERVAL_SECONDS','WEB_WATCHDOG_TIMEOUT_SECONDS','WEB_WATCHDOG_FAILURE_THRESHOLD','WEB_WATCHDOG_START_GRACE_SECONDS'):
    assert key in web_env, f'{key} missing from web environment'
PY

echo "[7/9] Runtime dependencies"
if [ ! -f vendor/autoload.php ]; then
  echo "vendor/ is missing. Run: composer install" >&2
  exit 2
fi
if [ ! -d node_modules ]; then
  echo "node_modules/ is missing. Run: npm install" >&2
  exit 2
fi

echo "[8/9] Automated tests and frontend checks"
php artisan config:clear
php artisan test
npm run types:check
npm run build
php artisan route:cache
php artisan config:cache

echo "[9/9] Release check complete"
echo "PASS"
