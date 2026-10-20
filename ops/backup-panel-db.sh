#!/usr/bin/env bash
# Daily consistent backup of the panel's SQLite DB (session/queue/cache/orders
# all live in this one file). Uses SQLite VACUUM INTO for a hot, consistent
# snapshot — safe while the app is writing. Keeps N days locally, and OPTIONALLY
# pushes an offsite copy to one or more hosts (only a backup file is written to
# each; nothing else on those hosts is touched). Sends a Telegram summary to
# bound admins via `artisan ops:notify` so a silent failure surfaces.
#
# Secrets (offsite host IPs) live in an untracked config file, NOT in this repo:
#   /etc/panel-backup.conf   (or point $PANEL_BACKUP_CONF at another path)
# See ops/backup-panel-db.conf.example for the template.
set -uo pipefail

# --- Load optional site config (offsite host list etc.) ----------------------
CONF="${PANEL_BACKUP_CONF:-/etc/panel-backup.conf}"
# shellcheck source=/dev/null
[ -f "$CONF" ] && . "$CONF"

PANEL="${PANEL_DIR:-/var/www/panel}"
DB="${DB_PATH:-$PANEL/database/database.sqlite}"
DEST="${BACKUP_DEST:-/root/backups}"
RETAIN_DAYS="${RETAIN_DAYS:-14}"
PHP_BIN="${PHP_BIN:-php8.4}"
SSH_KEY="${SSH_KEY:-$PANEL/storage/app/relay_ssh_key}"
OFFSITE_NODES="${OFFSITE_NODES:-}"          # space-separated host list; empty = local only
OFFSITE_DIR="${OFFSITE_DIR:-/root/panel-backups}"

notify() { cd "$PANEL" && "$PHP_BIN" artisan ops:notify "$1" >/dev/null 2>&1 || true; }

mkdir -p "$DEST"
TS=$(date +%Y%m%d-%H%M%S)
NOW=$(date '+%Y-%m-%d %H:%M')
OUT="$DEST/panel-db-$TS.sqlite"

# 1) Local consistent snapshot (not a plain cp — that can tear under WAL writes).
if ! "$PHP_BIN" -r '$p=new PDO("sqlite:".$argv[1]); $p->exec("VACUUM INTO ".$p->quote($argv[2]));' "$DB" "$OUT"; then
    echo "$(date +%F_%T) FATAL: local snapshot failed" >&2
    notify "❌ 面板备份失败！本地快照未生成，请立即检查服务器。
时间: $NOW"
    exit 1
fi
gzip -f "$OUT"
GZ="${OUT}.gz"
SIZE=$(du -h "$GZ" | cut -f1)

find "$DEST" -name 'panel-db-*.sqlite.gz' -mtime +"$RETAIN_DAYS" -delete
echo "$(date +%F_%T) local ok: $GZ ($SIZE)"

# 1b) Each 分站 lives in its OWN SQLite file (database/tenant<id>.sqlite) once
# multi-tenant launches. Snapshot every one the same hot/consistent way. With
# no 分站 yet this loop does nothing → identical to the old main-only backup.
BACKUP_GZS=("$GZ")
SNAP_WARN=0
shopt -s nullglob
for TDB in "$PANEL"/database/tenant*.sqlite; do
    TBASE=$(basename "$TDB" .sqlite)                 # e.g. tenantdaili1
    TOUT="$DEST/panel-tdb-$TBASE-$TS.sqlite"
    if "$PHP_BIN" -r '$p=new PDO("sqlite:".$argv[1]); $p->exec("VACUUM INTO ".$p->quote($argv[2]));' "$TDB" "$TOUT"; then
        gzip -f "$TOUT"
        BACKUP_GZS+=("${TOUT}.gz")
    else
        echo "$(date +%F_%T) WARN: tenant snapshot failed -> $TDB" >&2
        SNAP_WARN=1
    fi
done
shopt -u nullglob
find "$DEST" -name 'panel-tdb-*.sqlite.gz' -mtime +"$RETAIN_DAYS" -delete
TENANT_COUNT=$(( ${#BACKUP_GZS[@]} - 1 ))
TOTAL=$(du -ch "${BACKUP_GZS[@]}" | tail -1 | cut -f1)
echo "$(date +%F_%T) tenant snapshots: $TENANT_COUNT (total $TOTAL)"

# 2) Offsite copies (only if configured). Push the main库 + every 分站库. A host
# being down only warns; every snapshot is always safe locally.
ALL_OK=1
LINES="本地: ok（主库 + ${TENANT_COUNT} 分站库）"
for H in $OFFSITE_NODES; do
    HOST_OK=1
    for F in "${BACKUP_GZS[@]}"; do
        if ! scp -i "$SSH_KEY" -o BatchMode=yes -o StrictHostKeyChecking=accept-new -o ConnectTimeout=20 "$F" "root@$H:$OFFSITE_DIR/" 2>/dev/null; then
            HOST_OK=0; break
        fi
    done
    if [ "$HOST_OK" = 1 ] && ssh -i "$SSH_KEY" -o BatchMode=yes -o StrictHostKeyChecking=accept-new -o ConnectTimeout=20 "root@$H" \
            "mkdir -p '$OFFSITE_DIR'; chmod 700 '$OFFSITE_DIR'; chmod 600 '$OFFSITE_DIR'/panel-*.sqlite.gz 2>/dev/null; find '$OFFSITE_DIR' -name 'panel-db-*.sqlite.gz' -mtime +$RETAIN_DAYS -delete; find '$OFFSITE_DIR' -name 'panel-tdb-*.sqlite.gz' -mtime +$RETAIN_DAYS -delete" 2>/dev/null; then
        echo "$(date +%F_%T) offsite ok -> $H"
        LINES="$LINES
$H: ok"
    else
        echo "$(date +%F_%T) WARN: offsite failed -> $H (backup still safe locally)"
        LINES="$LINES
$H: 失败"
        ALL_OK=0
    fi
done

SUMMARY="主库 + ${TENANT_COUNT} 分站库，共 $TOTAL"
[ "$SNAP_WARN" = 1 ] && SUMMARY="$SUMMARY
⚠️ 有分站库快照失败，请检查"

if [ -z "${OFFSITE_NODES// }" ]; then
    notify "✅ 面板每日备份完成（仅本地）
时间: $NOW
$SUMMARY"
elif [ "$ALL_OK" = 1 ] && [ "$SNAP_WARN" = 0 ]; then
    notify "✅ 面板每日备份完成
时间: $NOW
$SUMMARY
$LINES"
else
    notify "⚠️ 面板备份：本地成功，但有失败项（分站快照或异地推送）
时间: $NOW
$SUMMARY
$LINES"
fi
