# Cloud Provisioning — VentaSync

How Coolify deploys a new VentaSync Cloud tenant. Internal operations doc — not customer-facing. Excluded from all build artifacts.

## The image

| | |
|---|---|
| Registry | `ghcr.io/reboot-interactive/ventasync-legacy` |
| Latest tag | `v0.1.0` (not yet pushed — Dockerfile + Actions workflow pending) |
| Visibility | TBD — public or private. If private, Coolify needs a `READ:PACKAGES` PAT from a `reboot-interactive` org member |
| Architecture | `linux/amd64` (and `linux/arm64` if you provision ARM VPS for cheaper tenants) |
| Estimated size | ~250 MB compressed |

## Env vars to set in Coolify

### Baked into image (do NOT override)

These are set by the Dockerfile's `ENV` directives. Coolify does nothing with them — they're immutable per image build.

```
AGILA_LICENSE_BYPASS=true     # Cloud has no license check; bypass is build-time, not runtime
AGILA_AUTO_MIGRATE=true       # Run `php artisan migrate --force --seed` on container start
```

The seeded admin (admin/admin) is rotated by AgilaCP provisioning before the tenant is exposed to the operator — see the provisioning spec below.

### Required per-tenant (Coolify sets these)

| Var | Source / How to generate |
|---|---|
| `AGILA_TENANT_ID` | AgilaCP `tenants.id` (integer, auto-increment). Required, read-only post-provisioning. |
| `APP_NAME` | Customer's company name (e.g., `"Acme Trading"`) |
| `APP_URL` | `https://{subdomain}.example.com` or custom domain |
| `APP_KEY` | Generated once at provisioning. `php artisan key:generate --show` once, persist in AgilaCP's `tenants` row, never change |
| `DB_HOST` | The MySQL container's hostname inside Coolify (usually the docker-compose service name, e.g., `database`) |
| `DB_PORT` | `3306` |
| `DB_DATABASE` | `tenant_{AGILA_TENANT_ID}` |
| `DB_USERNAME` | `tenant_{AGILA_TENANT_ID}` |
| `DB_PASSWORD` | Random 32-char string. AgilaCP generates and persists. |

### Optional per-tenant

```
MAIL_HOST=         # If empty, falls back to AgilaCP's shared transactional SMTP
MAIL_PORT=587
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_FROM_ADDRESS=
APP_DEBUG=false    # Default — only flip to true during incident response
LOG_LEVEL=warning  # Default
```

## Manual provisioning checklist (the owner, today)

When you onboard a Cloud customer manually before the AgilaCP-Coolify integration ships:

1. **Login to Coolify admin** at your Coolify URL
2. **Create a new application:**
   - Name: `agila-tenant-{AGILA_TENANT_ID}`
   - Source: Docker image
   - Image: `ghcr.io/reboot-interactive/ventasync-legacy:v0.1.0`
3. **Add a MySQL database service:**
   - Click "Add Service" → MySQL 8.0
   - Database name: `tenant_{AGILA_TENANT_ID}`
   - User: `tenant_{AGILA_TENANT_ID}`
   - Password: click "Generate" and persist it in your tenant record
4. **Set the env vars** from the "Required per-tenant" table above
5. **Configure the domain:**
   - Coolify → Application → Domains
   - Add: `{subdomain}.example.com`
   - Enable "Generate SSL Certificate"
6. **Click Deploy**
7. **Wait ~30-60 seconds** for the container to start
8. **Rotate the seeded admin password** (the container booted with admin/admin from the seeder):
   - `coolify exec {container} php artisan tinker --execute='\App\Models\User::where("username","admin")->update(["email"=>"customer@email.com","password"=>bcrypt("their-chosen-pw")]);'`
   - Confirm by logging in once yourself with the new credentials
9. **Verify:**
   - Visit `https://{subdomain}.example.com`
   - Login as the customer's admin email + their chosen password
10. **Email the customer** their URL + login confirmation

Total time per tenant: ~5 minutes once you have a Coolify template you can clone.

## Automated provisioning spec (AgilaCP-Coolify integration, later)

When AgilaCP's Coolify integration is ready, the flow becomes:

```
1. Customer pays for Cloud subscription via AgilaCP checkout
2. AgilaCP webhook: customer.subscribed
3. AgilaCP creates tenants row → gets tenant_id (auto-increment INT)
4. AgilaCP calls Coolify API (in order):
   - POST /api/v1/applications        (image=ghcr.io/reboot-interactive/ventasync-legacy:vX.Y.Z)
   - POST /api/v1/services            (mysql, name=tenant_{id})
   - POST /api/v1/environment-variables  (the table above, one POST per var or batched)
   - POST /api/v1/domains             (subdomain mapping)
   - POST /api/v1/deploy              (triggers the first deploy — container seeds admin/admin)
5. AgilaCP polls Coolify for deploy status (every 5s, max 2 min timeout)
6. AgilaCP rotates the admin password (via Coolify exec + tinker) to the customer's chosen credentials
7. AgilaCP emails customer when deploy succeeds (or alerts the owner on failure)
```

**Coolify API docs:** https://coolify.io/docs/api — the AgilaCP-side Claude session works from there.

## First-boot behavior (what the container does on launch)

The Dockerfile's entrypoint script handles all post-deploy steps — Coolify does NOT need to run any custom hooks:

```
1. Container starts → PHP-FPM + Nginx booting
2. Entrypoint script runs:
   a. Wait for DB to be reachable (up to 60s, fail container if not)
   b. If AGILA_AUTO_MIGRATE=true:
      → php artisan migrate --force --seed
        (creates Administrator user_group via migrations,
         seeds default admin/admin user via DatabaseSeeder)
   c. Clear stale caches
3. Hand off to PHP-FPM + Nginx
4. Container is now serving requests with admin/admin credentials
5. AgilaCP provisioning step rotates the admin password to the
   customer's chosen credentials BEFORE the URL is sent to the customer
```

If migrations fail: container exits with non-zero status. Coolify marks deployment as failed, AgilaCP sees the alert and emails the owner.

## Tenant lifecycle

| Event | What happens |
|---|---|
| **Signup** | AgilaCP charges, provisions Coolify container as above. |
| **Version upgrade (v0.2.0+)** | AgilaCP triggers Coolify "Redeploy" with new image tag. Each tenant rolls forward one-by-one; AgilaCP can pause/resume the rollout. |
| **Customer cancels** | AgilaCP marks tenant as `cancelled`, schedules container deletion in 30 days. DB export auto-emailed to customer before deletion. |
| **Customer fails payment** | AgilaCP sets `subscriptions.status=past_due`, container keeps running for 14 days as grace period, then stops via Coolify API. Restarting requires payment + AgilaCP redeploy call. |
| **Customer requests data export** | Coolify "Export DB" → `mysqldump` → AgilaCP emails customer the dump (S3-signed link). |
| **Customer wants custom domain** | Coolify adds the custom domain to the existing application. Let's Encrypt cert provisioned automatically. |
| **Tenant version downgrade** | NOT supported — migrations are forward-only. If a v0.2.0 tenant needs to go back to v0.1.0, they must restore from a pre-upgrade DB backup. |

## Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| Container starts but admin login fails | Seeder didn't run, or password rotation step failed | Check container logs for the `migrate --seed` step; if seeder skipped, manually re-run `migrate --seed` |
| `Class 'PDO' not found` or DB connection errors | PHP MySQL extension missing from image (shouldn't happen — Dockerfile installs `pdo_mysql`) | Re-pull image, check Dockerfile |
| 502 Bad Gateway | PHP-FPM not started (check logs) or Nginx misconfigured | `docker logs agila-tenant-{id}` and look for fatal startup errors |
| Login works but admin sees no extensions | Cache stale or extension manifests not registered | Container restart, or `php artisan cache:clear` via Coolify console |
| Migrations fail on upgrade | Custom data violating new constraint | Restore from pre-upgrade DB snapshot, investigate before retrying |
| Container exits immediately (CrashLoopBackOff) | Env var missing or DB unreachable | Coolify logs will show which env var the entrypoint complained about |

## Open questions for first Cloud deploy

These get resolved when you actually deploy the first tenant. Capture answers back into this doc afterward:

- Image visibility: public or private GHCR? (Public is simpler; private requires Coolify token but prevents pirating Plus extensions.)
- Where does AgilaCP's transactional SMTP run? (Postmark? AWS SES? Self-hosted Postal?)
- DB backup cadence: nightly Coolify-managed, or AgilaCP-scheduled via custom cron?
- What's the rollback procedure if v0.2.0 has a regression that breaks tenants?
