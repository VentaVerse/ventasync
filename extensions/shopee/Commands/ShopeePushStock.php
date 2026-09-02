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
        $setting = ShopeeSetting::defaultStore()?->decrypted();
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

        /* The shared sender (Phase E, #184, #190): link-driven plan, skip
         * ledger, per-model failure_list verdicts, and the one-shot model
         * repair - the same path the buttons walk, so the cron can no
         * longer drift from the UI. Disabled products are dropped first,
         * as before. */
        $enabledIds = DB::table($pfx . 'product')
            ->whereIn('product_id', $links->pluck('product_id')->map(fn ($v) => (int) $v)->unique()->all())
            ->where('status', 1)
            ->pluck('product_id')->map(fn ($v) => (int) $v)->all();
        $totalSkipped = $links->count() - ($links = $links->filter(fn ($l) => in_array((int) $l->product_id, $enabledIds, true))->values())->count();

        $results = app(\Extensions\shopee\Services\Shopee\ShopeeStockPricePush::class)
            ->push('stock', $auth, $links, $pfx);

        $totalOk = $results['ok'];
        $totalErr = $results['err'];
        if ($totalErr > 0) {
            Log::warning('Shopee push-stock: ' . $totalErr . ' model(s) failed', ['last_error' => $results['last_error']]);
        }

        $ledger = \Extensions\shopee\Services\Shopee\ShopeeStockPricePush::ledgerClause($results);
        $this->info("Done. Success: {$totalOk}, Failed: {$totalErr}, Skipped: {$totalSkipped}." . $ledger);

        // Stamp sync timestamp on settings (independent of API logging)
        ShopeeSetting::query()->update(['last_stock_push_at' => now()]);

        return $totalErr > 0 ? 1 : 0;
    }

}
