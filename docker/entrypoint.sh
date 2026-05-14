#!/bin/sh
#
# VentaSync Cloud container entrypoint.
#
# Runs once before supervisord (which then keeps PHP-FPM + nginx alive).
# Does the per-tenant bootstrap that on self-hosted installs would be
# handled by the customer typing through the /install wizard:
#
#   1. Waits for the database to be reachable (60s budget)
#   2. Runs migrations if AGILA_AUTO_MIGRATE=true
#   3. Creates the first admin user from AGILA_ADMIN_EMAIL +
#      AGILA_ADMIN_PASSWORD_HASH if AGILA_INSTALL_BYPASS=true and no
#      users exist yet
#   4. Caches config / routes / views
#   5. Hands off to supervisord
#
# Designed to be idempotent — safe to re-run on every container restart.

set -e

cd /var/www/html

echo "[entrypoint] VentaSync Cloud — starting..."

# ── 1. Wait for the database ───────────────────────────────────────────

DB_HOST="${DB_HOST:-database}"
DB_PORT="${DB_PORT:-3306}"

echo "[entrypoint] Waiting for database at ${DB_HOST}:${DB_PORT} (max 60s)..."

attempt=0
max_attempts=60
while [ "$attempt" -lt "$max_attempts" ]; do
    if php -r "
        try {
            new PDO('mysql:host=${DB_HOST};port=${DB_PORT};dbname=${DB_DATABASE}', '${DB_USERNAME}', '${DB_PASSWORD}', [PDO::ATTR_TIMEOUT => 2, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            exit(0);
        } catch (Exception \$e) {
            exit(1);
        }
    " 2>/dev/null; then
        echo "[entrypoint] Database is reachable."
        break
    fi
    attempt=$((attempt + 1))
    if [ "$attempt" -eq "$max_attempts" ]; then
        echo "[entrypoint] ERROR: Database did not become reachable in ${max_attempts}s. Exiting."
        exit 1
    fi
    sleep 1
done

# ── 2. Run migrations ──────────────────────────────────────────────────

if [ "${AGILA_AUTO_MIGRATE:-false}" = "true" ]; then
    echo "[entrypoint] Running migrations (AGILA_AUTO_MIGRATE=true)..."
    php artisan migrate --force --no-interaction
fi

# ── 3. Install bypass (Cloud-only admin bootstrap) ─────────────────────

if [ "${AGILA_INSTALL_BYPASS:-false}" = "true" ]; then
    echo "[entrypoint] Running install bypass (AGILA_INSTALL_BYPASS=true)..."
    php artisan agila:install-bypass --no-interaction
fi

# ── 4. Clear stale caches (Laravel compiles on-demand) ────────────────
#
# We don't pre-warm config:cache / route:cache / view:cache because:
#   - Cache state can mismatch across container restarts if env vars
#     change (e.g., APP_URL on domain rename).
#   - Cold-cache first-request penalty is ~50ms on modest hardware —
#     not worth the operational complexity.
#   - view:cache in particular has had issues with the extension view
#     paths during image build.
#
# If perf becomes a concern, we re-introduce the cache steps here under
# a flag (AGILA_CACHE_ON_BOOT=true) and ensure they're robust.

echo "[entrypoint] Clearing any stale caches..."
php artisan optimize:clear 2>/dev/null || true

# ── 5. Ensure storage + bootstrap/cache are writable ──────────────────

chown -R nobody:nobody storage bootstrap/cache 2>/dev/null || true
chmod -R 775 storage bootstrap/cache 2>/dev/null || true

echo "[entrypoint] Bootstrap complete. Handing off to supervisord."
exec /usr/bin/supervisord -c /etc/supervisord.conf
