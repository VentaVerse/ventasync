#!/bin/sh
#
# VentaSync Cloud container entrypoint.
#
# Runs on every container start, then hands over to supervisord, which keeps
# PHP-FPM, nginx, the scheduler and the queue worker alive. It does what a
# manual install does by hand, and what the Pterodactyl boot script
# (ventasync-boot) does for the other runtime; the two should take the same
# steps:
#
#   1. Refuses to start without the settings the app cannot run without
#   2. Waits for the database to be reachable (60s budget)
#   3. Runs migrations, and seeds until the first account exists, when
#      VENTASYNC_AUTO_MIGRATE=true
#   4. Links public/storage
#   5. Clears stale caches
#   6. Hands off to supervisord
#
# Idempotent: safe to re-run on every container restart.

set -e

cd /var/www/html

say()  { echo "[entrypoint] $*"; }
fail() { echo "[entrypoint] ERROR: $*"; exit 1; }

say "VentaSync Cloud, starting..."

# ── 1. Required settings ───────────────────────────────────────────────
#
# The image carries no .env, so every setting comes from the platform's
# environment variables. APP_KEY in particular cannot be generated here: the
# container's files are thrown away on each deploy, and a new key would sign
# everyone out and make every stored secret unreadable.

missing=""
for key in APP_KEY APP_URL DB_DATABASE DB_USERNAME DB_PASSWORD; do
    eval "value=\${$key:-}"
    [ -n "$value" ] || missing="$missing $key"
done
[ -z "$missing" ] || fail "Set these environment variables first:$missing (APP_KEY: run 'php artisan key:generate --show' anywhere and paste the result)."

# ── 2. Wait for the database ───────────────────────────────────────────

DB_HOST="${DB_HOST:-database}"
DB_PORT="${DB_PORT:-3306}"
export DB_HOST DB_PORT

say "Waiting for database at ${DB_HOST}:${DB_PORT} (max 60s)..."

# The credentials are read from the environment inside PHP, never pasted into
# the code, so a password with a quote in it still connects.
attempt=0
max_attempts=60
while [ "$attempt" -lt "$max_attempts" ]; do
    if php -r '
        try {
            new PDO("mysql:host=" . getenv("DB_HOST") . ";port=" . getenv("DB_PORT") . ";dbname=" . getenv("DB_DATABASE"),
                getenv("DB_USERNAME"), getenv("DB_PASSWORD"),
                [PDO::ATTR_TIMEOUT => 2, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            exit(0);
        } catch (Exception $e) {
            exit(1);
        }
    ' 2>/dev/null; then
        say "Database is reachable."
        break
    fi
    attempt=$((attempt + 1))
    if [ "$attempt" -eq "$max_attempts" ]; then
        fail "Database did not become reachable in ${max_attempts}s."
    fi
    sleep 1
done

# ── 3. Migrations, and the seed ────────────────────────────────────────
#
# The seed switches on the extensions a new install starts with and makes no
# user: the first person to open the install creates the administrator. It
# runs until that account exists, and leaves an extension switched off by
# hand alone.

if [ "${VENTASYNC_AUTO_MIGRATE:-false}" = "true" ]; then
    say "Running migrations..."
    php artisan migrate --force --no-interaction || fail "Migrations failed. Check the database settings."

    has_users=$(php artisan tinker --execute='echo DB::table("users")->exists() ? "yes" : "no";' 2>/dev/null | tail -n 1)
    if [ "$has_users" = "no" ]; then
        say "No account yet: seeding..."
        php artisan db:seed --force --no-interaction || fail "Seeding failed."
    fi

    # A hosted plan switches on what it newly includes, such as a bought
    # add-on. On a self-hosted install this does nothing.
    php artisan plan:apply --no-interaction || say "Could not apply the plan. Extensions are as they were."
fi

# ── 4. Link public/storage ─────────────────────────────────────────────
#
# Everything the app serves from the public disk is reached through
# public/storage: product images, the logo, generated thumbnails. The link
# cannot come from the image, because it is gitignored and because it points
# at an absolute path that belongs to whoever built it. So it is made here.
#
# It is remade on every start, not only the first: public/ is image content
# and is replaced on each deploy, while storage/app/public is usually a
# mounted volume that outlives it.

if [ ! -e public/storage ]; then
    say "Linking public/storage..."
    php artisan storage:link --no-interaction >/dev/null \
        || say "WARNING: public/storage could not be linked; pictures will not show."
fi

# ── 5. Clear stale caches (Laravel compiles on-demand) ─────────────────
#
# We don't pre-warm config:cache / route:cache / view:cache because:
#   - Cache state can mismatch across container restarts if env vars
#     change (e.g., APP_URL on domain rename).
#   - Cold-cache first-request penalty is ~50ms on modest hardware,
#     not worth the operational complexity.
#   - view:cache in particular has had issues with the extension view
#     paths during image build.

say "Clearing any stale caches..."
php artisan optimize:clear 2>/dev/null || true

# ── 6. Ensure storage + bootstrap/cache are writable ───────────────────

mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs
chown -R nobody:nobody storage bootstrap/cache 2>/dev/null || true
chmod -R 775 storage bootstrap/cache 2>/dev/null || true

say "Bootstrap complete. Handing off to supervisord."
exec /usr/bin/supervisord -c /etc/supervisord.conf
