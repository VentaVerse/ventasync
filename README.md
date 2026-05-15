# VentaSync

**Self-hosted multi-marketplace ERP** for sellers running across Lazada, Shopee, TikTok Shop, Shopify, OpenCart, Venta, and more — all from one dashboard.

Open-core distribution: the Community tier is free and AGPL-licensed; advanced features (campaigns, vouchers, advanced analytics, premium integrations) are sold as paid Plus modules.

---

## Why VentaSync

Filipino multi-channel sellers typically juggle Lazada, Shopee, TikTok, and their own webstore — three browser tabs, three sets of credentials, three reconciliation processes. VentaSync centralizes the daily operations:

- **One catalog** that pushes consistent prices, SKUs, and stock to every connected marketplace
- **One unified order list** with marketplace badges, profit per order, and stock auto-deducted
- **One reporting layer** that computes profitability across platforms
- **One purchasing workflow** for restocking, multi-currency POs, and vendor management

Built for self-hosting: you run it on your own server, you own the data, you control the upgrade cadence.

---

## Tier model

| Tier | What's included | Price |
|------|----------------|-------|
| **Community** | Core ERP + free integrations (Lazada, Shopee, TikTok, Venta, Pedallion) + warehousing + petty cash + unlimited users + free updates forever | Free, AGPL-3.0 |
| **Plus** | Everything in Community + Audit log + Purchasing (POs/vendors) + Reports (profitability/payouts) + OpenCart integration + Shopify integration + 1 year updates + email support | Paid bundle / à-la-carte |
| **Enterprise** | Everything in Plus + custom modules + dedicated support | Contact sales |

Plus modules are also available **à la carte** — buy only the integrations you need.

---

## Supported platforms (Community tier)

| Platform | Capabilities |
|----------|-------------|
| **Lazada** | Order sync (incl. returns), product listing, stock push, price push, AWB print, status mapping |
| **Shopee** | Order sync (incl. returns), product listing, stock push, price push, status mapping |
| **TikTok Shop** | Order sync, product listing, stock push, price push, status mapping |
| **Venta** | Multi-store order sync, inventory push, product groups, categories |
| **Pedallion** | Distributor API integration — product sync, orders, product groups |

Plus tier adds **OpenCart** (bi-directional sync with OpenCart 2.3.x stores), **Shopify** (multi-store), the **Audit Log** module, **Purchasing** (POs, vendors, receiving), and **Reports** (profitability, payouts, marketplace fees, inventory valuation). Available as a bundle or à-la-carte per module.

---

## Features (Community)

### Catalog
- Products with descriptions, images, options/variants, SKU tracking
- Hierarchical categories
- Manufacturers / brands
- Options and option values (e.g. Color, Size)
- Bulk actions (enable, disable, delete)

### Orders
- Unified order list with marketplace source badges
- Order detail with product breakdown, cost, profit, margin, markup
- Configurable order statuses
- Automatic stock deduction/restoration on status changes

### Inventory
- Per-product and per-option stock levels
- **Stock History Log** — full audit trail of every quantity change with timestamps, user attribution, and source (manual edit, order deduction, restoration)
- Multi-warehouse support
- Automatic stock push to connected marketplaces

### Dashboard
- Stat cards (products, categories, orders, revenue)
- Today's snapshot
- Sales chart (30-day / monthly / yearly)
- Platform distribution
- Recent orders with marketplace badges
- Per-platform pending order counts

### Users & permissions
- Role-based access via User Groups
- Granular per-feature permissions
- Activity log audit trail
- Last login tracking

### Other
- Global search across products, orders, categories, manufacturers
- Sanctum-authenticated REST API for mobile / external integrations
- Mobile API surface for marketplace order management

---

## Tech stack

- **Backend:** PHP 8.2+, Laravel 12
- **Database:** MySQL 8.0+
- **Frontend:** Blade + Vite + custom CSS (no Tailwind dependency for the core UI)
- **Auth:** Laravel Sanctum (API), session-based (web)
- **Queue:** Database driver

---

## Requirements

- PHP 8.2+ with extensions: `mbstring`, `pdo_mysql`, `zip`, `gd` or `imagick`, `xml`, `curl`
- MySQL 8.0+ (or MariaDB 10.6+)
- Composer 2+
- Node.js 18+ and npm (for asset rebuilds; pre-built assets ship in the zip)
- Web server: nginx or Apache with PHP-FPM

---

## Installation

**Drag-and-drop install on most modern hosting panels.** The Community zip ships with `vendor/` bundled and a top-level `.htaccess` shim, so you don't need SSH, composer, or DocumentRoot reconfiguration.

```
1. Download → click "Code → Download ZIP" at the top of this page
2. Upload    → put the zip in your hosting panel's public_html/
3. Extract   → right-click → Extract in cPanel / HestiaCP File Manager
4. Database  → create a MySQL database + user in your hosting panel
5. Visit     → open https://your-domain.com/ in a browser
6. Wizard    → the setup wizard appears; walk through the 6 steps
7. Login     → use the credentials you created in step 6
```

That's it. Marketplace API credentials (Lazada, Shopee, TikTok) are configured through the Settings pages inside the app after install.

For per-panel walkthroughs (HestiaCP, cPanel, CyberPanel, Plesk) and manual install via SSH, see **[INSTALL.md](INSTALL.md)**.

---

## Configuration

Required `.env` values:

```env
APP_NAME="Your Company Name"
APP_URL=https://erp.yourdomain.com

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=agila_suites
DB_USERNAME=your_user
DB_PASSWORD=your_password

MAIL_MAILER=smtp
MAIL_HOST=smtp.example.com
MAIL_PORT=587
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=erp@yourdomain.com
MAIL_FROM_NAME="${APP_NAME}"

# Catalog table prefix (defaults to 0012_)
DB_TABLE_PREFIX=0012_

# Public catalog URL for image serving (optional — only if images are hosted on a separate storefront)
CATALOG_PUBLIC_URL=https://your-storefront.com
```

---

## Cron jobs

Add to your crontab for marketplace sync and token refresh:

```
*  *    * * *   /usr/bin/php /path/to/artisan schedule:run >> /dev/null 2>&1
```

The Laravel scheduler runs the per-marketplace sync, token refresh, and stock push commands at appropriate intervals.

---

## Development

Run server, queue worker, log viewer, and Vite hot-reload concurrently:

```bash
composer dev
```

---

## Architecture

VentaSync is **modular by design**. Each marketplace integration lives under `extensions/{name}/` with its own:

- Manifest (`extension.json`)
- Service provider class
- Controllers, Models, Services
- Routes, views, migrations
- Permissions

Extensions register themselves via the `IntegrationRegistry` and contribute to core surfaces (orders list, dashboard, mobile API, layout banner) via well-defined contracts (`IntegrationProvider`, `OrderImagesContributor`, `DashboardContributor`, etc.). Adding a new marketplace requires no core code changes.

This same architecture supports **third-party developers** building their own integrations, and powers the Plus-tier paid modules.

---

## Updates

Community customers get free security and bug-fix updates for the latest major version. Major version upgrades may include migration steps documented in `UPGRADE.md`.

Plus customers receive 1 year of feature updates from purchase date.

---

## License

**AGPL-3.0-or-later.** You may use, modify, and distribute VentaSync under the terms of the GNU Affero General Public License v3.0 or later. If you modify and serve VentaSync over a network, you must make your modified source available to users.

Plus modules are distributed under separate commercial licenses. See https://example.com/pricing for details.

---

## Support

- **Community:** GitHub Issues, public forum
- **Plus:** Email support included for 1 year
- **Enterprise:** Dedicated support contracts available

Website: https://example.com
