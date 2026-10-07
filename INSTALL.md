# Installing VentaSync

This is a standard Laravel 12 install. If you've deployed any Laravel app before, the steps below will feel familiar. You need shell access to the server.

## Requirements

- **PHP** 8.2 or newer with extensions: `bcmath`, `ctype`, `curl`, `dom`, `fileinfo`, `gd`, `intl`, `json`, `mbstring`, `openssl`, `pcre`, `pdo_mysql`, `tokenizer`, `xml`, `zip`
- **MySQL** 8.0+ (or MariaDB 10.6+)
- **Composer** 2.x
- A web server (Apache or nginx) pointing its document root at `public/`

---

## Install

### 1. Get the code

**Option A: download the zip**

```bash
cd /var/www
wget https://github.com/VentaVerse/ventasync/archive/refs/heads/main.zip
unzip main.zip && mv ventasync-main ventasync
cd ventasync
composer install --no-dev --optimize-autoloader
```

**Option B: git clone (for development or contributing)**

```bash
git clone https://github.com/VentaVerse/ventasync.git
cd ventasync
composer install --no-dev --optimize-autoloader
```

Both paths require Composer 2.x and an outbound internet connection to packagist.

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
DB_DATABASE=ventasync
DB_USERNAME=your_db_user
DB_PASSWORD=your_db_password
```

### 3. Set permissions

```bash
chmod -R 775 storage bootstrap/cache
chown -R www-data:www-data .  # adjust user/group for your web server
```

### 4. Run migrations

```bash
php artisan migrate --force --seed
```

The seed switches on the starting extensions. It creates no user.

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

### 7. Schedule the background jobs

Every automation (order syncs, stock and price pushes, token refresh, mobile app retries and low stock alerts) runs from Laravel's scheduler. Add one entry to your hosting panel's Cron Jobs, set to every minute:

```
* * * * * php /path/to/ventasync/artisan schedule:run >> /dev/null 2>&1
```

Without it nothing on any Automations section runs, whatever it says there. How often each job runs is set on its own Automations section, with no further changes to the cron entry.

### 8. Visit your site

Open `https://your-domain.com`. The first page asks you to create the administrator account.

---

## Web server pointing

**Important: DocumentRoot must point at `public/`**, not the repo root. This is the standard Laravel deployment. Pointing at the repo root would expose `app/`, `config/`, `.env`, and other source files to the web.

**nginx example:**
```nginx
server {
    listen 80;
    server_name your-domain.com;
    root /var/www/ventasync/public;
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
cd /var/www/ventasync

# 1. Back up your database first
mysqldump -u ventasync_user -p ventasync > backup-$(date +%Y%m%d).sql

# 2. Replace files (preserving .env and storage/)
mv .env /tmp/ventasync.env.backup
rm -rf app bootstrap config database extensions public/build resources routes vendor
wget -O /tmp/ventasync.zip https://github.com/VentaVerse/ventasync/archive/refs/heads/main.zip
unzip /tmp/ventasync.zip -d /tmp/
cp -r /tmp/ventasync-main/* .
mv /tmp/ventasync.env.backup .env

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

## Need help?

- Open an issue at https://github.com/VentaVerse/ventasync/issues
- Or reach us at [ventasync.com/contact](https://ventasync.com/contact)
