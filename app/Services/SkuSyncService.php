<?php

namespace App\Services;

use App\Integrations\IntegrationRegistry;
use Illuminate\Support\Facades\DB;

class SkuSyncService
{
    public function syncSkuChanges(int $productId, array $skuChanges): array
    {
        if (empty($skuChanges['product_sku']) && empty($skuChanges['option_skus'])) {
            return [];
        }

        $pfx = (string) config('catalog.prefix');
        $status = DB::table($pfx . 'product')->where('product_id', $productId)->value('status');
        if ((int) $status === 0) {
            return [];
        }

        $messages = [];
        foreach (app(IntegrationRegistry::class)->skuSyncContributors() as $contributor) {
            $key = $contributor->integrationId();
            $message = $contributor->pushSkuChanges($productId, $skuChanges);
            if ($message !== null) {
                $messages[$key] = $message;
            }
        }

        return $messages;
    }
}
