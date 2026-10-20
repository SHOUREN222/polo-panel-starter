# Ops scripts

Server-side operational scripts that aren't part of the app runtime. Secret-free
by design — anything host-specific (IPs, paths) comes from an **untracked** config
file, not from these committed files.

## Nightly DB backup — `backup-panel-db.sh`

Consistent daily backup of the panel's SQLite DB (`VACUUM INTO` hot snapshot,
gzipped, N-day rotation), with optional offsite copies to other hosts and a
Telegram summary to bound admins (via `php artisan ops:notify`).

### Install

```bash
# 1) Put the script on the server
install -m 755 ops/backup-panel-db.sh /root/backup-panel-db.sh

# 2) Create the site config (holds offsite host IPs — NOT in the repo)
cp ops/backup-panel-db.conf.example /etc/panel-backup.conf
$EDITOR /etc/panel-backup.conf          # set OFFSITE_NODES="ip1 ip2" (or leave empty for local-only)

# 3) Run once to verify (should print local ok + offsite ok, and TG-notify admins)
/root/backup-panel-db.sh

# 4) Schedule it daily (keep any existing crontab lines)
( crontab -l 2>/dev/null | grep -v backup-panel-db.sh; \
  echo "30 3 * * * /root/backup-panel-db.sh >> /var/log/panel-backup.log 2>&1" ) | crontab -
```

### Notes

- **Offsite requires key-based SSH** from this box to each host as `root`
  (default key: `storage/app/relay_ssh_key`). Each host only ever receives a
  backup file in `OFFSITE_DIR`; nothing else on it is touched.
- Leave `OFFSITE_NODES=""` for a local-only backup — still runs and notifies.
- Telegram summary needs a bound admin + bot token configured in the panel; it's
  a no-op otherwise (the backup itself still runs).

### Restore

```bash
gunzip -c /root/backups/panel-db-YYYYmmdd-HHMMSS.sqlite.gz > /var/www/panel/database/database.sqlite
chown www-data:www-data /var/www/panel/database/database.sqlite
systemctl reload php8.4-fpm
```
