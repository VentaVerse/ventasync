<?php

namespace Extensions\shopee\Commands;

use Extensions\shopee\Models\ShopeeApiLog;
use Extensions\shopee\Models\ShopeeProductLink;
use Extensions\shopee\Models\ShopeeSetting;
use Extensions\shopee\Services\Shopee\ShopeeClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ShopeePushStock extends Command
{
    protected $signature = 'shopee:push-stock';

    protected $description = 'Push ERP product quantities to Shopee for all linked products';

    public function handle(ShopeeClient $client): int
    {
        $setting = ShopeeSetting::query()->first()?->decrypted();
        $auth = ShopeeSetting::activeAuth($setting);
        if (!$setting || !$auth['complete']) {
            $modeLabel = $auth['mode'] === 'sandbox' ? 'Sandbox' : 'Production';
            $this->error("Missing Shopee {$modeLabel} credentials/token. Configure Shopee settings first.");
            return 1;
        }

        $links = ShopeeProductLink::query()->get();

        if ($links->isEmpty()) {
            $this->warn('No Shopee product links found.');
            return 0;
        }

        $this->info("Pushing stock for {$links->count()} link(s)...");

        $pfx = (string) config('catalog.prefix');
        $totalOk = 0;
        $totalErr = 0;
        $totalSkipped = 0;

        // Delegate qty resolution to the shared service so the UI sync
        // (ShopeeProductGroupController::pushStockForLinks) and this cron
        // walk identical logic — both honour product_option_combinations
        // for multi-option products. Previously this cron read POV only,
        // missing every multi-option SKU.
        $resolved = app(\Extensions\shopee\Services\ShopeeStockPushService::class)->resolve($links, $pfx);

        // Drop disabled products before grouping.
        $resolved = array_values(array_filter($resolved, function ($row) use (&$totalSkipped) {
            if (($row['product_status'] ?? 1) === 0) {
                $totalSkipped++;
                return false;
            }
            return true;
        }));

        $grouped = [];
        foreach ($resolved as $row) {
            $grouped[$row['item_id']][] = $row;
        }

        foreach ($grouped as $itemId => $rows) {
            $stockList = [];

            foreach ($rows as $row) {
                $stockList[] = ['model_id' => $row['model_id'], 'seller_stock' => [['stock' => $row['quantity']]]];
            }

            if (empty($stockList)) {
                $totalSkipped++;
                continue;
            }

            $body = [
                'item_id'    => (int) $itemId,
                'stock_list' => $stockList,
            ];

            $res = $client->shopPost(
                $auth['mode'],
                (int) $auth['partner_id'],
                (string) $auth['partner_key'],
                (string) $auth['access_token'],
                (int) $auth['shop_id'],
                '/api/v2/product/update_stock',
                [],
                $body
            );

            ShopeeApiLog::safeCreate([
                'pack'            => 'shopee.product.update_stock.cron',
                'method'          => 'POST',
                'api_path'        => '/api/v2/product/update_stock',
                'auth_required'   => true,
                'request_params'  => $body,
                'response_status' => (int) ($res['status'] ?? 0),
                'ok'              => (bool) ($res['ok'] ?? false),
                'response_body'   => $res['body'] ?? null,
            ]);

            $resBody = $res['body'] ?? [];
            $errMsg = is_array($resBody) ? ($resBody['error'] ?? null) : null;

            $isOk = ($res['ok'] ?? false) && (empty($errMsg) || $errMsg === '' || $errMsg === 'success');
            $syncData = [
                'last_synced_at' => now(),
                'last_sync_action' => 'sync_qty',
                'last_sync_ok' => $isOk,
                'last_sync_error_code' => $isOk ? null : (string) ($errMsg ?? ''),
                'last_sync_error_message' => $isOk ? null : (is_array($resBody) ? (string) ($resBody['message'] ?? '') : ''),
            ];
            foreach ($rows as $row) {
                $row['link']->update($syncData);
            }

            if ($isOk) {
                $totalOk += count($stockList);
            } else {
                $totalErr += count($stockList);
                $msg = is_array($resBody) ? ($resBody['message'] ?? json_encode($resBody)) : (string) $resBody;
                Log::warning("Shopee push-stock failed for item {$itemId}", ['response' => $msg]);
            }
        }

        $this->info("Done. Success: {$totalOk}, Failed: {$totalErr}, Skipped: {$totalSkipped}");

        // Stamp sync timestamp on settings (independent of API logging)
        ShopeeSetting::query()->update(['last_stock_push_at' => now()]);

        return $totalErr > 0 ? 1 : 0;
    }

}
