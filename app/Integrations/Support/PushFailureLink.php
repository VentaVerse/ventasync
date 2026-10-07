<?php

namespace App\Integrations\Support;

use App\Models\ScheduledJob;
use Illuminate\Support\Facades\Route;

final class PushFailureLink
{
    private const LISTINGS = [
        'shopee' => 'ext.shopee.products.index',
        'lazada' => 'ext.lazada.products.index',
        'tiktok' => 'ext.tiktok.products.index',
        'ventacart' => 'ext.ventacart.listings.index',
        'woocommerce' => 'ext.woocommerce.listings.index',
    ];

    public static function isPush(ScheduledJob $job): bool
    {
        return (bool) preg_match('/:push-(stock|price|qty)$/', (string) strtok(trim((string) $job->command), ' '));
    }

    public static function for(ScheduledJob $job, ?int $storeId = null): ?string
    {
        if ($job->last_run_ok !== false || ! self::isPush($job)) {
            return null;
        }
        $name = self::LISTINGS[(string) $job->integration] ?? null;
        if ($name === null || ! Route::has($name)) {
            return null;
        }
        $params = ['sync_status' => 'error'];
        $store = $storeId ?? ($job->store_id !== null ? (int) $job->store_id : null);
        if ($store) {
            $params['store'] = $store;
        }
        try {
            return route($name, $params);
        } catch (\Throwable) {
            return null;
        }
    }
}
