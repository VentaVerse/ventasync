<?php

namespace Extensions\venta\Commands;

use Extensions\venta\Models\VentaProductLink;
use Extensions\venta\Models\VentaSetting;
use Extensions\venta\Services\Venta\VentaClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class VentaPushStock extends Command implements \App\Integrations\Contracts\SteppableAutomation
{
    protected $signature = 'venta:push-stock
        {--store= : Store ID (venta_settings.id). Omit to push for all enabled stores}';

    protected $description = 'Push ERP product quantities to all linked Venta products';

    public function handle(): int
    {
        $storeId = $this->option('store');

        if ($storeId) {
            $settings = VentaSetting::where('id', (int) $storeId)->where('enabled', true)->get();
        } else {
            $settings = VentaSetting::where('enabled', true)->get();
        }

        if ($settings->isEmpty()) {
            $this->error('No enabled Venta stores found.');
            return 1;
        }

        $pfx = (string) config('catalog.prefix');

        foreach ($settings as $setting) {
            $this->info("Pushing stock for: {$setting->store_name} (#{$setting->id})");

            $client = new VentaClient($setting);
            $links = VentaProductLink::where('venta_setting_id', $setting->id)
                ->whereNotNull('venta_product_id')
                ->get();

            if ($links->isEmpty()) {
                $this->info('  No linked products. Skipping.');
                continue;
            }

            $productIds = $links->pluck('product_id')->unique()->toArray();

            $erpProducts = DB::table($pfx . 'product')
                ->whereIn('product_id', $productIds)
                ->get(['product_id', 'sku', 'quantity', 'status'])
                ->keyBy('product_id');

            $erpOptions = DB::table($pfx . 'product_option_value')
                ->whereIn('product_id', $productIds)
                ->whereNotNull('sku')
                ->where('sku', '!=', '')
                ->get(['product_id', 'sku', 'quantity'])
                ->groupBy(fn($r) => (int) $r->product_id);

            $ok = 0;
            $err = 0;
            $skip = 0;
            $outcomes = [];

            foreach ($links as $link) {
                $product = $erpProducts->get($link->product_id);
                if (!$product || (int) $product->status === 0) {
                    $skip++;
                    continue;
                }

                $sku = $link->sku ?: $product->sku;

                $variants = $erpOptions->get($link->product_id);
                $failure = null;

                if ($variants && $variants->count() > 0) {
                    $allOk = true;
                    foreach ($variants as $ov) {
                        $result = $client->pushVariantStock(trim($ov->sku), max(0, (int) $ov->quantity));
                        if (! $result['ok']) {
                            $allOk = false;
                            $failure = $failure ?? $this->failureText($result);
                        }
                    }

                    // The parent push was fired and its result thrown away, so
                    // a product whose variants all succeeded counted as OK
                    // even when the product itself did not exist on Venta -
                    // which is exactly the case this command needs to report.
                    $parent = $client->pushStock($sku, max(0, (int) $product->quantity));
                    if (! $parent['ok']) {
                        $allOk = false;
                        $failure = $failure ?? $this->failureText($parent);
                    }

                    $allOk ? $ok++ : $err++;
                } else {
                    $result = $client->pushStock($sku, max(0, (int) $product->quantity));

                    if ($result['ok']) {
                        $ok++;
                    } else {
                        $err++;
                        $failure = $this->failureText($result);
                    }
                }

                $outcomes[(int) $link->product_id] = $failure;

                usleep(200000); // 200ms rate limit
            }

            // Record that this ran, the way VentaPushReviews already did.
            //
            // Nothing wrote last_stock_push_at. The column existed, the
            // settings page read it, and no code path anywhere set it - so
            // "Stock pushed: Never" was permanent no matter how many hundreds
            // of products pushed successfully. Same for last_product_sync_at
            // below.
            //
            // Stamped even when some rows failed, because the figure answers
            // "when did this last run", not "when did it last run perfectly".
            // A push where 254 succeeded and 5 failed did happen, and saying
            // "Never" about it is the worse lie.
            $setting->update(['last_stock_push_at' => now()]);
            $this->recordOutcomes($setting->id, $outcomes);

            $this->info("  Done. OK: {$ok}, Failed: {$err}, Skipped: {$skip}");
        }

        return 0;
    }

    /**
     * What Venta said, in one line, or a stand-in that admits we have none.
     *
     * The client wraps a 4xx in an exception message, so the useful part -
     * "Product not found" - arrives nested inside "HTTP request returned
     * status code 404: {...}". Both are kept: the operator needs the words,
     * and the status is what makes a broken link recognisable.
     */
    private function failureText(array $result): string
    {
        $body = $result['body'] ?? [];
        $text = is_array($body) ? (string) ($body['error'] ?? $body['message'] ?? '') : (string) $body;
        $text = trim(preg_replace('/\s+/', ' ', $text));

        if ($text === '') {
            $status = (int) ($result['status'] ?? 0);
            $text = $status > 0 ? "Venta answered {$status}." : 'The request to Venta failed.';
        }

        return $text;
    }

    /**
     * Write each product's outcome where the operator will look for it.
     *
     * This command counted failures and threw away both the reason and which
     * product, so "Failed: 5" was the entire account of five products whose
     * stock never reached Venta. The product-group page reads sync_status and
     * push_error on the pivot, and nothing here was writing them, so those
     * five rows went on looking exactly like the 254 that worked.
     *
     * Scoped to groups belonging to THIS store: a product can sit in several
     * groups, and a failure against one store says nothing about another.
     *
     * @param  array<int, string|null>  $outcomes  product id => failure text, or null when it worked
     */
    private function recordOutcomes(int $settingId, array $outcomes): void
    {
        if (empty($outcomes)) {
            return;
        }

        $groupIds = DB::table('venta_product_groups')
            ->where('venta_setting_id', $settingId)
            ->pluck('id');

        if ($groupIds->isEmpty()) {
            return;
        }

        foreach ($outcomes as $productId => $failure) {
            DB::table('venta_product_group_products')
                ->whereIn('venta_product_group_id', $groupIds)
                ->where('product_id', $productId)
                ->update([
                    'sync_status'    => $failure === null ? 'pushed' : 'error',
                    'push_error'     => $failure === null ? null : Str::limit($failure, 480),
                    'last_pushed_at' => now(),
                ]);
        }
    }

    /** Run now walks this push ten products per request (SteppableAutomation, 2026-09-07). */
    public function automationUnits(\App\Models\ScheduledJob $job): array
    {
        return ['label' => 'products', 'units' => \Extensions\venta\Services\Venta\VentaPushSteps::units($job)];
    }

    public function automationStep(\App\Models\ScheduledJob $job, array $units): array
    {
        return \Extensions\venta\Services\Venta\VentaPushSteps::step('stock', $units);
    }
}
