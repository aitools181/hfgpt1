#!/bin/sh
set -eu

if ! command -v docker >/dev/null 2>&1; then
    echo "ERROR: docker CLI is required. Run this on the Coolify host/server." >&2
    exit 2
fi

container="${1:-}"
if [ -z "$container" ]; then
    echo "Usage: $0 <web-container-name-or-id>" >&2
    echo "" >&2
    echo "Find the web container first:" >&2
    echo "  docker ps -a --format 'table {{.ID}}\t{{.Names}}\t{{.Status}}'" >&2
    exit 2
fi

printf '%s\n' '=== Container state ==='
docker inspect "$container" --format 'Name={{.Name}}
Status={{.State.Status}}
Running={{.State.Running}}
Health={{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}
ExitCode={{.State.ExitCode}}
OOMKilled={{.State.OOMKilled}}
RestartPolicy={{.HostConfig.RestartPolicy.Name}}
RestartCount={{.RestartCount}}
StartedAt={{.State.StartedAt}}
FinishedAt={{.State.FinishedAt}}
Error={{.State.Error}}'

printf '%s\n' '' '=== Health history (last 10) ==='
docker inspect "$container" --format '{{if .State.Health}}{{range .State.Health.Log}}{{println .Start " exit=" .ExitCode " " .Output}}{{end}}{{else}}No Docker health history{{end}}' | tail -n 10 || true

printf '%s\n' '' '=== Recent runtime logs ==='
docker logs --tail 250 "$container" 2>&1 || true

printf '%s\n' '' '=== Host memory / disk ==='
free -h 2>/dev/null || true
df -h / /var/lib/docker 2>/dev/null || df -h / 2>/dev/null || true

printf '%s\n' '' '=== Live resource usage ==='
docker stats --no-stream --format 'table {{.Name}}\t{{.CPUPerc}}\t{{.MemUsage}}\t{{.MemPerc}}\t{{.PIDs}}' 2>/dev/null || true
printf '%s\n' '' '=== Docker daemon summary ==='
docker info --format 'Containers={{.Containers}} Running={{.ContainersRunning}} Stopped={{.ContainersStopped}} CPUs={{.NCPU}} Memory={{.MemTotal}} Driver={{.Driver}}' 2>/dev/null || true

printf '%s\n' '' '=== Recent Docker events for this container (last 2h) ==='
docker events --since 2h --until 0s --filter "container=$(docker inspect "$container" --format '{{.Id}}')" 2>/dev/null | tail -n 80 || true

printf '%s\n' '' '=== Kernel OOM / kill evidence (last 4h when journalctl is available) ==='
if command -v journalctl >/dev/null 2>&1; then
    journalctl -k --since '4 hours ago' --no-pager 2>/dev/null | grep -Ei 'out of memory|oom|killed process|memory cgroup out of memory' | tail -n 100 || true
else
    dmesg 2>/dev/null | grep -Ei 'out of memory|oom|killed process|memory cgroup out of memory' | tail -n 100 || true
fi

printf '%s\n' '' '=== Persistent supervisor log (if mounted) ==='
docker exec "$container" sh -c 'tail -n 250 /var/www/html/storage/logs/runtime-supervisor.log 2>/dev/null || true' 2>/dev/null || true
