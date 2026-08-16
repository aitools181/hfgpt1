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


# v1.0.6 runtime self-healing invariants.
watchdog = read(ROOT / "docker/web-start.sh")
for marker in ("WEB_WATCHDOG_ENABLED", "WEB_WATCHDOG_FAILURE_THRESHOLD", "watchdog reached failure threshold", "shutdown 1", "/up"):
    if marker not in watchdog:
        issues.append(f"Runtime watchdog invariant missing: {marker}")
compose = read(ROOT / "docker-compose.yml")
for marker in ("restart: unless-stopped", "WEB_WATCHDOG_ENABLED", "WEB_WATCHDOG_FAILURE_THRESHOLD", "http://127.0.0.1/up"):
    if marker not in compose:
        issues.append(f"Compose self-healing invariant missing: {marker}")

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
