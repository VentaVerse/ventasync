# Installing VentaSync

Step-by-step install guide for the major shared-hosting panels. Pick the section that matches your host.

If you're on a dedicated VPS without a panel, see [Manual install via SSH](#manual-install-via-ssh) at the bottom.

## ⚠️ Important: don't change DocumentRoot

VentaSync ships with a **top-level `.htaccess`** and an **`index.php` shim** at the repo root that automatically route requests into `public/`. This is what makes drag-and-drop install possible — you don't need to change your hosting panel's DocumentRoot.

**If you DO change DocumentRoot to `public_html/public/`** (a common Laravel-deployment instinct), Apache will hit `public/public/...` because the root `.htaccess` adds an extra `public/` prefix → **500 error**.

**Pick ONE of these patterns. Not both:**

| Pattern | DocumentRoot | Root `.htaccess` + `index.php` |
|---|---|---|
| **Drag-and-drop (recommended for shared hosting)** | Leave at panel default (`public_html/`) | Keep them as shipped |
| **Standard Laravel (if you control DocumentRoot)** | Change to `public_html/public/` | Delete the root `.htaccess` and root `index.php` |

If you saw a 500 error after install and you'd changed DocumentRoot, that's almost certainly the cause. Revert DocumentRoot to the panel default OR delete the two root files.

---

## Prerequisites

Whichever path you take, your hosting account needs:

| Requirement | Note |
|---|---|
| PHP 8.2 or later | Check via panel → PHP Manager. Most modern panels default to 8.2 or 8.3. |
| MySQL 8.0+ or MariaDB 10.6+ | Standard on every shared host. |
| PHP extensions enabled | `pdo_mysql`, `mbstring`, `bcmath`, `gd`, `zip`, `intl`, `exif`, `xml`. All standard. |
| Domain pointed at your server | Either a subdomain (`erp.your-business.com`) or a domain you own. DNS A record → server IP. |
| ~150 MB free disk space | Plus space for your catalog/orders. |

The installer wizard's **Requirements** step (step 2) auto-detects all of the above and reports what's missing. If something fails the check, fix it with your panel's PHP config UI before continuing.

## Download

1. Open this repo: **[github.com/reboot-interactive/ventasync-legacy](https://github.com/reboot-interactive/ventasync-legacy)**
2. Click the green **Code** button → **Download ZIP**
3. Save the file (it's about 30 MB). The filename will be something like `ventasync-legacy-main.zip`.

That's the entire codebase, including all PHP dependencies (`vendor/`) and pre-built CSS/JS assets (`public/build/`). No composer or npm needed.

---

## Install on HestiaCP

HestiaCP is one of the smoothest panels for this — its DocumentRoot config is friendly, and Let's Encrypt SSL is one click.

### 1. Set up the domain in HestiaCP

1. Log in to HestiaCP admin
2. **Web** → **Add Web Domain**
3. Domain: `erp.your-business.com` (or whatever you registered)
4. Tick **Enable SSL Support** → tick **Use Lets Encrypt to obtain SSL Certificate**
5. Save

HestiaCP creates the nginx vhost, requests the cert, and configures DNS records (if DNS is also managed here).

> **⚠️ Don't change "Document Root" in the Web Domain advanced settings.** Leave it at the HestiaCP default. VentaSync' root `.htaccess` handles the routing. See the [DocumentRoot warning at the top of this file](#-important-dont-change-documentroot) if you're tempted to point it at `/public`.

### 2. Upload the zip

Two options:

**Option A — File Manager (easiest):**
1. Click the **Files** icon next to your domain (or go to **File Manager**)
2. Navigate to `public_html/`
3. Click **Upload** → select your downloaded zip
4. After upload, right-click the zip → **Extract**

**Option B — SFTP:**
1. Get SFTP credentials from your HestiaCP user page
2. Use FileZilla / WinSCP / Cyberduck to connect
3. Upload + extract the zip into `/home/<user>/web/<domain>/public_html/`

After extracting, `public_html/` should contain: `app/`, `config/`, `extensions/`, `public/`, `vendor/`, `index.php`, `.htaccess`, and the rest of the codebase.

### 3. Create a MySQL database

1. **Databases** → **Add Database**
2. Database name: `erp` (HestiaCP will prefix with your username, e.g., `youruser_erp`)
3. Username: `erp` (becomes `youruser_erp`)
4. Password: generate a strong one — **save it somewhere**, you'll need it in step 5
5. Save

### 4. Set file permissions (if needed)

HestiaCP usually gets this right by default. If not:

1. File Manager → select `storage/` and `bootstrap/cache/`
2. Right-click → **Permissions** → set to `755` (or `775` if your group needs write)
3. Repeat recursively (the dialog has a "Apply to all" toggle)

Symptom of wrong permissions: install wizard step 2 (Requirements) fails on "storage writable" check.

### 5. Visit the site → walk the wizard

1. Open `https://erp.your-business.com/` in a browser
2. The installer wizard appears at `/install/welcome`
3. Walk through 6 steps:
   - **Welcome** — read, click Continue
   - **Requirements** — should be all green; fix anything red and refresh
   - **Database** — enter the credentials from step 3 above. Click Continue. The wizard writes `.env` and runs migrations (~20 sec).
   - **Site Config** — your business name, timezone, default currency
   - **Admin** — create your first admin user (this becomes your login)
   - **Finish** — installer marks the install complete and redirects you to `/login`

4. Log in with the credentials you set in step 5.

Total time: ~5 minutes if you have your DB password ready.

---

## Install on cPanel

cPanel is similar to HestiaCP but with different menu locations.

### 1. Set up the subdomain (if needed)

1. cPanel home → **Subdomains** (under Domains section)
2. Subdomain: `erp` → for domain: pick yours
3. Document Root: `/public_html/erp` (default — leave as-is)
4. Save

OR use the main domain — in that case skip this step and upload into `public_html/` directly.

### 2. Enable SSL

1. cPanel home → **SSL/TLS Status** (or **AutoSSL** depending on host)
2. Find your domain/subdomain → click **Run AutoSSL**
3. Wait 1-2 minutes. The cert is issued automatically.

If AutoSSL isn't available, ask your host or use the free Let's Encrypt option in cPanel.

### 3. Upload the zip

1. cPanel → **File Manager**
2. Navigate to `public_html/` (or `public_html/erp/` if you made a subdomain)
3. Top toolbar → **Upload** → select your zip
4. After upload, right-click the zip in File Manager → **Extract** → confirm

### 4. Create the database

1. cPanel home → **MySQL® Databases**
2. **Create New Database**: `erp` (cPanel prefixes with your account, e.g., `youracct_erp`)
3. **Add New User**: username `erp`, strong password (save it)
4. **Add User To Database**: select your user + your db → grant **ALL PRIVILEGES**

### 5. Visit the site + wizard

Same as HestiaCP step 5 above — open the URL, walk the wizard.

---

## Install on CyberPanel

CyberPanel uses OpenLiteSpeed by default and has slightly different paths.

### 1. Add the website

1. CyberPanel admin → **Websites** → **Create Website**
2. Domain: `erp.your-business.com`
3. PHP version: 8.2+
4. SSL: tick **Issue SSL**
5. Save

### 2. Upload via File Manager

1. **Websites** → **List Websites** → click **Manage** next to your domain
2. **File Manager**
3. Navigate to `/public_html/`
4. Click **Upload** → select your zip
5. After upload, click the zip → **Extract**

### 3. Create the database

1. **Databases** → **Create Database**
2. Database name: `erp` (auto-prefixed)
3. Username + password: set securely

### 4. Visit + wizard

Same as above.

### CyberPanel gotcha

OpenLiteSpeed sometimes ignores `.htaccess` directives. If you see the wizard but it can't write the `.env` file (step 3 fails), check that the storage directory is writable. From the CyberPanel terminal:

```bash
cd /home/yourdomain.com/public_html
chmod -R 755 storage bootstrap/cache
```

---

## Install on Plesk

Plesk is more click-heavy but works fine.

### 1. Add the domain

1. Plesk admin → **Websites & Domains** → **Add Subdomain** (or **Add Domain**)
2. Enter `erp` (or your full domain)
3. Document root: default (`erp.your-business.com/`)
4. After saving, click the domain → **SSL/TLS Certificates** → **Install** (Let's Encrypt option)

### 2. Upload + extract

1. Click the domain → **Files**
2. Navigate to `httpdocs/` (Plesk's default web root)
3. **Upload Files** → select zip
4. After upload, right-click → **Extract Files**

### 3. Create database

1. Domain page → **Databases** → **Add Database**
2. Type: MySQL
3. Name: `erp_db`, user: `erp_user`, password: strong
4. Save

### 4. Visit + wizard

Same as above.

---

## Manual install via SSH

For dedicated VPS users without a panel.

### Prerequisites

- SSH access with sudo
- nginx or Apache configured
- PHP 8.2-FPM running
- MySQL 8.0+ running
- A domain pointed at your server IP

### Step-by-step

```bash
# 1. Clone or download the source
cd /var/www
git clone https://github.com/reboot-interactive/ventasync-legacy.git
cd ventasync-legacy

# 2. (Optional, only if you cloned via git — the GitHub ZIP already includes vendor/)
composer install --no-dev --optimize-autoloader

# 3. Set permissions
chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache

# 4. Create the MySQL database
mysql -uroot -p
> CREATE DATABASE agila_suites CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
> CREATE USER 'agila'@'localhost' IDENTIFIED BY 'STRONG_PASSWORD_HERE';
> GRANT ALL PRIVILEGES ON agila_suites.* TO 'agila'@'localhost';
> FLUSH PRIVILEGES;
> EXIT;

# 5. Configure nginx (example — adjust to your setup)
# Point document root to /var/www/ventasync-legacy/public/
# OR if you can't change DocumentRoot, leave it at /var/www/ventasync-legacy and
# let the included .htaccess + index.php shim handle routing

# 6. Visit the site
# https://your-domain.com/  → wizard appears
# Walk through the 6 steps (DB credentials from step 4)
```

---

## Post-install checklist

After the wizard completes and you log in:

| | |
|---|---|
| ☐ Change the admin email if it differs from your real one | Settings → Users → your user |
| ☐ Set your timezone | Settings → Website Settings |
| ☐ Configure SMTP | Settings → Website Settings → Email |
| ☐ Set up cron jobs (marketplace sync, token refresh) | See [Cron jobs](#cron-jobs) below |
| ☐ Connect your first marketplace | Integrations → Lazada / Shopee / TikTok |

### Cron jobs

Add to your panel's cron / crontab so the Laravel scheduler runs marketplace sync, token refresh, etc:

```
* * * * * php /full/path/to/ventasync-legacy/artisan schedule:run >/dev/null 2>&1
```

In HestiaCP: **Cron** → **Add Cron Job** → `* * * * *` with the command above.
In cPanel: **Cron Jobs** → set "Every minute" → paste command.
In Plesk: **Domain** → **Scheduled Tasks** → **Add Task**.

The scheduler handles per-marketplace OAuth token refresh, periodic order pulls (if configured), and other maintenance.

---

## Troubleshooting

### Wizard step 2 (Requirements) shows red items

| Failure | Fix |
|---|---|
| PHP version too old | Switch PHP version in your panel's PHP Manager to 8.2 or 8.3 |
| Missing extension (`mbstring`, `gd`, etc.) | Enable in panel's PHP extension manager and restart PHP-FPM |
| `storage` not writable | Set `storage/` and `bootstrap/cache/` to `755` or `775`, recursive |

### "Could not connect to database" on step 3

- Double-check the DB host (usually `localhost` or `127.0.0.1`)
- Double-check the username has the prefix your panel added (e.g., `youruser_erp` not just `erp`)
- Verify the user has ALL PRIVILEGES on the database (cPanel/HestiaCP usually do this automatically; older Plesk doesn't)

### 500 error visiting the URL

- Check the web server error log (HestiaCP: **Log** tab; cPanel: **Errors** at the bottom of cPanel home)
- Most common cause: `storage/` not writable. See above.
- Second most common: PHP version mismatch (your domain is using a different PHP version than what extensions/composer.json require)

### Browser shows `/install` but nothing renders

- View page source — if it's a Laravel error stack, the error message at the top tells you what's broken.
- If it's a blank page with no error, check the PHP error log.

### Install completes but `/login` redirects to `/install` again

This means the installer marker file (`storage/agila/installed.lock`) wasn't created — probably a permissions issue. Workaround: manually create the file via SSH or File Manager:

```bash
echo "manual" > storage/agila/installed.lock
```

Then visit `/login` again. The middleware will see the file and stop redirecting.

### Mixed-content errors after install (HTTPS site loading HTTP assets)

This means your site is behind a reverse proxy (Cloudflare, nginx in front, etc.) and Laravel can't tell the request was HTTPS. The shipped `App\Http\Middleware\TrustProxies` has `protected $proxies = '*'` which usually fixes this. If you still see issues, check `APP_URL` in `.env` — it must start with `https://`.

---

## Need help?

| Channel | For |
|---|---|
| **[GitHub Issues](https://github.com/reboot-interactive/ventasync-legacy/issues)** | Bugs, feature requests, install questions |
| **[hello@example.com](mailto:hello@example.com)** | Plus-tier purchases, partnership, commercial licensing |

Pull requests welcome. For substantial features, open an issue first to discuss.
