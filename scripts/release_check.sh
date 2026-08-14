#!/bin/sh
set -eu

cd "$(dirname "$0")/.."

echo "[1/7] PHP syntax"
find app bootstrap config database routes tests -type f -name '*.php' -print0 | xargs -0 -n1 php -l >/tmp/happy-family-php-lint.txt

echo "[2/7] Composer manifest"
php -r '$j=json_decode(file_get_contents("composer.json"), true, 512, JSON_THROW_ON_ERROR); if (!isset($j["require"]["laravel/framework"])) exit(1);'

echo "[3/7] NPM manifest"
node -e 'JSON.parse(require("fs").readFileSync("package.json","utf8"))'

echo "[4/7] Docker Compose YAML"
python3 - <<'PY'
import yaml
with open('docker-compose.yml','r',encoding='utf-8') as f:
    data=yaml.safe_load(f)
assert isinstance(data,dict) and 'services' in data and {'app','web','worker','scheduler','db','redis'} <= set(data['services'])
PY

echo "[5/7] Runtime dependencies"
if [ ! -f vendor/autoload.php ]; then
  echo "vendor/ is missing. Run: composer install" >&2
  exit 2
fi
if [ ! -d node_modules ]; then
  echo "node_modules/ is missing. Run: npm install" >&2
  exit 2
fi

echo "[6/7] Automated tests and frontend checks"
php artisan config:clear
php artisan test
npm run types:check
npm run build
php artisan route:cache
php artisan config:cache

echo "[7/7] Release check complete"
echo "PASS"
