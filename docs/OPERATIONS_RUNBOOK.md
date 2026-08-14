# Production Operations Runbook

## Purpose

This runbook is the operational handoff for the SMVS Happy Family Portal. It assumes deployment from the repository/Docker Compose configuration through Coolify.

## Daily checks

- Confirm the public application responds and `/health/ready` returns HTTP 200 with `database=true` and `cache=true`.
- Confirm the `worker` and `scheduler` services are running.
- Review application/container logs for repeated exceptions, database errors or queue failures.
- Review the portal Reminders / Alerts screen for expected 4-day and 7-day events.
- Review open Support Requests and low-stock Inventory items as part of normal operations.

## Release procedure

1. Run CI on the exact Git commit to be released.
2. Confirm PHP tests, TypeScript type check and Vite production build pass.
3. Take a pre-release backup.
4. Deploy the immutable Git commit in Coolify.
5. The application container runs migrations and the baseline seeder on startup. Baseline seeding is idempotent.
6. Verify `/health/ready`.
7. Sign in as Super Admin and smoke-test Dashboard, Family list, Karyakar list, Groups, My Target (with a field test account), Reports, Bal Pravruti and Support modules.
8. Keep the previous working Git commit available for application rollback. Database rollback should be performed only when the migration/data implications are understood; otherwise restore the pre-release backup.

## Backups

The repository contains `scripts/backup.sh` and `scripts/restore.sh` for a Docker Compose host. A backup contains:

- PostgreSQL logical dump
- persistent `storage/app/public` content
- release `VERSION`
- SHA-256 checksums

Store backups outside the application host and apply the retention policy approved by SMVS. A backup is not considered verified until a restore drill has succeeded in a non-production environment.

## Restore drill

1. Create a fresh isolated deployment using the same release.
2. Copy one backup directory to the deployment host.
3. Run `CONFIRM_RESTORE=YES scripts/restore.sh <backup-directory>`.
4. Verify checksums, `/health/ready`, login, representative records, uploaded content, reports and audit history.
5. Record the drill date, backup timestamp, restore duration and result.

## Queue and scheduler

- `worker` runs Laravel `queue:work` and is intended for asynchronous workloads.
- `scheduler` runs `schedule:work`.
- The inactivity command `happy-family:inactivity-check` runs hourly with overlap protection.
- If reminders stop appearing, first check the scheduler service logs and then run the command manually in the app container.

## Security operations

- Never commit `.env`, production exports, database dumps or member/family data to Git.
- Use a long random `APP_KEY`, database password and Super Admin password.
- Keep `APP_DEBUG=false` in production.
- Keep HTTPS enabled at Coolify/proxy level.
- Rotate credentials immediately when an administrator leaves or a secret is exposed.
- Review Activity/Audit Logs for administrative changes and transfers.
- Supportive content uploads are limited by type and size; do not use the portal as a general file store.

## Incident priorities

1. Protect data integrity and stop unauthorized access.
2. Preserve logs and a current backup before destructive remediation when possible.
3. Restore service from a known-good application commit and verified backup.
4. Document what changed, who acted and what data was affected.
