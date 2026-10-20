#!/usr/bin/env bash
set -euo pipefail

cd /var/www/html

# One-time-ish app bootstrap. Runs on every container start but each step is
# idempotent, so `docker compose up` on a fresh OR existing volume just works.

# 0. Named volumes mount empty over storage/ and public/ — recreate the dir
# structure and refresh the public assets from the image's pristine copy so a
# rebuilt image's new build/ + code reach nginx (update-safe).
mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views \
         storage/logs storage/app/public bootstrap/cache
cp -R /usr/src/public/. public/ 2>/dev/null || true

# 1. APP_KEY — generate once and persist into the mounted .env if missing.
# Written directly (not via `key:generate`, which refuses when an APP_KEY env
# var is present). NOTE: .env is a bind-mounted single file, so `sed -i` fails
# ("Resource busy" — it renames a temp over the mount). Build the new content
# in /tmp, then `cat >` truncates+writes the same inode, which works.
if ! grep -q '^APP_KEY=base64:' .env 2>/dev/null; then
    NEWKEY="base64:$(head -c 32 /dev/urandom | base64)"
    if grep -q '^APP_KEY=' .env 2>/dev/null; then
        sed "s|^APP_KEY=.*|APP_KEY=${NEWKEY}|" .env > /tmp/env.new
    else
        cp .env /tmp/env.new
        printf '\nAPP_KEY=%s\n' "${NEWKEY}" >> /tmp/env.new
    fi
    cat /tmp/env.new > .env
    rm -f /tmp/env.new
    echo "Generated a fresh APP_KEY."
fi

# 2. Wait for the database to accept connections before migrating.
if [ "${DB_CONNECTION:-mysql}" = "mysql" ]; then
    echo "Waiting for database ${DB_HOST:-db}:${DB_PORT:-3306} ..."
    for i in $(seq 1 30); do
        if php -r "exit(@fsockopen(getenv('DB_HOST')?:'db', (int)(getenv('DB_PORT')?:3306)) ? 0 : 1);"; then
            break
        fi
        sleep 2
    done
fi

# 3. Schema + storage symlink.
php artisan migrate --force --no-interaction
php artisan storage:link --no-interaction || true

# 4. Cache config/routes/views for production speed (safe to re-run).
php artisan optimize:clear --no-interaction
php artisan config:cache --no-interaction
php artisan route:cache --no-interaction
php artisan view:cache --no-interaction

chown -R www-data:www-data storage bootstrap/cache

exec "$@"
