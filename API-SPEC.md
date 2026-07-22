# External API — Specification

> Status: **draft / in progress** (branch `erp-api`, local only — not pushed).
> Owner: The owner. Last updated: 2026-07-22.
> Companion doc: `BUILD_LOG.md` (chronological build record). This file is the
> **contract**; the build log is the **diary**.

## 1. Purpose & background

The ERP already exposes a Sanctum-based API oriented at a **mobile companion
app** (`routes/api.php`, device tokens). It does **not** offer a general,
scoped way for trusted 3rd-party applications to read/write ERP data.

This spec defines an **inbound external API** so approved apps — first the AI
pricing/reporting assistant — can access ERP data under least-privilege,
fully auditable, and revocable credentials.

Direction of integration: **3rd-party → reads/writes ERP** (the inverse of the
existing ERP → marketplace push integrations).

## 2. Goals / non-goals

**Goals**
- Named "API Applications", each with a scoped token, managed in **Settings → API**.
- Per-resource **Read / Write / None** permission matrix.
- Full **audit trail** of every external API call.
- v1 delivers **READ**; WRITE is modelled + scaffolded but **not granted**.
- Zero changes to the existing mobile API behaviour.

**Non-goals (v1)**
- No public/unauthenticated endpoints.
- No automatic price writing (that is a later, guardrailed phase).
- No OAuth authorization-code flow — bearer tokens issued in-app are sufficient.

## 3. Access & security model

- **Unit of access = `ApiClient`** (`app/Models/ApiClient.php`), the token owner,
  kept separate from human/mobile user tokens.
- **Auth**: Sanctum bearer token (`Authorization: Bearer <token>`), `auth:sanctum`.
- **Scopes** = Sanctum token abilities, formatted `resource:action`
  (e.g. `products:read`, `reports:read`). Catalog: `config/api_scopes.php`.
- **Enforcement**: `scope:<scope>` middleware (`EnsureApiScope`) →
  `tokenCan()`; missing scope → `403`.
- **Audit**: `api.audit` middleware (`LogApiRequest`, terminable) writes one
  `api_request_logs` row per call (client, method, path, scope, status, ip,
  time) and bumps `ApiClient.last_used_at`. Failure to log never breaks a call.
- **Token storage**: hashed at rest (Sanctum default); plaintext shown once at
  creation.
- **Token expiry**: optional per token, chosen at creation — an explicit expiry
  **date**, or **no expiry**. Backed by `personal_access_tokens.expires_at`
  (already present); Sanctum treats a token as valid iff `expires_at` is null or
  in the future. Global `sanctum.expiration` stays `null` (per-token control).
- **Token rotation**: an application's token can be rotated from the UI — the
  old token is revoked and a new one issued with the **same scopes and same
  expiry policy**; new plaintext shown once. Any client using the old token
  immediately gets 401.
- **Transport**: HTTPS only in production. **No rate limiting** (operator
  decision, 2026-07-22).
- Hard rule for this build: **branch `erp-api` only, never `main`, never live,
  never pushed to the git remote.**

## 4. Data & permission model

### 4.1 Scope catalog (`config/api_scopes.php`)
| Resource   | Actions       | Notes                                             |
|------------|---------------|---------------------------------------------------|
| products   | read, write   | Catalog + cost/price/qty/per-channel markup       |
| reports    | read          | Aggregated sales/profit (read-only by nature)     |
| orders     | read, write   | Order headers, items, status                      |
| inventory  | read, write   | Stock levels / quantity adjustments               |

The Settings → API matrix renders one row per resource with Read / Write
checkboxes; a checked box grants `{resource}:{action}`. Unchecked = no access.

### 4.2 New tables
- **`api_clients`** — id, name, description, active, created_by, last_used_at, timestamps.
- **`api_request_logs`** — id, api_client_id, method, path, scope, status, ip, created_at.
- Tokens live in the existing `personal_access_tokens` (tokenable = `ApiClient`),
  `abilities` = granted scopes.

## 5. Endpoints (contract)

Base path: `/api/v1`. All require `auth:sanctum` + `api.audit`; each route also
requires the listed scope. All responses JSON.

### Implemented (v1)
| Method | Path                     | Scope           | Description |
|--------|--------------------------|-----------------|-------------|
| GET    | `/products`              | `products:read` | Paginated products: product_id, name, model, sku, price, cost, quantity, status. Query: `search`, `per_page` (≤100). |
| GET    | `/products/{id}`         | `products:read` | Single product (404 if missing). |
| GET    | `/reports/products`      | `reports:read`  | Per-product profitability over a date range: units, revenue, COGS, shipping, fees, net_profit, margin, markup + summary. Query: `date_from`, `date_to`, `search`, `source[]`, `status[]`, `sort`, `dir`, `per_page` (≤200). Backed by `ProductProfitReportService`. |

### Implemented (v1) — orders & inventory
| Method | Path             | Scope            | Description |
|--------|------------------|------------------|-------------|
| GET    | `/orders`        | `orders:read`    | Paginated orders: channel, status, customer, total, line/unit counts. Query: `date_from`, `date_to`, `status`, `source`, `search`, `per_page`. |
| GET    | `/orders/{id}`   | `orders:read`    | Order header + line items (name, qty, price, total, cost). |
| GET    | `/inventory`     | `inventory:read` | Stock vs. reorder level: quantity, reorder_level, is_low, below_by, cost, price. Query: `low_stock` (≤ reorder_level), `threshold` (≤ N), `search`, `per_page`. NOTE: `low_stock` needs product `reorder_level` set (currently 0 across catalog) — use `threshold` until reorder levels are populated. |

### Scaffolded but LOCKED (write phase — not granted in v1)
| Method | Path                       | Scope              | Description |
|--------|----------------------------|--------------------|-------------|
| PATCH  | `/products/{id}/price`     | `products:write`   | Update base price (guardrails: min-margin floor, max % change). |
| PATCH  | `/inventory/{id}`          | `inventory:write`  | Adjust quantity. |

### Error shapes
- `401 {"message":"Unauthenticated."}` — missing/invalid token.
- `403 {"message":"Missing required scope: <scope>"}` — token lacks scope.
- `404 {"message":"<resource> not found."}`
- `422` — Laravel validation errors.

## 6. Settings → API (management UI)

Admin-gated page to:
1. List API Applications (name, scopes, last used, expiry, active).
2. Create an application → name + Read/Write/None matrix + **expiry choice
   (date or "no expiry")** → **issue token (shown once)**.
3. **Rotate** an application's token (revoke old, issue new with same
   scopes + expiry; new plaintext shown once).
4. Revoke / deactivate an application.
5. View recent `api_request_logs` per application (the audit view).

## 7. Rate limiting & throttling
- **None.** The external API is not rate limited (operator decision,
  2026-07-22). The default framework `api` throttle still applies to the
  legacy mobile routes but is not added to the `/v1` group.

## 8. Rollout phases
1. **Read-only (v1, current)** — endpoints above; AI granted read scopes only.
   AI pulls data, recommends, DMs the operator. No write ability.
2. **Write, human-approved** — write endpoints unlocked; changes proposed →
   operator approves → applied.
3. **Autonomous write** — guardrailed auto-apply once trust is established.

## 9. Acceptance criteria (v1)
- [x] Unauthed request → 401; wrong scope → 403; correct scope → 200 (verified).
- [x] Every call recorded in `api_request_logs` (verified).
- [x] `/products` returns real catalog incl. cost/price (verified, ~2081 rows).
- [x] `/reports/products` matches web-report figures (verified on sandbox).
- [x] Orders + inventory read endpoints (verified on sandbox).
- [x] Settings → API UI: create/revoke app + issue token (verified via UI).
- [x] Token expiry (date or none) selectable at creation (verified).
- [x] Token rotation (revoke + re-issue, same scopes/expiry) (verified).
- [ ] Seed `ai-agent` read-only application.
- [ ] Feature tests: auth, scope isolation, expiry, output shape.
- [ ] Write endpoints scaffolded + locked (scope not grantable to AI in v1).

## 10. Decisions & open questions
**Decided (2026-07-22):** token expiry = optional (date or none); rotation =
yes; rate limiting = none.

**Open:**
- Which write endpoints matter first once we reach phase 2 (price vs stock)?
- Should the audit log be exportable / retained for N days then pruned?
