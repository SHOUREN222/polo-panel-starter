# syntax=docker/dockerfile:1
#
# Frontend assets are built on the HOST (npm run build → public/build) and
# copied in — see the Makefile / README. This keeps the image build to pure
# PHP (no in-container npm, which is flaky under Docker Desktop on macOS) and
# works identically on Linux.

# ---------- Stage 1: PHP dependencies (composer, no dev) ----------
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
# scripts/artisan not needed to resolve deps; skip them for a clean vendor layer
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

# ---------- Stage 2: runtime (php-fpm) ----------
FROM php:8.4-fpm-alpine AS app

# System libs + PHP extensions the app actually uses (pdo_mysql/sqlite, bcmath
# for money math, gd/zip/intl, opcache, pcntl for the queue worker).
RUN apk add --no-cache \
        git bash icu-dev libzip-dev libpng-dev oniguruma-dev sqlite-dev \
    && docker-php-ext-configure intl \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql pdo_sqlite bcmath zip gd intl opcache pcntl \
    && rm -rf /var/cache/apk/*

# Production PHP/opcache tuning
RUN { \
        echo "opcache.enable=1"; \
        echo "opcache.enable_cli=0"; \
        echo "opcache.memory_consumption=128"; \
        echo "opcache.max_accelerated_files=20000"; \
        echo "opcache.validate_timestamps=0"; \
        echo "expose_php=0"; \
        echo "upload_max_filesize=20M"; \
        echo "post_max_size=20M"; \
    } > /usr/local/etc/php/conf.d/zz-app.ini

WORKDIR /var/www/html

# composer binary (used below to dump the optimized autoloader with full source)
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# App source (incl. host-built public/build) + resolved deps
COPY . .
COPY --from=vendor /app/vendor ./vendor

# Fail early with a clear message if the frontend wasn't built on the host.
RUN test -d public/build || { echo "ERROR: public/build is missing — run 'npm run build' (or 'make up') on the host first."; exit 1; } \
    && composer dump-autoload --no-dev --optimize --classmap-authoritative \
    && cp -R public /usr/src/public \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod +x docker/entrypoint.sh

ENTRYPOINT ["docker/entrypoint.sh"]
CMD ["php-fpm"]
