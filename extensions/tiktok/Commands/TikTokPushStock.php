<?php

namespace Extensions\tiktok\Commands;

use Extensions\tiktok\Models\TikTokApiLog;
use Extensions\tiktok\Models\TikTokProductGroupProduct;
use Extensions\tiktok\Models\TikTokSetting;
use Extensions\tiktok\Services\TikTok\TikTokClient;
use Extensions\tiktok\Services\TikTok\TikTokStockPushService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TikTokPushStock extends Command
{
    protected $signature = 'tiktok:push-stock';

    protected $description = 'Push ERP product quantities to TikTok Shop for all linked products';

    public function handle(): int
    {
        $raw = TikTokSetting::query()->first();
        if (!$raw) {
            $this->error('TikTok settings not configured.');
            return 1;
        }

        $s = $raw->decrypted();
        $sandbox = $raw->mode === 'sandbox';

        $appKey     = $sandbox ? ($s->sandbox_app_key ?? '') : ($s->app_key ?? '');
        $appSecret  = $sandbox ? ($s->sandbox_app_secret ?? '') : ($s->app_secret ?? '');
        $token      = $sandbox ? ($s->sandbox_access_token ?? '') : ($s->access_token ?? '');
        $shopCipher = $sandbox ? ($raw->sandbox_shop_cipher ?? '') : ($raw->shop_cipher ?? '');
        $warehouseId = $raw->warehouse_id ?: null;

        if (!$appKey || !$appSecret || !$token) {
            $this->error('Missing TikTok credentials/token.');
            return 1;
        }

        $expiresAt = $sandbox ? $raw->sandbox_expires_at : $raw->expires_at;
        if ($expiresAt && $expiresAt->isPast()) {
            $this->warn('TikTok access token expired. Refresh it first.');
            return 1;
        }

        $pfx = (string) config('catalog.prefix');
        $client = app(TikTokClient::class);

        // Get all pivot rows that have been pushed to TikTok
        $pivotRows = TikTokProductGroupProduct::query()
            ->whereNotNull('tiktok_product_id')
            ->where('tiktok_product_id', '!=', '')
            ->get();

        if ($pivotRows->isEmpty()) {
            $this->info('No TikTok products linked.');
            $raw->update(['last_stock_push_at' => now()]);
            return 0;
        }

        $this->info("Pushing stock for {$pivotRows->count()} product(s)...");

        /* The shared sender (Phase F, #184): the same path the buttons
         * walk - skip ledger, per-SKU verdicts, the one-shot map repair -
         * so the cron can no longer drift from the UI. */
        $c = [
            'app_key' => $appKey, 'app_secret' => $appSecret, 'token' => $token,
            'shop_cipher' => $shopCipher, 'warehouse_id' => $warehouseId,
        ];
        $results = app(\Extensions\tiktok\Services\TikTok\TikTokStockPricePush::class)
            ->push('stock', $c, $pivotRows, $pfx);

        $totalOk = $results['ok'];
        $totalErr = $results['err'];
        $skipped = $results['skipped'];
        if ($totalErr > 0) {
            Log::warning('TikTok push-stock: ' . $totalErr . ' product(s) failed', ['last_error' => $results['last_error']]);
        }

        $raw->update(['last_stock_push_at' => now()]);

        $this->info("Done. Success: {$totalOk}, Failed: {$totalErr}, Skipped: {$skipped}."
            . \Extensions\tiktok\Services\TikTok\TikTokStockPricePush::ledgerClause($results));
        return $totalErr > 0 ? 1 : 0;
    }

}
