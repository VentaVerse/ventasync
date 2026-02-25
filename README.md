# Multi-Marketplace ERP

A Laravel-based ERP system for managing products, orders, and inventory across multiple e-commerce platforms from a single dashboard.

## Overview

This ERP centralizes catalog management, order processing, stock tracking, and sales reporting for businesses selling across multiple marketplaces. It uses an OpenCart-compatible catalog schema and syncs with external platforms via their respective APIs.

### Supported Platforms

- **OpenCart** — Bi-directional sync for products, categories, manufacturers, options, orders, and stock quantities. Supports multiple OpenCart stores.
- **Lazada** — Order sync, product listing, stock push, return tracking. Uses Lazada Open Platform API.
- **Shopee** — Order sync, product listing, stock push, return tracking, logistics. Uses Shopee Partner API.
- **TikTok Shop** — Integration via TikTok Shop API.

## Features

### Catalog Management
- Products with descriptions, images, options/variants, and SKU tracking
- Categories with hierarchical paths
- Manufacturers / Brands
- Options and option values (e.g. Color, Size)
- Bulk actions (enable, disable, delete)

### Order Management
- Unified order list across all platforms with marketplace source badges
- Order detail with product breakdown, cost, profit, margin, and markup
- Order status management with configurable statuses
- Stock deduction/restoration tied to order status changes

### Inventory & Stock
- Real-time stock levels for products and option variants
- Stock History Log — full audit trail of every quantity change (manual edits, order deductions, restorations) with timestamps, order links, source tracking, and user attribution
- Automatic stock sync push to OpenCart, Lazada, and Shopee

### Reports
- **Order Profitability** — Revenue, cost, profit, margin %, markup % grouped by month or searchable
- **Product Profitability** — Per-product sales performance with sortable columns
- **Margin by Category** — Category-level profit analysis
- **Marketplace Fees** — Commission, service, payment, and shipping fees by platform
- **Inventory Valuation** — Current stock value at cost and retail
- **Product Sales History** — Per-product order history with full profit breakdown

### Dashboard
- Stat cards (products, categories, orders, revenue)
- Today's snapshot (orders, revenue, week orders)
- Sales chart with 30-day / monthly / yearly toggle
- Platform distribution pie chart
- Recent orders with marketplace source badges
- Sync status cards for OpenCart stores, Lazada, Shopee, and TikTok
- Pending order counts per platform

### User Management
- Users with role-based access via User Groups
- Granular permissions: `manage_products`, `manage_orders`, `manage_reports`, `manage_marketplace_api`, `manage_activity_log`, etc.
- Activity Log — audit trail of user actions across the system
- Last login tracking

### Other
- Global search across products, orders, categories, and manufacturers
- API endpoints for external integrations (Sanctum-authenticated)

## Tech Stack

- **Backend:** PHP 8.2+, Laravel 12
- **Database:** MySQL with OpenCart-compatible prefixed tables
- **Frontend:** Blade templates, Tailwind CSS 4, Vite 7
- **Auth:** Laravel Sanctum (API tokens), session-based (web)
- **Queue:** Database driver (for marketplace sync jobs)

## Requirements

- PHP 8.2+
- MySQL 8.0+
- Composer
- Node.js 18+ & npm

## Installation

```bash
# Clone the repository
git clone <repository-url>
cd <project-directory>

# Install dependencies and set up
composer setup
```

The `composer setup` script will:
1. Install PHP dependencies
2. Copy `.env.example` to `.env` (if not exists)
3. Generate application key
4. Run migrations
5. Install npm dependencies
6. Build frontend assets

### Manual Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
npm install
npm run build
```

## Configuration

### Environment Variables

Copy `.env.example` and configure:

```env
# Database
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=your_database
DB_USERNAME=your_username
DB_PASSWORD=your_password

# OpenCart table prefix (e.g. "oc_")
DB_TABLE_PREFIX=

# Public catalog URL for image serving
CATALOG_PUBLIC_URL=https://your-storefront.com
```

Marketplace API credentials (Lazada, Shopee, TikTok) are configured through the Settings pages within the application.

## Development

```bash
# Start dev server, queue worker, log viewer, and Vite simultaneously
composer dev
```

This runs concurrently:
- `php artisan serve` — Laravel dev server
- `php artisan queue:listen` — Queue worker for sync jobs
- `php artisan pail` — Real-time log viewer
- `npm run dev` — Vite HMR

## Project Structure

```
app/
├── Http/Controllers/       # Web and API controllers
│   ├── Api/                # REST API endpoints
│   └── Auth/               # Authentication controllers
├── Models/
│   ├── Admin/              # User groups, permissions
│   └── Catalog/            # Products, orders, categories (prefixed tables)
├── Services/
│   ├── Lazada/             # Lazada API client
│   ├── OpenCart/            # OpenCart sync services
│   ├── Shopee/             # Shopee API client
│   ├── TikTok/             # TikTok API client
│   ├── OrderStockService   # Stock deduction/restoration logic
│   ├── StockHistoryLogger  # Stock change audit trail
│   ├── ActivityLogger      # User action audit trail
│   └── SkuSyncService      # Cross-platform SKU sync
├── Rules/                  # Custom validation rules
└── View/                   # View composers

resources/views/
├── dashboard/              # Main dashboard
├── products/               # Product CRUD, sales history, stock history
├── orders/                 # Order list and detail
├── reports/                # Profitability, fees, inventory reports
├── lazada/                 # Lazada management pages
├── shopee/                 # Shopee management pages
├── opencart/               # OpenCart sync management
├── tiktok/                 # TikTok management
├── categories/             # Category management
├── manufacturers/          # Manufacturer/brand management
├── options/                # Product options management
├── users/                  # User management
├── user_groups/            # Role/permission management
├── activity_log/           # Activity audit log
├── settings/               # Application settings
└── layouts/                # App layout and sidebar
```

## License

Proprietary. All rights reserved.
