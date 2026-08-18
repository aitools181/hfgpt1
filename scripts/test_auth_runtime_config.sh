#!/bin/sh
set -eu

fail() { echo "[auth-runtime-test] FAIL: $*" >&2; exit 1; }
pass() { echo "[auth-runtime-test] PASS: $*"; }

grep -q "SESSION_DRIVER: database" docker-compose.yml || fail "web/compose is not database-session based"
grep -q "CACHE_STORE: database" docker-compose.yml || fail "compose is not database-cache based"
grep -q "'driver' => env('SESSION_DRIVER', 'database')" config/session.php || fail "session default is not database"
grep -q "'encrypt' => filter_var(env('SESSION_ENCRYPT', false)" config/session.php || fail "session payload encryption hardening missing"
grep -q "'database' => \[" config/cache.php || fail "database cache store missing"
grep -q "Schema::create('sessions'" database/migrations/2026_08_18_020001_harden_session_and_cache_backends.php || fail "sessions migration missing"
grep -q "Schema::create('cache'" database/migrations/2026_08_18_020001_harden_session_and_cache_backends.php || fail "cache migration missing"
grep -q "Schema::create('cache_locks'" database/migrations/2026_08_18_020001_harden_session_and_cache_backends.php || fail "cache locks migration missing"
if grep -q "Route::post('/login'.*throttle:" routes/web.php; then fail "login still has fail-closed framework throttle middleware"; fi
grep -q "SessionGuard::attempt() already migrates" app/Http/Controllers/Auth/LoginController.php || fail "redundant-login-session-rotation fix missing"
grep -q "database session write/read/delete probe" routes/console.php || fail "startup database session round-trip probe missing"
grep -q "session_backend" app/Http/Controllers/HealthController.php || fail "readiness session backend probe missing"
grep -q "location = /health/ready" docker/nginx/default.conf || fail "isolated public readiness route missing"
grep -q "fastcgi_pass 127.0.0.1:9003" docker/nginx/default.conf || fail "isolated health FPM pool not wired"

pass "login/session/cache/health runtime invariants"
