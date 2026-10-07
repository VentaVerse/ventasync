# syntax=docker/dockerfile:1.6
#
# VentaSync Cloud image
#
# Multi-stage build:
#   1. composer   — installs PHP production dependencies
#   2. assets     — builds Vite frontend assets
#   3. runtime    — final image with PHP-FPM + nginx + supervisord
#
# Bakes the two Cloud-specific behavior flags into the image so they
# can't be flipped off by a Coolify operator misconfiguration:
#
#   VENTASYNC_LICENSE_BYPASS=true   : Plus extensions skip license check
#   VENTASYNC_AUTO_MIGRATE=true     : entrypoint runs `migrate --force` and
#                                   the seed, which makes no user
#
# Per-tenant env vars (APP_KEY, APP_URL, DB credentials) are set by the
# hosting platform. The first person to open the install creates the
# administrator.

# ─── Stage 1: composer (PHP production deps) ───────────────────────────
FROM composer:2 AS composer

WORKDIR /app

COPY composer.json composer.lock ./

RUN composer install \
    --no-dev \
    --no-scripts \
    --no-autoloader \
    --no-interaction \
    --no-progress \
    --prefer-dist

# Copy source needed for autoload regeneration.
COPY app/ app/
COPY bootstrap/ bootstrap/
COPY config/ config/
COPY database/ database/
COPY extensions/ extensions/
COPY public/ public/
COPY resources/ resources/
COPY routes/ routes/
COPY artisan ./

RUN composer dump-autoload --optimize --classmap-authoritative --no-scripts \
    && rm -f bootstrap/cache/config.php bootstrap/cache/routes-v7.php bootstrap/cache/packages.php bootstrap/cache/services.php

# ─── Stage 2: assets (Vite build) ──────────────────────────────────────
FROM node:20-alpine AS assets

WORKDIR /app

COPY package.json package-lock.json* ./
RUN if [ -f package-lock.json ]; then npm ci; else npm install --no-audit --no-fund; fi

COPY resources/ resources/
COPY extensions/ extensions/
COPY vite.config.js* postcss.config.js* tailwind.config.js* ./
COPY public/build/manifest.json public/build/manifest.json

RUN npm run build || echo "vite build skipped or failed — falling back to pre-built public/build/"

# ─── Stage 3: runtime ──────────────────────────────────────────────────
FROM php:8.2-fpm-alpine AS runtime

# Install runtime dependencies + PHP extensions
RUN apk add --no-cache \
        nginx \
        supervisor \
        bash \
        tzdata \
        icu-libs \
        libpng \
        libjpeg-turbo \
        libwebp \
        freetype \
        oniguruma \
        libzip \
    && apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        icu-dev \
        libpng-dev \
        libjpeg-turbo-dev \
        libwebp-dev \
        freetype-dev \
        oniguruma-dev \
        libzip-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j$(nproc) \
        pdo_mysql \
        mbstring \
        bcmath \
        gd \
        zip \
        intl \
        opcache \
        exif \
        pcntl \
    && apk del .build-deps \
    && rm -rf /var/cache/apk/* /tmp/* /usr/local/lib/php/test /usr/local/lib/php/doc

# PHP / FPM tuning
COPY docker/php-fpm.pool.conf /usr/local/etc/php-fpm.d/www.conf

# OPcache production settings
RUN { \
        echo 'opcache.enable=1'; \
        echo 'opcache.enable_cli=0'; \
        echo 'opcache.memory_consumption=128'; \
        echo 'opcache.max_accelerated_files=20000'; \
        echo 'opcache.validate_timestamps=0'; \
        echo 'opcache.revalidate_freq=0'; \
        echo 'opcache.interned_strings_buffer=16'; \
    } > /usr/local/etc/php/conf.d/opcache.ini

# Realpath cache + memory limit
RUN { \
        echo 'memory_limit=256M'; \
        echo 'upload_max_filesize=50M'; \
        echo 'post_max_size=50M'; \
        echo 'realpath_cache_size=4096K'; \
        echo 'realpath_cache_ttl=600'; \
        echo 'expose_php=Off'; \
    } > /usr/local/etc/php/conf.d/ventasync.ini

# Nginx + supervisord
COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/supervisord.conf /etc/supervisord.conf

# Application code
WORKDIR /var/www/html

COPY --from=composer /app/vendor ./vendor
COPY --from=composer /app/bootstrap/cache ./bootstrap/cache

COPY app/ app/
COPY bootstrap/app.php bootstrap/providers.php bootstrap/
COPY config/ config/
COPY database/ database/
COPY extensions/ extensions/
COPY public/ public/
COPY resources/ resources/
COPY routes/ routes/
COPY storage/ storage/
COPY artisan composer.json composer.lock ./

# Vite-built assets (overrides whatever was in public/build/ from the COPY above)
COPY --from=assets /app/public/build public/build

# Entrypoint
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Permissions for runtime-writable dirs
RUN mkdir -p \
        storage/framework/cache \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/framework/testing \
        storage/logs \
        bootstrap/cache \
    && rm -rf "storage/framework/{cache,sessions,views}" \
    && chown -R nobody:nobody storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

# Cloud-specific behavior — baked in, cannot be overridden by Coolify env vars
# safely (the application's check_env reads ENV directives that Dockerfile baked).
ENV VENTASYNC_LICENSE_BYPASS=true \
    VENTASYNC_AUTO_MIGRATE=true \
    APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr

EXPOSE 8080

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
