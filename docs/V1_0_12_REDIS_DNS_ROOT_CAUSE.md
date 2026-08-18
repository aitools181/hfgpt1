# v1.0.12 Redis / DNS root-cause note

## Confirmed production evidence

1. Nginx access logs show successful requests on `https://hfgpt1.divyajivan.com`, including `POST /login` -> 302, authenticated `GET /` -> 200, and repeated internal `GET /up` -> 200.
2. The browser screenshots that show **Server Not Found** use `hfgpt1.divyaivan.com` (missing the `j` in `divyajivan`). That hostname is different, so the request never reaches Nginx/Laravel.
3. Queue logs show `RedisException: read error on connection to redis:6379` every few seconds. The code had `block_for=5` but PhpRedis `read_timeout=2`, so a normal blocking queue wait could outlive the socket timeout and be reported as a connection failure.
4. Redis also reports the Linux host warning `vm.overcommit_memory` is disabled. Redis upstream recommends setting it to `1` for reliable background save/fork behavior.

## v1.0.12 fix

The queue now uses a dedicated Redis connection with `REDIS_QUEUE_READ_TIMEOUT=15` and `REDIS_QUEUE_BLOCK_FOR=5`. Web/cache Redis retains its short timeout.

## Host action required once on the Coolify server

Run as root on the Docker/Coolify host (not inside the Redis container):

```bash
sysctl -w vm.overcommit_memory=1
printf 'vm.overcommit_memory = 1\n' > /etc/sysctl.d/99-redis-overcommit.conf
sysctl --system
```

Verify:

```bash
sysctl vm.overcommit_memory
```

Expected: `vm.overcommit_memory = 1`.

## Correct public hostname

Use the hostname that appears in successful Nginx access logs: `hfgpt1.divyajivan.com`. A browser request to `hfgpt1.divyaivan.com` is a different DNS name and can legitimately show Server Not Found.
