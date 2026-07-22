# ERP External API — Build Log (branch: `erp-api`)

> Purpose: give trusted 3rd-party apps (starting with the AI assistant) **scoped, auditable read/write access** to the ERP over a token API. v1 delivers full READ; WRITE is scaffolded but locked.
>
> Rules for this build: work **only** on `erp-api`. Never touch `main` or live. Everything revertible. All decisions recorded here.

## Context (from audit)
- Laravel 12 ERP ("Xenon"). Existing `routes/api.php` uses **Sanctum** but is oriented at a mobile companion app (device tokens), not general 3rd-party integration.
- Existing permission system: roles + `User::hasPermission()` / `getEffectivePermissions()`.
- Marketplace push already works (`pushPrices()`/`pushStock()` in lazada/shopee/tiktok) — relevant for the future WRITE phase.
- Sandbox DB: local `ventasync` (safe to migrate/test).

## Design decisions
1. **Unit of access = "API Application"** — a named client with a scoped Sanctum token. Managed in **Settings → API** (admin-gated).
2. **Scopes** as `resource:action`, e.g. `products:read`, `products:write`, `reports:read`, `orders:read`, `inventory:read/write`. Stored as Sanctum token **abilities**.
3. **Two-layer defense**: API routes require `auth:sanctum` + `abilities:<scope>`; write scopes simply won't be granted to the AI token in v1.
4. **Token shown once** on creation (hashed at rest, GitHub-PAT style).
5. **Audit log**: every API request records app, route, scope, IP, timestamp.
6. v1 READ built fully; WRITE endpoints scaffolded + scope-gated, not granted.

## Task checklist
- [ ] Permissions/scope catalog (read + write)
- [ ] `api_clients` + request-log migrations & models
- [ ] Settings → API management UI (list / create with R/W/None matrix / revoke)
- [ ] Token issuance (Sanctum abilities) + one-time reveal
- [ ] Read endpoints: products, product detail, sales report, orders, inventory
- [ ] Write endpoints scaffolded + `*:write` gated (not granted)
- [ ] Rate limiting + audit-log middleware
- [ ] Seed `ai-agent` application (read-only)
- [ ] Tests (feature) for auth, scope enforcement, read output
- [ ] Update this log per step

## Change log
- Created branch `erp-api` off `main`. Verified sandbox DB is local. (start)
- Foundation: scopes catalog, `api_clients` + `api_request_logs` tables/models. (commit 73e4e88)
- Auth chain: `scope` + `api.audit` middleware, `GET /api/v1/products` + `/{id}`.
  Verified end-to-end (401/403/200 + audit rows) on sandbox. (commit a3a6ebb)
- Reports: `ProductProfitReportService` (canonical profit query, single source
  of truth) + `GET /api/v1/reports/products` (scope reports:read). Verified
  against sandbox (607 SKUs, matches web-report shape) + scope isolation
  (reports↔products tokens mutually 403). All test clients/tokens/logs purged.
- API-SPEC.md added at repo root (docs/ is gitignored). Recorded operator
  decisions: token expiry (date/none), rotation, no rate limiting.
- Settings → API UI: `view/manage_api_clients` permissions (config +
  lang + synced), `ApiClientController` (index/store/rotate/destroy),
  routes under /settings/api, sidebar nav link, `settings/api/index`
  view (scope R/W matrix, expiry picker, one-time token reveal, rotate/
  revoke, audit-activity panel). Verified via real UI on sandbox: page
  renders natively; create issues scoped token; rotate swaps token
  (88→89); expiry-dated create stored 2027-01-01 23:59:59; revoke deletes
  client. Temp login route + all test data removed after.

- Modular refactor (decouple API from core): added `ApiScopeRegistry`
  (aggregates core config + extension manifest `api_scopes`), added
  `routes/api.php` loading to `ExtensionProvider::bootRoutes` (mounts under
  /api/v1 with auth:sanctum + api.audit). Moved the profit report out of
  core into the reports extension: `extensions/reports/Services/
  ProductProfitReportService`, `.../Controllers/Api/ReportApiController`,
  `.../routes/api.php`, `reports:read` scope in extension.json. Removed core
  `Api/V1/ReportController` + `app/Services/Reports/*` + core reports route.
  Verified: core has 0 channel-table refs (mine); reports endpoint served via
  extension loader returns real data; audit + scope middleware apply to
  extension routes; products/orders/inventory unaffected; registry lists
  products/orders/inventory/reports. Pre-existing mobile-API channel coupling
  (Api/OrderController, Api/DashboardController) noted, out of scope.

## Follow-ups (tracked, not forgotten)
- Migrate web `extensions/reports` ReportController::products() onto
  `ProductProfitReportService` so web + API can never diverge (needs a
  logged-in web-path verification before switching).
- Remaining v1: orders + inventory read endpoints; Settings → API management
  UI (create app → R/W matrix → issue token → revoke); seed `ai-agent`
  read-only client; feature tests; write-scope scaffolding (locked).
