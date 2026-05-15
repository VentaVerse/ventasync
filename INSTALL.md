# Installing VentaSync Community

This is a standard Laravel 12 install. If you've deployed any Laravel app before, the steps below will feel familiar. You need shell access to the server.

## Requirements

- **PHP** 8.2 or 8.3 (8.3 recommended) with extensions: `bcmath`, `ctype`, `curl`, `dom`, `fileinfo`, `gd`, `intl`, `json`, `mbstring`, `openssl`, `pcre`, `pdo_mysql`, `tokenizer`, `xml`, `zip`
- **MySQL** 8.0+ (or MariaDB 10.6+)
- **Composer** 2.x (skip if you downloaded a release zip — `vendor/` is already bundled)
- A web server (Apache or nginx) pointing its document root at `public/`

---

## Install

### 1. Get the code

**Option A — release zip (recommended for production):**

```bash
cd /var/www
wget https://github.com/reboot-interactive/ventasync-legacy/archive/refs/heads/main.zip
unzip main.zip && mv ventasync-legacy-main ventasync-legacy
cd ventasync-legacy
```

The release zip ships with `vendor/` bundled — you can skip `composer install`.

**Option B — git clone (for development / contributing):**

```bash
git clone https://github.com/reboot-interactive/ventasync-legacy.git
cd ventasync-legacy
composer install --no-dev --optimize-autoloader
```

### 2. Configure the environment

```bash
cp .env.example .env
nano .env  # set DB_DATABASE, DB_USERNAME, DB_PASSWORD, APP_URL
php artisan key:generate
```

Key `.env` values to set:

```env
APP_URL=https://your-domain.com
APP_ENV=production
APP_DEBUG=false

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=agila_suites
DB_USERNAME=your_db_user
DB_PASSWORD=your_db_password
```

### 3. Set permissions

```bash
chmod -R 775 storage bootstrap/cache
chown -R www-data:www-data .  # adjust user/group for your web server
```

### 4. Run migrations + seed the admin user

```bash
php artisan migrate --force --seed
```

You'll see the default admin credentials printed in green at the end:

```
✓ Admin user created

  Default credentials (change immediately):

    Username: admin
    Email:    admin@admin.com
    Password: admin

  Security: change this password through Settings → Users on first login.
```

### 5. Symlink storage

```bash
php artisan storage:link
```

### 6. (Optional) Cache config for production

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

### 7. Visit your site

Open `https://your-domain.com` → log in with `admin` / `admin` → **change the password immediately** via Settings → Users.

---

## Web server pointing

**Important: DocumentRoot must point at `public/`**, not the repo root. This is the standard Laravel deployment. Pointing at the repo root would expose `app/`, `config/`, `.env`, and other source files to the web.

**nginx example:**
```nginx
server {
    listen 80;
    server_name your-domain.com;
    root /var/www/ventasync-legacy/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
    }
}
```

**Apache:** the bundled `public/.htaccess` handles routing. Just point DocumentRoot at `public/` and enable `mod_rewrite`.

---

## Upgrading

To upgrade an existing install to a new release:

```bash
cd /var/www/ventasync-legacy

# 1. Back up your database first
mysqldump -u agila_user -p agila_suites > backup-$(date +%Y%m%d).sql

# 2. Replace files (preserving .env and storage/)
mv .env /tmp/agila.env.backup
rm -rf app bootstrap config database extensions public/build resources routes vendor
wget -O /tmp/agila.zip https://github.com/reboot-interactive/ventasync-legacy/archive/refs/heads/main.zip
unzip /tmp/agila.zip -d /tmp/
cp -r /tmp/ventasync-legacy-main/* .
mv /tmp/agila.env.backup .env

# 3. Run new migrations + clear caches
php artisan migrate --force
php artisan view:clear
php artisan config:clear
php artisan route:clear
```

---

## Troubleshooting

**500 error after install:**
- Check `storage/logs/laravel.log` for the stack trace
- Verify DB credentials in `.env` and that the database exists (`mysql -u user -p -e 'show databases;'`)
- Ensure `storage/` and `bootstrap/cache/` are writable by the web server user

**"Database connection refused":**
- MySQL service running? `systemctl status mysql`
- DB user has access from `127.0.0.1`? `mysql -u user -h 127.0.0.1 -p`

**"Route not defined" after upgrade:**
- Run `php artisan route:clear` and `php artisan view:clear`

**Slow first-page load:**
- Run the production caches in step 6 above
- Make sure OPcache is enabled in PHP

**Permissions errors writing to storage:**
- Re-run step 3 (`chmod`/`chown`)
- Check `php-fpm` user matches the owner of `storage/`

---

## What's NOT included in Community

Plus tier features (paid):
- **Audit** — activity log + staff accountability
- **Reports** — order/product profitability, marketplace fees, inventory valuation
- **Shopify** — multi-store Shopify integration
- **Purchasing** — vendors, purchase orders, supplier invoices, reorder suggestions
- **OpenCart** — multi-store OpenCart connector + review sync

Each Plus extension ships as a separate folder under `extensions/{id}/` — drop it in, run `php artisan migrate --force`, done.

For Plus licensing, see [example.com](https://example.com) (TBD).

---

## Need help?

- Open an issue at https://github.com/reboot-interactive/ventasync-legacy/issues
- For Plus / Cloud support, contact `support@example.com` (TBD)
