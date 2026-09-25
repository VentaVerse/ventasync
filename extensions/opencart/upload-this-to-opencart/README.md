# How to Install the ERP Sync API on Your OpenCart Store

This folder contains an OpenCart module that lets your VentaSync ERP communicate with your OpenCart store — pulling orders, pushing stock and price updates, and syncing product groups.

**Important:** This code is NOT installed in VentaSync. It's installed inside your OpenCart store.

## Compatibility

- **OpenCart:** 2.3.0.0 and later (2.3.x branch). Not tested on OpenCart 3.x or 4.x.
- **PHP:** 5.6+ (matching OpenCart 2.3 requirements). Modern OpenCart hosts use 7.4 or 8.x; both work.
- **MySQL:** Whatever your OpenCart already uses.

## Before You Start

You'll need:

1. **FTP / SFTP credentials** for your OpenCart store, OR cPanel / Plesk / Hestia File Manager access
2. **OpenCart admin login** with permission to install modules
3. **A backup of your OpenCart `admin/` and `catalog/` directories** — these instructions overwrite no existing files, but a backup is cheap insurance

## Step 1 — Upload the Files

Connect to your OpenCart store's file system using your preferred method:

- **cPanel File Manager** (most common shared hosts)
- **FTP client** like FileZilla, WinSCP, or Cyberduck
- **SFTP** if your host supports it (more secure than FTP)
- **SSH** if you have shell access — `rsync` or `scp` works fine

Navigate to your OpenCart root directory. This is the folder that contains:

```
your-opencart-store/
├── admin/
├── catalog/
├── system/
├── index.php
├── config.php
└── admin/config.php
```

Now upload **the contents of the `upload-this-to-opencart/` folder** (not the folder itself) so that:

- The files inside `upload-this-to-opencart/admin/` merge into your store's `admin/` folder
- The files inside `upload-this-to-opencart/catalog/` merge into your store's `catalog/` folder

After uploading, your OpenCart should have these new files:

```
admin/controller/extension/module/erp_sync.php
admin/language/en-gb/extension/module/erp_sync.php
admin/view/template/extension/module/erp_sync.tpl
catalog/controller/api/erp.php
catalog/model/api/erp.php
```

These are all NEW files — none overwrite anything that ships with OpenCart.

## Step 2 — Install the Module in OpenCart Admin

1. Log in to your OpenCart admin panel: `https://your-store.com/admin`
2. Go to **Extensions → Extensions**
3. In the **Choose the extension type** dropdown, select **Modules**
4. Find **ERP Sync API** in the module list
5. Click the green **+ Install** button next to it
6. After install completes, click the blue **Edit (pencil)** button on the same row

The module auto-generates a 64-character API key when it installs. You'll copy this on the next step.

## Step 3 — Configure the Module

On the ERP Sync API edit page, set:

| Field | What to set | Why |
|---|---|---|
| **Status** | `Enabled` | The API won't respond to requests when this is disabled. |
| **API Key** | Auto-generated. **Copy this** — you'll paste it into VentaSync. | Authenticates every API call from your VentaSync server. |
| **IP Whitelist** | Recommended: enter your VentaSync server's public IP. Leave empty to allow any IP (less secure). | Blocks API calls from servers other than yours. |
| **Debug Logging** | Leave `Disabled` for normal use. Enable only when troubleshooting. | Logs every API request to the OpenCart error log. Generates noise during normal operation. |

Click **Save** (top-right floppy-disk icon).

## Step 4 — Connect VentaSync to Your OpenCart Store

Switch over to your VentaSync ERP admin:

1. Go to **Integrations → OpenCart**
2. Click **Add Store** (or edit an existing one)
3. Fill in:
   - **Store Name:** Anything you'll recognize (e.g., "Main Store")
   - **Store URL:** Your OpenCart base URL (e.g., `https://your-store.com`)
   - **API Key:** Paste the key you copied in Step 3
4. Click **Save**
5. Click **Test Connection**

If you see `Connected successfully` — you're done. The ERP will start syncing on its next scheduled run, or you can trigger a manual sync from the OpenCart store's detail page.

## Troubleshooting

| Error in VentaSync | What it means | Fix |
|---|---|---|
| `403 ERP Sync API module is disabled` | The module's Status field in OpenCart admin is `Disabled` | Go back to Step 3 and set Status to `Enabled` |
| `403 IP not allowed` | Your VentaSync server's IP isn't in the OpenCart IP Whitelist | Either add the IP to the whitelist, or clear the whitelist field entirely |
| `401 Unauthorized` / `Invalid API key` | The API key in VentaSync doesn't match the one in OpenCart | Copy the API key from OpenCart admin again and re-paste into VentaSync |
| `404 Not Found` on `/index.php?route=api/erp/...` | The catalog-side files didn't upload to `catalog/controller/api/erp.php` | Re-upload Step 1, double-check folder structure |
| `Class 'ControllerExtensionModuleErpSync' not found` | The admin file is missing or in the wrong path | Re-upload Step 1, check `admin/controller/extension/module/erp_sync.php` exists |
| `Permission denied` during upload | Your FTP user doesn't have write access | Contact your host or use cPanel File Manager which usually runs as the site owner |

## Uninstalling

To remove the ERP Sync API from your OpenCart store:

1. OpenCart admin → **Extensions → Extensions → Modules**
2. Find ERP Sync API → click the red **— Uninstall** button (this deletes the settings rows from your database)
3. Delete the 5 files you uploaded in Step 1

That's it.

## Security Notes

- **Use HTTPS** on your OpenCart store. The API key travels in a header — over plain HTTP, it can be intercepted.
- **Set an IP Whitelist** in production. The default (empty) accepts requests from anywhere with the right key, which is fine for evaluation but tighter security needs the whitelist.
- **Don't paste your API key into chat or screenshots.** Anyone with the key and your OpenCart URL can read your full catalog + create orders.
- **Rotate the key** if you ever suspect it's been exposed. Go to OpenCart admin → ERP Sync API → click **Generate Key** → Save → update the key in VentaSync.

## Need Help?

Contact us at [ventasync.com/contact](https://ventasync.com/contact) with:
- Your OpenCart version (Help → About in OpenCart admin)
- The exact error message
- Whether Debug Logging was enabled (and the relevant lines from `system/storage/logs/error.log`)
