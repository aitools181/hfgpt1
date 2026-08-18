#!/bin/sh
set -eu
cd "$(dirname "$0")/.."
TMP="$(mktemp -d)"
cleanup() { rm -rf "$TMP"; }
trap cleanup EXIT INT TERM
chmod 755 "$TMP"
mkdir -p "$TMP/bootstrap/cache" "$TMP/storage"
cp docker/entrypoint.sh "$TMP/entrypoint.sh"
chmod +x "$TMP/entrypoint.sh"

valid_key='base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA='
(
  cd "$TMP"
  APP_KEY="$valid_key" APP_URL='https://example.invalid' DB_PASSWORD='test-password' RUN_MIGRATIONS=false ./entrypoint.sh true
)
echo 'Bootstrap valid configuration PASS'

set +e
(
  cd "$TMP"
  APP_KEY='base64:not-a-valid-32-byte-key' APP_URL='https://example.invalid' DB_PASSWORD='test-password' RUN_MIGRATIONS=false ./entrypoint.sh true
) >/tmp/hfp-invalid-key.log 2>&1
rc=$?
set -e
[ "$rc" -ne 0 ] || { cat /tmp/hfp-invalid-key.log; echo 'Invalid APP_KEY was accepted' >&2; exit 1; }
grep -q 'APP_KEY' /tmp/hfp-invalid-key.log
echo 'Bootstrap invalid APP_KEY rejection PASS'

set +e
(
  cd "$TMP"
  APP_KEY="$valid_key" APP_URL='not-a-url' DB_PASSWORD='test-password' RUN_MIGRATIONS=false ./entrypoint.sh true
) >/tmp/hfp-invalid-url.log 2>&1
rc=$?
set -e
[ "$rc" -ne 0 ] || { cat /tmp/hfp-invalid-url.log; echo 'Invalid APP_URL was accepted' >&2; exit 1; }
grep -q 'APP_URL' /tmp/hfp-invalid-url.log
echo 'Bootstrap invalid APP_URL rejection PASS'
