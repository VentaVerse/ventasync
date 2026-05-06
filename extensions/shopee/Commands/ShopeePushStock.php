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
        if (!$setting || !$setting->partner_id || !$setting->partner_key || !$setting->access_token || !$setting->shop_id) {
            $this->error('Missing Shopee credentials/token. Configure Shopee settings first.');
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

        // Group links by item_id for batch stock updates
        $grouped = [];
        foreach ($links as $link) {
            $grouped[$link->shopee_item_id][] = $link;
        }

        foreach ($grouped as $itemId => $itemLinks) {
            $stockList = [];

            foreach ($itemLinks as $link) {
                $productId = (int) $link->product_id;

                // Skip disabled products
                $productStatus = DB::table($pfx . 'product')->where('product_id', $productId)->value('status');
                if ((int) $productStatus === 0) {
                    $totalSkipped++;
                    continue;
                }

                // If there's a model_id, try to find qty from option value by SKU
                $qty = 0;
                $sku = trim((string) ($link->sku ?? ''));

                if ($sku !== '') {
                    // Try option value SKU
                    $povQty = DB::table($pfx . 'product_option_value')
                        ->where('product_id', $productId)
                        ->where('sku', $sku)
                        ->value('quantity');

                    if ($povQty !== null) {
                        $qty = max(0, (int) $povQty);
                    } else {
                        // Fallback: main product qty
                        $prodQty = DB::table($pfx . 'product')
                            ->where('product_id', $productId)
                            ->value('quantity');
                        $qty = max(0, (int) ($prodQty ?? 0));
                    }
                } else {
                    $prodQty = DB::table($pfx . 'product')
                        ->where('product_id', $productId)
                        ->value('quantity');
                    $qty = max(0, (int) ($prodQty ?? 0));
                }

                $modelId = (int) ($link->shopee_model_id ?? 0);

                $stockEntry = ['model_id' => $modelId, 'seller_stock' => [['stock' => $qty]]];
                $stockList[] = $stockEntry;
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
                $setting->mode ?? 'live',
                (int) $setting->partner_id,
                (string) $setting->partner_key,
                (string) $setting->access_token,
                (int) $setting->shop_id,
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
            foreach ($itemLinks as $link) {
                $link->update($syncData);
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
