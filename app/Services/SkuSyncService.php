<?php

namespace App\Services;

use App\Channels\ChannelRegistry;
use Illuminate\Support\Facades\DB;

class SkuSyncService
{
    public function syncSkuChanges(int $productId, array $skuChanges): array
    {
        if (empty($skuChanges['product_sku']) && empty($skuChanges['variation_skus'])) {
            return [];
        }

        $status = DB::table('products')->where('product_id', $productId)->value('status');
        if ((int) $status === 0) {
            return [];
        }

        $messages = [];
        foreach (app(ChannelRegistry::class)->skuSyncContributors() as $contributor) {
            $key = $contributor->getId();
            $message = $contributor->pushSkuChanges($productId, $skuChanges);
            if ($message !== null) {
                $messages[$key] = $message;
            }
        }

        return $messages;
    }
}
