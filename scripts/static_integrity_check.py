#!/usr/bin/env python3
"""Offline source-integrity checks that do not require Composer or node_modules."""
from __future__ import annotations

import json
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
issues: list[str] = []


def read(path: Path) -> str:
    return path.read_text(encoding="utf-8", errors="replace")


# JSON manifests.
for name in ("composer.json", "package.json"):
    try:
        json.loads(read(ROOT / name))
    except Exception as exc:  # noqa: BLE001
        issues.append(f"Invalid JSON {name}: {exc}")

# Every Inertia render must have a matching React page.
page_root = ROOT / "resources/js/pages"
pages = {str(p.relative_to(page_root)).replace("\\", "/").rsplit(".", 1)[0] for p in page_root.rglob("*.tsx")}
for php in (ROOT / "app").rglob("*.php"):
    source = read(php)
    for match in re.finditer(r"Inertia::render\(['\"]([^'\"]+)", source):
        if match.group(1) not in pages:
            issues.append(f"Missing Inertia page '{match.group(1)}' referenced by {php.relative_to(ROOT)}")

# Route names must be unique and controller methods must exist.
routes_path = ROOT / "routes/web.php"
routes = read(routes_path)
route_names = re.findall(r"->name\('([^']+)'\)", routes)
for name in sorted(set(route_names)):
    if route_names.count(name) > 1:
        issues.append(f"Duplicate route name: {name}")

for match in re.finditer(r"\[([A-Za-z0-9_]+)::class,\s*'([A-Za-z0-9_]+)'\]", routes):
    alias, method = match.groups()
    alias_import = re.search(r"use\s+([^;]+)\s+as\s+" + re.escape(alias) + r";", routes)
    direct_import = re.search(r"use\s+([^;]+\\)" + re.escape(alias) + r";", routes)
    fqcn = alias_import.group(1) if alias_import else (direct_import.group(1) + alias if direct_import else None)
    if not fqcn or not fqcn.startswith("App\\"):
        issues.append(f"Cannot resolve controller import for {alias}::{method}")
        continue
    controller = ROOT / "app" / (fqcn.removeprefix("App\\").replace("\\", "/") + ".php")
    if not controller.exists():
        issues.append(f"Missing controller file for {alias}: {controller.relative_to(ROOT)}")
        continue
    if not re.search(r"function\s+" + re.escape(method) + r"\s*\(", read(controller)):
        issues.append(f"Missing controller method {alias}::{method}")

# Health routes must bypass Redis-backed session/Inertia middleware so a Redis
# outage cannot turn a healthy PHP/Laravel process into a false watchdog kill.
for health_marker in (
    "$healthMiddleware = [StartSession::class, ShareErrorsFromSession::class, HandleInertiaRequests::class]",
    "withoutMiddleware($healthMiddleware)->name('health.live')",
    "withoutMiddleware($healthMiddleware)->name('health.ready')",
):
    if health_marker not in routes:
        issues.append(f"Health dependency-isolation invariant missing: {health_marker}")

# Route/nav permissions must all be seeded.
seeder = read(ROOT / "database/seeders/RolePermissionSeeder.php")
seeded_permissions = set(re.findall(r"\['([a-z0-9_]+)',\s*'[^']+',\s*'[a-z0-9_]+'\]", seeder))
used_permissions: set[str] = set()
for spec in re.findall(r"permission:([a-z0-9_,]+)", routes):
    used_permissions.update(spec.split(","))

layout = read(ROOT / "resources/js/layouts/app-layout.tsx")
used_permissions.update(re.findall(r"permission:\s*'([a-z0-9_]+)'", layout))
for arr in re.findall(r"permissionsAny:\s*\[([^\]]+)\]", layout):
    used_permissions.update(re.findall(r"'([a-z0-9_]+)'", arr))
for permission in sorted(used_permissions - seeded_permissions):
    issues.append(f"Permission used but not seeded: {permission}")

# Every sidebar href should have an exact GET route.
get_paths = set(re.findall(r"Route::get\('([^']+)'", routes))
for href in re.findall(r"href:\s*'([^']+)'", layout):
    if href != "/" and href not in get_paths:
        issues.append(f"Sidebar href has no exact GET route: {href}")

# No merge markers or debug calls in application/runtime PHP.
for path in [*(ROOT / "app").rglob("*.php"), *(ROOT / "routes").rglob("*.php")]:
    source = read(path)
    if re.search(r"\b(dd|dump|var_dump)\s*\(", source):
        issues.append(f"Debug call left in {path.relative_to(ROOT)}")

for path in ROOT.rglob("*"):
    if not path.is_file() or ".git" in path.parts or path.name in {"RELEASE_MANIFEST.txt", "static_integrity_check.py"}:
        continue
    if path.stat().st_size > 2_000_000:
        continue
    try:
        source = read(path)
    except OSError:
        continue
    if "<<<<<<< " in source or ">>>>>>> " in source:
        issues.append(f"Merge conflict marker in {path.relative_to(ROOT)}")

# Password reset security invariants introduced in v1.0.4.
required_password_reset_markers = {
    ROOT / "routes/web.php": ["reset_user_passwords", "users.password.reset"],
    ROOT / "app/Http/Controllers/Admin/UserController.php": ["password_reset", "session_version", "password_changed_at", "lockForUpdate"],
    ROOT / "app/Http/Middleware/EnsureActiveUser.php": ["auth_session_version"],
    ROOT / "database/seeders/RolePermissionSeeder.php": ["reset_user_passwords"],
    ROOT / "app/Http/Controllers/Admin/SettingsController.php": ["Only Super Admin can grant or remove the Reset User Passwords permission"],
}
for path, markers in required_password_reset_markers.items():
    source = read(path)
    for marker in markers:
        if marker not in source:
            issues.append(f"Password reset invariant missing '{marker}' in {path.relative_to(ROOT)}")


# v1.0.8 runtime / scale stability invariants.
watchdog = read(ROOT / "docker/web-start.sh")
for marker in (
    "WEB_WATCHDOG_ENABLED",
    "WEB_WATCHDOG_FAILURE_THRESHOLD",
    "WEB_PROCESS_RESTART_MAX",
    "WEB_PROCESS_STOP_GRACE_SECONDS",
    "bounded_stop",
    "terminate_leftover_processes",
    "pid_running",
    'state=$(awk',
    "recovering PHP-FPM",
    "recovering Nginx",
    "isolated Laravel liveness remained unreachable",
    "runtime snapshot begin",
    "memory.events",
    "SIGHUP received by supervisor and ignored",
):
    if marker not in watchdog:
        issues.append(f"Runtime supervisor invariant missing: {marker}")
if "kill -USR2" in watchdog or "requesting PHP-FPM worker reload" in watchdog:
    issues.append("Runtime supervisor must not use the v1.0.7 USR2 reload recovery path")
if '>> "$RUNTIME_LOG" 2>/dev/null || true' not in watchdog:
    issues.append("Runtime logging must be best-effort so disk/log write errors cannot kill the supervisor")

healthcheck = read(ROOT / "docker/runtime-healthcheck.sh")
for marker in (
    "/run/happy-family/php-fpm.pid", "/run/happy-family/nginx.pid",
    "__container_health", "__fpm_health", "__laravel_health", "pong",
    "/run/happy-family/worker.pid", "/run/happy-family/scheduler.pid",
    "queue:work redis", "schedule:work", 'state=$(awk',
):
    if marker not in healthcheck:
        issues.append(f"Runtime healthcheck invariant missing: {marker}")

compose = read(ROOT / "docker-compose.yml")
for marker in (
    "restart: always",
    "WEB_WATCHDOG_ENABLED",
    "WEB_WATCHDOG_FAILURE_THRESHOLD",
    "WEB_PROCESS_RESTART_MAX",
    "WEB_PROCESS_STOP_GRACE_SECONDS",
    "mem_limit:", "pids_limit:", "max-size:", "max-file:",
    "happy-family-healthcheck", "happy-family-background",
    "--maxmemory-policy noeviction", "statement_timeout=",
):
    if marker not in compose:
        issues.append(f"Compose reliability invariant missing: {marker}")

try:
    import yaml
    compose_data = yaml.safe_load(compose)
    services = compose_data["services"]
    if services["web"].get("environment", {}).get("SESSION_DRIVER") != "file":
        issues.append("Web sessions must use file storage so Redis is not an HTTP availability dependency")
    if services["web"].get("environment", {}).get("CACHE_STORE") != "file":
        issues.append("Web cache must use local file storage so Redis is isolated to queue durability")
    if "app_sessions:/var/www/html/storage/framework/sessions" not in services["web"].get("volumes", []):
        issues.append("Persistent web session volume missing")
    if "redis" in services["web"].get("depends_on", {}):
        issues.append("Web service must not be blocked from starting by Redis queue availability")
except Exception as exc:  # noqa: BLE001
    issues.append(f"Cannot validate Compose dependency isolation: {exc}")

background = read(ROOT / "docker/background-start.sh")
for marker in (
    "queue:work redis", "--memory=240", "--max-jobs=200", "--max-time=1800",
    "schedule:work", "BACKGROUND_RESTART_BACKOFF_SECONDS", "BACKGROUND_LOG_MAX_BYTES", "child exited rc=",
    "termination signal received; stopping child", "/$ROLE.pid",
):
    if marker not in background:
        issues.append(f"Background supervisor invariant missing: {marker}")
if "restart: always" not in compose:
    issues.append("Docker restart:always invariant missing")

for marker_name in (
    "mem_limit: ${WEB_MEMORY_LIMIT:-1280m}",
    "mem_limit: ${WORKER_MEMORY_LIMIT:-448m}",
    "mem_limit: ${DB_MEMORY_LIMIT:-768m}",
    "PGCONNECT_TIMEOUT: ${PGCONNECT_TIMEOUT:-5}",
):
    if marker_name not in compose:
        issues.append(f"Compose resource/timeout invariant missing: {marker_name}")

# Worker and scheduler must wait for the migrated/healthy web service so they
# cannot consume jobs or run scheduled queries against a partially migrated DB.
if compose.count("condition: service_healthy") < 5 or compose.count("web:\n        condition: service_healthy") < 2:
    issues.append("Background startup must depend on healthy web after migrations")

# init:true inserts an init process as PID 1. Health checks must discover actual
# queue/scheduler children instead of assuming the workload is PID 1.
if "/proc/1/cmdline" in compose or "/proc/1/cmdline" in healthcheck:
    issues.append("Runtime healthcheck must not inspect /proc/1/cmdline when init:true is enabled")

fpm = read(ROOT / "docker/php/fpm/zz-happy-family.conf")
for marker in (
    "pm.max_children", "pm.max_requests", "request_terminate_timeout", "request_slowlog_timeout",
    "[control]", "listen = 127.0.0.1:9001", "ping.path = /fpm-ping", "[reports]", "listen = 127.0.0.1:9002", "[health]", "listen = 127.0.0.1:9003",
):
    if marker not in fpm:
        issues.append(f"PHP-FPM production safety invariant missing: {marker}")

for pool in ("www", "control", "reports", "health"):
    block_match = re.search(r"\[" + re.escape(pool) + r"\]([\s\S]*?)(?=\n\[|\Z)", fpm)
    if not block_match or "user = www-data" not in block_match.group(1) or "group = www-data" not in block_match.group(1):
        issues.append(f"PHP-FPM pool {pool} must explicitly run as www-data")

nginx = read(ROOT / "docker/nginx/default.conf")
for marker in ("/__container_health", "/__fpm_health", "/__laravel_health", "fastcgi_param REQUEST_URI /up", "127.0.0.1:9001", "location = /monitoring/reports/export", "127.0.0.1:9002", "127.0.0.1:9003", "fastcgi_buffering off", "access_log /dev/stdout", "error_log /dev/stderr"):
    if marker not in nginx:
        issues.append(f"Nginx isolation/health invariant missing: {marker}")

php_ini = read(ROOT / "docker/php/conf.d/zz-production.ini")
if "memory_limit=192M" in php_ini or "memory_limit=512M" in php_ini:
    # v1.0.8 must stay below the old high-risk web limits. The final value is
    # asserted more precisely in release_check.sh.
    issues.append("PHP web memory_limit still uses an old high-risk value")

report_service = read(ROOT / "app/Services/Monitoring/ReportService.php")
for marker in ("PREVIEW_LIMIT = 500", "public function stream", "lazyById(", "lazyByIdDesc("):
    if marker not in report_service:
        issues.append(f"Bounded report streaming invariant missing: {marker}")

report_controller = read(ROOT / "app/Http/Controllers/Monitoring/ReportController.php")
if "$reports->stream(" not in report_controller:
    issues.append("CSV export no longer uses lazy ReportService::stream")

reader = read(ROOT / "app/Services/TabularFileReader.php")
for marker in ("XMLReader", "assertZipEntrySafe", "DEFAULT_MAX_ZIP_RATIO", "DEFAULT_MAX_ROW_BYTES", "yield array_combine"):
    if marker not in reader:
        issues.append(f"Streaming import safety invariant missing: {marker}")

import_job = read(ROOT / "app/Jobs/ProcessRegistrationImport.php")
if "deleteSourceFile" not in import_job:
    issues.append("Processed/terminal import source cleanup invariant missing")

monitoring = read(ROOT / "app/Services/Monitoring/MonitoringAnalyticsService.php")
for marker in ("$centerPerformance = $this->centerPerformance", "aggregateZonePerformance"):
    if marker not in monitoring:
        issues.append(f"Monitoring duplicate-query prevention invariant missing: {marker}")

bal_service = read(ROOT / "app/Services/Bal/BalPravrutiService.php")
if "groupPerformanceTruncated" not in bal_service or "limit(300)" not in bal_service:
    issues.append("Bal dashboard bounded group-performance invariant missing")
if "->with('sanchalak')->get()" in bal_service:
    issues.append("Bal filter options still hydrate every Group/Sanchalak")

# Large assignment catalogs must be on-demand rather than embedded in page
# payloads at 100k-family / 10k-karyakar scale.
for path, marker in {
    ROOT / "app/Http/Controllers/Assignments/TargetController.php": "searchOptions",
    ROOT / "app/Http/Controllers/Assignments/AreaAssignmentController.php": "searchOptions",
    ROOT / "app/Http/Controllers/Assignments/GroupController.php": "searchEligibleFamilies",
    ROOT / "app/Http/Controllers/Admin/UserController.php": "searchKaryakars",
}.items():
    if marker not in read(path):
        issues.append(f"On-demand catalog invariant missing {marker} in {path.relative_to(ROOT)}")

# v1.0.8 infrastructure failure containment / readiness guards.
registration_import = read(ROOT / "app/Services/RegistrationImportService.php")
for marker_name in ("isInfrastructureFailure", "connection refused", "57P01"):
    if marker_name not in registration_import:
        issues.append(f"Import infrastructure-retry invariant missing: {marker_name}")

import_job = read(ROOT / "app/Jobs/ProcessRegistrationImport.php")
if "public function backoff" not in import_job or "[15, 60, 180]" not in import_job:
    issues.append("Import retry backoff invariant missing")

health_controller = read(ROOT / "app/Http/Controllers/HealthController.php")
for marker_name in ("HEALTH_MIN_DISK_FREE_MB", "disk_free_space", "is_writable", "Redis::connection", "setex", "health:ready:redis"):
    if marker_name not in health_controller:
        issues.append(f"Deep readiness storage/disk invariant missing: {marker_name}")

report_controller_source = read(ROOT / "app/Http/Controllers/Monitoring/ReportController.php")
if "connection_aborted()" not in report_controller_source:
    issues.append("CSV export must stop work when the client disconnects")

console_routes = read(ROOT / "routes/console.php")
if "queue:prune-failed --hours=720" not in console_routes:
    issues.append("Failed-job pruning schedule missing")

entrypoint = read(ROOT / "docker/entrypoint.sh")
for marker_name in ("validate_app_key", "APP_KEY must decode to exactly 32 bytes", "validate_app_url", "PGOPTIONS=\"-c statement_timeout=0 -c lock_timeout=30000\""):
    if marker_name not in entrypoint:
        issues.append(f"Bootstrap fail-fast validation missing: {marker_name}")

web_supervisor = read(ROOT / "docker/web-start.sh")
for marker_name in (
    "WEB_INFRA_PROBE_INTERVAL_SECONDS",
    "WEB_INFRA_PROBE_FAILURE_THRESHOLD",
    "Nginx PID was alive but health endpoint was unresponsive",
    "PHP-FPM master PID was alive but control pool was unresponsive",
    "WEB_PROCESS_RESTART_BACKOFF_SECONDS",
    "WEB_PROCESS_RESTART_MAX_BACKOFF_SECONDS",
):
    if marker_name not in web_supervisor:
        issues.append(f"Web live-but-hung recovery invariant missing: {marker_name}")

inactivity = read(ROOT / "app/Services/Field/InactivityService.php")
for marker_name in ("chunkById(200", "selectRaw('group_id, karyakar_id, MAX(completed_at) AS last_completed_at')", "groupsWithOpenEvents", "23505"):
    if marker_name not in inactivity:
        issues.append(f"Inactivity scale/race hardening invariant missing: {marker_name}")


# v1.0.9 mobile application UI invariants.
mobile_css = read(ROOT / "resources/css/app.css")
mobile_blade = read(ROOT / "resources/views/app.blade.php")
for marker in (
    "hf-mobile-appbar", "hf-mobile-bottom-nav", "hf-mobile-menu-sheet",
    "hf-mobile-table", "More navigation options", "safe-area",
):
    source = layout + mobile_css
    if marker not in source:
        issues.append(f"Mobile UI invariant missing: {marker}")
for marker in ("viewport-fit=cover", "manifest.webmanifest", "apple-mobile-web-app-capable"):
    if marker not in mobile_blade:
        issues.append(f"Mobile viewport/PWA invariant missing: {marker}")
for path in (ROOT / "public/app-icon.svg", ROOT / "public/app-icon-192.png", ROOT / "public/app-icon-512.png", ROOT / "public/manifest.webmanifest"):
    if not path.exists() or path.stat().st_size < 100:
        issues.append(f"Mobile app asset missing/empty: {path.relative_to(ROOT)}")

table_count = 0
mobile_table_count = 0
wrapper_count = 0
for page_path in page_root.rglob("*.tsx"):
    page_source = read(page_path)
    table_count += page_source.count("<table")
    mobile_table_count += page_source.count("hf-mobile-table")
    wrapper_count += page_source.count("hf-table-scroll")
if table_count != mobile_table_count:
    issues.append(f"Mobile table coverage incomplete: {mobile_table_count}/{table_count} tables marked")
if wrapper_count < table_count:
    issues.append(f"Contained table-scroll coverage incomplete: {wrapper_count}/{table_count}")
if "document.querySelectorAll<HTMLTableElement>('.hf-mobile-table')" not in layout:
    issues.append("Mobile table semantic-label hydration missing")
if ".hf-mobile-table td::before" not in mobile_css:
    issues.append("Mobile table card labels CSS missing")
if "form .grid.grid-cols-2" not in mobile_css:
    issues.append("Small-screen form stacking invariant missing")

# v1.0.10 authentication + post-login failure containment invariants.
login_controller = read(ROOT / "app/Http/Controllers/Auth/LoginController.php")
for marker in ("recordSafely", "last_login_at could not be updated", "Secure login session could not be persisted", "tooManyAttempts"):
    if marker not in login_controller:
        issues.append(f"Login resilience invariant missing: {marker}")

audit_service = read(ROOT / "app/Services/AuditTrail.php")
if "function recordSafely" not in audit_service or "Non-blocking audit write failed" not in audit_service:
    issues.append("Non-blocking authentication audit fallback missing")

dashboard_controller = read(ROOT / "app/Http/Controllers/DashboardController.php")
for marker in ("fallbackMonitoring", "Dashboard monitoring query failed", "dashboardWarnings"):
    if marker not in dashboard_controller:
        issues.append(f"Dashboard post-login failure containment invariant missing: {marker}")

auth_repair = ROOT / "database/migrations/2026_08_18_010001_repair_authentication_foundation.php"
if not auth_repair.exists():
    issues.append("Authentication foundation repair migration missing")

for marker in ("auth_schema", "session_storage", "auth_missing_tables", "auth_missing_columns"):
    if marker not in health_controller:
        issues.append(f"Authentication readiness invariant missing: {marker}")

for marker in ("www-data cannot write required Laravel runtime directories", "hf-write-test", "happy-family:auth-preflight"):
    if marker not in entrypoint:
        issues.append(f"Session/auth bootstrap guard missing: {marker}")

console_source = read(ROOT / "routes/console.php")
for marker in ("happy-family:auth-preflight", "audit-log write probe failed", "Super Admin role linkage check failed"):
    if marker not in console_source:
        issues.append(f"Authentication startup preflight invariant missing: {marker}")

ci_source = read(ROOT / ".github/workflows/ci.yml")
if "Production authentication smoke test" not in ci_source or "authenticated dashboard smoke test passed" not in ci_source:
    issues.append("Real Docker login + dashboard CI smoke test missing")

# Production scale indexes must be part of the release.
scale_migration = ROOT / "database/migrations/2026_08_18_000001_add_production_scale_indexes.php"
if not scale_migration.exists():
    issues.append("Production-scale index migration missing")
else:
    scale_source = read(scale_migration)
    for index_name in ("group_karyakars_group_status_idx", "targets_group_status_dates_karyakar_idx", "home_visits_group_karyakar_completed_idx"):
        if index_name not in scale_source:
            issues.append(f"Production-scale inactivity index missing: {index_name}")

if issues:
    print("STATIC INTEGRITY CHECK FAILED")
    for issue in issues:
        print(f"- {issue}")
    sys.exit(1)

print("STATIC INTEGRITY CHECK PASS")
print(f"Inertia pages: {len(pages)}")
print(f"Named routes: {len(route_names)}")
print(f"Seeded permissions: {len(seeded_permissions)}")
print(f"Used route/navigation permissions: {len(used_permissions)}")
