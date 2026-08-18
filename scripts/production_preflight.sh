#!/bin/sh
set -eu

min_ram_mb=${MIN_HOST_RAM_MB:-4500}
min_disk_mb=${MIN_HOST_DISK_FREE_MB:-10240}
errors=0
warnings=0

printf '%s\n' 'SMVS Happy Family - production host preflight'
printf '%s\n' 'Default container memory ceilings total about 2.9 GB; leave additional RAM for Coolify, Docker, Traefik and the OS.'

if [ -r /proc/meminfo ]; then
    ram_kb=$(awk '/^MemTotal:/ {print $2}' /proc/meminfo)
    ram_mb=$((ram_kb / 1024))
    printf 'Host RAM: %s MB\n' "$ram_mb"
    if [ "$ram_mb" -lt "$min_ram_mb" ]; then
        printf 'ERROR: Host RAM is below recommended production minimum %s MB.\n' "$min_ram_mb" >&2
        errors=$((errors + 1))
    fi
else
    printf '%s\n' 'WARNING: Could not read /proc/meminfo.' >&2
    warnings=$((warnings + 1))
fi

free_kb=$(df -Pk / 2>/dev/null | awk 'NR==2 {print $4}')
if [ -n "${free_kb:-}" ]; then
    free_mb=$((free_kb / 1024))
    printf 'Root disk free: %s MB\n' "$free_mb"
    if [ "$free_mb" -lt "$min_disk_mb" ]; then
        printf 'ERROR: Root disk free space is below recommended production minimum %s MB.\n' "$min_disk_mb" >&2
        errors=$((errors + 1))
    fi
fi

if command -v docker >/dev/null 2>&1; then
    printf 'Docker: %s\n' "$(docker version --format '{{.Server.Version}}' 2>/dev/null || echo unavailable)"
    if ! docker info >/dev/null 2>&1; then
        printf '%s\n' 'ERROR: Docker daemon is not reachable.' >&2
        errors=$((errors + 1))
    fi
else
    printf '%s\n' 'WARNING: Docker CLI not found. This check should be run on the Coolify server.' >&2
    warnings=$((warnings + 1))
fi

if [ "$errors" -gt 0 ]; then
    printf 'PRECHECK FAILED: errors=%s warnings=%s\n' "$errors" "$warnings" >&2
    exit 1
fi
printf 'PRECHECK PASS: warnings=%s\n' "$warnings"
