<?php echo $header; ?><?php echo $column_left; ?>
<div id="content">
  <div class="page-header">
    <div class="container-fluid">
      <div class="pull-right">
        <button type="submit" form="form-erp-sync" data-toggle="tooltip" title="<?php echo $button_save; ?>" class="btn btn-primary"><i class="fa fa-save"></i></button>
        <a href="<?php echo $cancel; ?>" data-toggle="tooltip" title="<?php echo $button_cancel; ?>" class="btn btn-default"><i class="fa fa-reply"></i></a>
      </div>
      <h1><?php echo $heading_title; ?></h1>
      <ul class="breadcrumb">
        <?php foreach ($breadcrumbs as $breadcrumb) { ?>
        <li><a href="<?php echo $breadcrumb['href']; ?>"><?php echo $breadcrumb['text']; ?></a></li>
        <?php } ?>
      </ul>
    </div>
  </div>

  <div class="container-fluid">
    <?php if ($error_warning) { ?>
    <div class="alert alert-danger"><i class="fa fa-exclamation-circle"></i> <?php echo $error_warning; ?>
      <button type="button" class="close" data-dismiss="alert">&times;</button>
    </div>
    <?php } ?>

    <?php if ($success) { ?>
    <div class="alert alert-success"><i class="fa fa-check-circle"></i> <?php echo $success; ?>
      <button type="button" class="close" data-dismiss="alert">&times;</button>
    </div>
    <?php } ?>

    <div class="panel panel-default">
      <div class="panel-heading">
        <h3 class="panel-title"><i class="fa fa-pencil"></i> <?php echo $text_edit; ?></h3>
      </div>
      <div class="panel-body">
        <form action="<?php echo $action; ?>" method="post" enctype="multipart/form-data" id="form-erp-sync" class="form-horizontal">

          <!-- Status -->
          <div class="form-group">
            <label class="col-sm-2 control-label" for="input-status"><?php echo $entry_status; ?></label>
            <div class="col-sm-10">
              <select name="module_erp_sync_status" id="input-status" class="form-control">
                <option value="1" <?php if ($module_erp_sync_status) { ?>selected="selected"<?php } ?>><?php echo $text_enabled; ?></option>
                <option value="0" <?php if (!$module_erp_sync_status) { ?>selected="selected"<?php } ?>><?php echo $text_disabled; ?></option>
              </select>
              <p class="help-block"><?php echo $text_status_hint; ?></p>
            </div>
          </div>

          <!-- API Key -->
          <div class="form-group <?php if ($error_api_key) { ?>has-error<?php } ?>">
            <label class="col-sm-2 control-label" for="input-api-key"><?php echo $entry_api_key; ?></label>
            <div class="col-sm-10">
              <div class="input-group">
                <input type="text" name="module_erp_sync_api_key" value="<?php echo htmlspecialchars($module_erp_sync_api_key, ENT_QUOTES, 'UTF-8'); ?>" id="input-api-key" class="form-control" style="font-family: monospace;" />
                <span class="input-group-btn">
                  <button type="button" id="btn-generate-key" class="btn btn-warning" data-toggle="tooltip" title="<?php echo $button_generate; ?>"><i class="fa fa-refresh"></i> <?php echo $button_generate; ?></button>
                </span>
              </div>
              <?php if ($error_api_key) { ?>
              <div class="text-danger"><?php echo $error_api_key; ?></div>
              <?php } ?>
              <p class="help-block"><?php echo $text_key_hint; ?></p>
            </div>
          </div>

          <!-- IP Whitelist -->
          <div class="form-group">
            <label class="col-sm-2 control-label" for="input-ip-whitelist"><?php echo $entry_ip_whitelist; ?></label>
            <div class="col-sm-10">
              <input type="text" name="module_erp_sync_ip_whitelist" value="<?php echo htmlspecialchars($module_erp_sync_ip_whitelist, ENT_QUOTES, 'UTF-8'); ?>" id="input-ip-whitelist" class="form-control" placeholder="e.g. 203.0.113.5, 198.51.100.0" />
              <p class="help-block"><?php echo $text_ip_hint; ?></p>
            </div>
          </div>

          <!-- Debug Logging -->
          <div class="form-group">
            <label class="col-sm-2 control-label" for="input-debug-log"><?php echo $entry_debug_log; ?></label>
            <div class="col-sm-10">
              <select name="module_erp_sync_debug_log" id="input-debug-log" class="form-control">
                <option value="1" <?php if ($module_erp_sync_debug_log) { ?>selected="selected"<?php } ?>><?php echo $text_enabled; ?></option>
                <option value="0" <?php if (!$module_erp_sync_debug_log) { ?>selected="selected"<?php } ?>><?php echo $text_disabled; ?></option>
              </select>
              <p class="help-block"><?php echo $text_log_hint; ?></p>
            </div>
          </div>

        </form>
      </div>
    </div>

    <!-- API Endpoint Reference -->
    <div class="panel panel-info">
      <div class="panel-heading">
        <h3 class="panel-title"><i class="fa fa-info-circle"></i> API Endpoint Reference</h3>
      </div>
      <div class="panel-body">
        <p>All endpoints require the header: <code>X-ERP-API-Key: &lt;your-key&gt;</code></p>
        <table class="table table-bordered table-condensed">
          <thead>
            <tr>
              <th>Method</th>
              <th>Route</th>
              <th>Description</th>
            </tr>
          </thead>
          <tbody>
            <tr><td><span class="label label-success">GET</span></td><td><code>api/erp/ping</code></td><td>Health check — returns store name and OpenCart version</td></tr>
            <tr><td><span class="label label-success">GET</span></td><td><code>api/erp/products</code></td><td>Paginated product list (supports <code>modified_since</code>, <code>page</code>, <code>limit</code>)</td></tr>
            <tr><td><span class="label label-success">GET</span></td><td><code>api/erp/orders</code></td><td>Paginated order list with line items and totals</td></tr>
            <tr><td><span class="label label-success">GET</span></td><td><code>api/erp/categories</code></td><td>All categories with descriptions and paths</td></tr>
            <tr><td><span class="label label-success">GET</span></td><td><code>api/erp/manufacturers</code></td><td>All manufacturers</td></tr>
            <tr><td><span class="label label-primary">POST</span></td><td><code>api/erp/product/update</code></td><td>Update a single product (price, quantity, status, name, description)</td></tr>
            <tr><td><span class="label label-primary">POST</span></td><td><code>api/erp/product/bulk_update</code></td><td>Bulk update multiple products</td></tr>
          </tbody>
        </table>
        <p class="text-muted" style="margin-top:10px;">
          Example URL: <code><?php echo rtrim(HTTPS_CATALOG ?: HTTP_CATALOG, '/'); ?>/index.php?route=api/erp/ping</code>
        </p>
        <p class="text-muted">
          <strong>Note:</strong> The API runs on the catalog (frontend) side to avoid admin login requirements. Deploy the API files to <code>catalog/controller/api/erp.php</code> and <code>catalog/model/api/erp.php</code>.
        </p>
      </div>
    </div>
  </div>
</div>

<script>
document.getElementById('btn-generate-key').addEventListener('click', function() {
  var chars = 'abcdef0123456789';
  var key = '';
  var array = new Uint8Array(64);
  window.crypto.getRandomValues(array);
  for (var i = 0; i < 64; i++) {
    key += chars[array[i] % chars.length];
  }
  document.getElementById('input-api-key').value = key;
});
</script>

<?php echo $footer; ?>
