#!/usr/bin/env bash
#
# One-command deploy to the existing production server.
#
#   cp .deploy.env.example .deploy.env   # fill in host/path once
#   ./deploy.sh                          # ship current working tree
#   ./deploy.sh --dry-run                # preview rsync, change nothing
#
# What it does: rsync the source (no secrets/vendor/node_modules), then on the
# server: composer install, npm build, migrate, clear caches, reload PHP-FPM,
# restart the queue worker. Backs up public/build first for a quick rollback.
set -euo pipefail

cd "$(dirname "$0")"

DRY_RUN=""
ASSUME_YES=""
for arg in "$@"; do
    case "$arg" in
        --dry-run) DRY_RUN="--dry-run" ;;
        -y|--yes)  ASSUME_YES=1 ;;
        *) echo "unknown option: $arg"; exit 1 ;;
    esac
done

if [ ! -f .deploy.env ]; then
    echo "✗ .deploy.env not found. Run: cp .deploy.env.example .deploy.env  (then edit it)"
    exit 1
fi
# shellcheck disable=SC1091
source .deploy.env
: "${SSH_HOST:?set SSH_HOST in .deploy.env}"
: "${REMOTE_PATH:?set REMOTE_PATH in .deploy.env}"
SSH_PORT="${SSH_PORT:-22}"
FPM_SERVICE="${FPM_SERVICE:-php8.4-fpm}"
QUEUE_SERVICE="${QUEUE_SERVICE:-panel-queue.service}"
# The user php-fpm runs as — everything under REMOTE_PATH must end up owned by
# it (see the chown step below).
WEB_USER="${WEB_USER:-www-data}"

SSH="ssh -p ${SSH_PORT} ${SSH_HOST}"

echo "▸ Target: ${SSH_HOST}:${REMOTE_PATH}  (fpm=${FPM_SERVICE}, queue=${QUEUE_SERVICE})"
if [ -z "$DRY_RUN" ] && [ -z "$ASSUME_YES" ]; then
    read -r -p "Deploy the current working tree to production? [y/N] " reply
    [ "$reply" = "y" ] || [ "$reply" = "Y" ] || { echo "aborted."; exit 0; }
fi

# 1. Sync source. Never push secrets, deps, local db, or build state — those
# are rebuilt/kept on the server. --delete keeps the remote a mirror of source.
echo "▸ Syncing source ..."
rsync -az --delete $DRY_RUN \
    -e "ssh -p ${SSH_PORT}" \
    --exclude '.git' \
    --exclude '.env' \
    --exclude '.env.*' \
    --exclude '.deploy.env' \
    --exclude '/vendor' \
    --exclude '/node_modules' \
    --exclude '/public/build' \
    --exclude '/public/hot' \
    --exclude '/public/storage' \
    --exclude '/storage/logs/*' \
    --exclude '/storage/framework/cache/*' \
    --exclude '/storage/framework/sessions/*' \
    --exclude '/storage/framework/views/*' \
    --exclude '/database/*.sqlite*' \
    `# storage/app is RUNTIME STATE, never in git (only .gitignore stubs are).` \
    `# It holds storage/app/relay_ssh_key — the private key NodeRelayService and` \
    `# backup-panel-db.sh use to reach the relay/offsite nodes (deleting it is` \
    `# unrecoverable) — and storage/app/public/app-downloads/*, the client` \
    `# installers customers download. --delete would wipe both. /public/storage` \
    `# (the symlink to storage/app/public) was already excluded; this is its source.` \
    --exclude '/storage/app' \
    ./ "${SSH_HOST}:${REMOTE_PATH}/"

if [ -n "$DRY_RUN" ]; then
    echo "▸ Dry run complete — nothing was changed on the server."
    exit 0
fi

# 2. Server-side build + release steps.
echo "▸ Building & releasing on the server ..."
$SSH "set -euo pipefail
    cd '${REMOTE_PATH}'
    # backup current compiled assets for a fast rollback
    rm -rf public/build.prev 2>/dev/null || true
    [ -d public/build ] && cp -r public/build public/build.prev || true

    composer install --no-dev --optimize-autoloader --no-interaction
    npm ci --no-audit --no-fund
    npm run build

    php artisan migrate --force
    # 租户库迁移：给每个已存在的分站 SQLite 库补上新迁移。首次上线还没有分站时
    # 是空跑；以后每次部署都要跑，否则老分站库会缺新表/列。central 迁移在前，租户在后。
    php artisan tenants:migrate --force
    php artisan optimize:clear
    rm -f storage/framework/views/*.php
    php artisan config:cache
    php artisan route:cache

    # MUST run after every write above, and before the reload.
    # Two things break php-fpm's (www-data) access otherwise:
    #   1. rsync -a preserves the *local* machine's uid/gid, stamping them onto
    #      every synced directory (database/, storage/, bootstrap/cache/);
    #   2. composer/npm/artisan above run as root here, so anything they create
    #      (incl. database.sqlite-wal from migrate) lands root-owned.
    # Either way www-data loses write access, and SQLite reports it as the very
    # unhelpful "attempt to write a readonly database" — a hard 500 on every
    # page that writes (login, sessions), while "/" still 302s so a naive
    # health check passes. This bit production once; do not remove.
    chown -R '${WEB_USER}':'${WEB_USER}' '${REMOTE_PATH}'

    # Lock secrets down to the web user only. .env carries DB/SMTP/payment
    # credentials, *.sqlite* is the whole database, and storage/app holds
    # the relay SSH private key (see the rsync exclude comment above) — all
    # three land group/world-readable by default, which any other account
    # on the box (or a misconfigured neighboring site) could read straight
    # off disk. Idempotent, so it's safe to re-run every deploy.
    chmod 600 .env
    chmod 600 database/*.sqlite* 2>/dev/null || true
    find storage/app -type d -exec chmod 700 {} \;
    find storage/app -type f -exec chmod 600 {} \;

    systemctl reload '${FPM_SERVICE}'
    php artisan queue:restart
    if [ -n '${TELEGRAM_SERVICE:-}' ]; then systemctl restart '${TELEGRAM_SERVICE}' || true; fi
    echo '✓ released'
"

# 3. Health check.
if [ -n "${HEALTH_URL:-}" ]; then
    echo "▸ Health check: ${HEALTH_URL}"
    code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 15 "${HEALTH_URL}" || echo "000")
    if [ "$code" = "200" ] || [ "$code" = "302" ]; then
        echo "✓ ${HEALTH_URL} → HTTP ${code}"
    else
        echo "⚠ ${HEALTH_URL} → HTTP ${code} (check the server; rollback assets: mv public/build.prev public/build)"
        exit 1
    fi
fi

echo "✓ Deploy complete."
