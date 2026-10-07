<?php

namespace App\Services\Orders;

use App\Integrations\Contracts\MarketplaceFeeSource;
use App\Integrations\IntegrationRegistry;
use Illuminate\Support\Facades\DB;

// Replace the order's fee rows, never add to them (syncs re-run); amounts are stored negative.
class MarketplaceFeeNormalizer
{
    public const CODES = [
        'commission' => ['marketplace_commission', 'Commission', 91],
        'payment' => ['marketplace_payment_fee', 'Payment fee', 92],
        'shipping' => ['marketplace_shipping_fee', 'Delivery fee', 93],
        'other' => ['marketplace_other_fee', 'Other fees', 94],
        'voucher' => ['marketplace_voucher', 'Vouchers', 95],
    ];

    public function __construct(private readonly IntegrationRegistry $registry) {}

    public function forOrder(int $coreOrderId): bool
    {
        foreach ($this->registry->marketplaceFeeSources() as $source) {
            $buckets = $source->feeBucketsForOrder($coreOrderId);
            if ($buckets !== null) {
                $this->write($coreOrderId, $buckets);

                return true;
            }
        }

        return false;
    }

    public function write(int $coreOrderId, array $buckets): void
    {
        $pfx = (string) config('catalog.prefix');
        $codes = array_map(fn ($c) => $c[0], self::CODES);

        DB::transaction(function () use ($pfx, $codes, $coreOrderId, $buckets) {
            DB::table($pfx . 'order_total')
                ->where('order_id', $coreOrderId)
                ->whereIn('code', $codes)
                ->delete();

            $rows = [];
            foreach (self::CODES as $bucket => [$code, $title, $sort]) {
                $amount = round(abs((float) ($buckets[$bucket] ?? 0)), 2);
                if ($amount <= 0) {
                    continue;
                }
                $rows[] = [
                    'order_id' => $coreOrderId,
                    'code' => $code,
                    'title' => $title,
                    'value' => -$amount,
                    'sort_order' => $sort,
                ];
            }

            if ($rows !== []) {
                DB::table($pfx . 'order_total')->insert($rows);
            }
        });
    }
}
