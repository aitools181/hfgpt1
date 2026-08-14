# Backup and Restore

## Create a backup

From a host with Docker Compose access to the running project:

```bash
./scripts/backup.sh
```

Or select a destination:

```bash
./scripts/backup.sh /secure/backups/happy-family/pre-release
```

## Restore

Restoring replaces database state and persistent public uploads in the target deployment.

```bash
CONFIRM_RESTORE=YES ./scripts/restore.sh /secure/backups/happy-family/pre-release
```

Always restore into a non-production environment first when validating a backup. After restoration, verify `/health/ready`, authentication, core workflow records, uploads, reports and Activity/Audit Logs.
