<?php

namespace Extensions\opencart\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class OpenCartReclassifyOrders extends Command
{
    protected $signature = 'opencart:reclassify-orders
        {--dry-run : Preview counts without updating}';

    protected $description = 'Reclassify opencart orders to lazada/shopee based on payment and shipping method';

    private array $lazadaPaymentMethods = [
        'COD',
        'GCASH_PP',
        'MIXEDCARD',
        'PAY_LATER',
        'PAYMENT_ACCOUNT',
        'WALLET_PAYMAYA2C2P',
        'BDO_IPP',
        'QRPH',
        'PURE_ZERO_PRICE',
    ];

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $pfx = (string) config('catalog.prefix');
        $table = $pfx . 'order';

        $this->info($dryRun ? 'DRY RUN: no changes will be made.' : 'Reclassifying opencart orders...');
        $this->newLine();

        $before = DB::table($table)
            ->where('marketplace_source', 'like', 'opencart:%')
            ->count();
        $this->line("OpenCart orders before: <comment>{$before}</comment>");
        $this->newLine();

        DB::beginTransaction();

        $count1 = DB::table($table)
            ->where('marketplace_source', 'like', 'opencart:%')
            ->where(function ($q) {
                $q->where('shipping_method', 'like', '%LazadaShipping%')
                  ->orWhere('shipping_method', 'Lazada Shipping');
            })
            ->update(['marketplace_source' => 'lazada']);

        $this->line("Rule 1: shipping_method contains 'Lazada': <info>{$count1}</info> → lazada");

        $count2 = DB::table($table)
            ->where('marketplace_source', 'like', 'opencart:%')
            ->where('payment_method', 'Shopee Payment')
            ->update(['marketplace_source' => 'shopee']);

        $this->line("Rule 2: payment_method = 'Shopee Payment': <info>{$count2}</info> → shopee");

        $count3 = DB::table($table)
            ->where('marketplace_source', 'like', 'opencart:%')
            ->whereIn('payment_method', $this->lazadaPaymentMethods)
            ->update(['marketplace_source' => 'lazada']);

        $this->line("Rule 3: Lazada payment codes (COD, GCASH_PP, etc.): <info>{$count3}</info> → lazada");

        $count4 = DB::table($table)
            ->where('marketplace_source', 'like', 'opencart:%')
            ->whereIn('shipping_method', ['SPX Express', 'Shopee Xpress', 'Ninja Van'])
            ->update(['marketplace_source' => 'shopee']);

        $this->line("Rule 4: Shopee couriers (SPX, Shopee Xpress): <info>{$count4}</info> → shopee");

        $this->newLine();
        $totalLazada = $count1 + $count3;
        $totalShopee = $count2 + $count4;
        $this->info("Total: {$totalLazada} → lazada, {$totalShopee} → shopee");

        $remaining = DB::table($table)
            ->where('marketplace_source', 'like', 'opencart:%')
            ->count();
        $this->line("Remaining as opencart: <comment>{$remaining}</comment>");

        $this->newLine();
        $this->info('Remaining opencart orders, top payment/shipping combos:');
        $leftover = DB::table($table)
            ->where('marketplace_source', 'like', 'opencart:%')
            ->select(DB::raw('payment_method, shipping_method, COUNT(*) as cnt'))
            ->groupBy('payment_method', 'shipping_method')
            ->orderByDesc('cnt')
            ->limit(20)
            ->get();

        $this->table(
            ['Payment Method', 'Shipping Method', 'Count'],
            $leftover->map(fn ($r) => [$r->payment_method ?: '(empty)', $r->shipping_method ?: '(empty)', $r->cnt])
        );

        if ($dryRun) {
            DB::rollBack();
            $this->newLine();
            $this->warn('Dry run complete, no changes committed. Run without --dry-run to apply.');
        } else {
            DB::commit();
            $this->newLine();
            $this->info('Done. All changes committed.');
        }

        return 0;
    }
}
