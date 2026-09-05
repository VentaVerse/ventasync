<?php

namespace Extensions\shopee\Services\Shopee;

use App\Services\ActivityLogger;
use App\Support\MarketplaceLink;
use Extensions\shopee\Models\ShopeeApiLog;
use Extensions\shopee\Models\ShopeeItemCache;
use Extensions\shopee\Models\ShopeeListing;
use Extensions\shopee\Models\ShopeeProductGroupProduct;
use Extensions\shopee\Models\ShopeeProductLink;
use Extensions\shopee\Models\ShopeeSetting;
use Illuminate\Support\Facades\DB;

/**
 * Shopee's half of the catalog delete (ProductRemover, 2026-09-05): the
 * item is deleted on every store that holds it, then every local row
 * for the product goes. A store that refuses keeps its rows so the
 * retry sees them.
 */
final class ShopeeProductRemover
{
    /** Mirror statuses that mean the item is already gone: no call needed. */
    private const GONE = ['BANNED', 'SELLER_DELETE', 'SHOPEE_DELETE', 'MISSING'];

    public function __construct(private readonly ShopeeClient $client) {}

    /** @see \App\Integrations\Contracts\ProductRemover::productPresence */
    public function presence(array $productIds): array
    {
        $links = ShopeeProductLink::query()->whereIn('product_id', $productIds)->whereNotNull('shopee_item_id')->get();
        if ($links->isEmpty()) {
            return [];
        }
        $stores = $this->stores($links->pluck('shopee_setting_id'));

        $out = [];
        $seen = [];
        foreach ($links as $link) {
            $key = (int) $link->product_id . ':' . (int) $link->shopee_setting_id . ':' . (int) $link->shopee_item_id;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $status = strtoupper((string) ($link->live_status ?? ''));
            $out[(int) $link->product_id][] = [
                'channel' => 'Shopee',
                'store' => $stores[(int) $link->shopee_setting_id] ?? 'store #' . (int) $link->shopee_setting_id,
                'item' => (string) $link->shopee_item_id,
                'live' => $status === '' ? null : !in_array($status, self::GONE, true),
                'removable' => true,
            ];
        }

        return $out;
    }

    /** @see \App\Integrations\Contracts\ProductRemover::removeProduct */
    public function remove(int $productId): array
    {
        $removed = [];
        $errors = [];

        $links = ShopeeProductLink::query()->where('product_id', $productId)->get();
        $stores = $this->stores($links->pluck('shopee_setting_id'));
        $byItem = $links->filter(fn ($l) => (int) $l->shopee_item_id > 0)
            ->groupBy(fn ($l) => (int) $l->shopee_setting_id . ':' . (int) $l->shopee_item_id);

        foreach ($byItem as $key => $group) {
            $storeId = (int) $group->first()->shopee_setting_id;
            $itemId = (int) $group->first()->shopee_item_id;
            $storeName = $stores[$storeId] ?? 'store #' . $storeId;
            $label = 'Shopee (' . $storeName . ')';
            $gone = $group->every(fn ($l) => in_array(strtoupper((string) ($l->live_status ?? '')), self::GONE, true));

            if (!$gone) {
                $setting = ShopeeSetting::query()->find($storeId)?->decrypted();
                $auth = ShopeeSetting::activeAuth($setting);
                if (!$setting || !$auth['complete']) {
                    $errors[] = $label . ' is not connected, so item ' . $itemId . ' could not be deleted there.';
                    continue;
                }
                $path = '/api/v2/product/delete_item';
                try {
                    $result = $this->client->shopPost(
                        $auth['mode'], (int) $auth['partner_id'], (string) $auth['partner_key'],
                        (string) $auth['access_token'], (int) $auth['shop_id'], $path, [], ['item_id' => $itemId]
                    );
                } catch (\Throwable $e) {
                    $result = ['ok' => false, 'status' => 0, 'body' => ['error' => 'transport', 'message' => \App\Support\TransportError::plain($e, 'Shopee')]];
                }
                ShopeeApiLog::safeCreate([
                    'shopee_setting_id' => $storeId,
                    'pack' => 'shopee.product.delete_item', 'method' => 'POST', 'api_path' => $path, 'auth_required' => true,
                    'request_params' => ['item_id' => $itemId, 'product_id' => $productId],
                    'response_status' => (int) ($result['status'] ?? 0), 'ok' => (bool) ($result['ok'] ?? false),
                    'response_body' => $result['body'] ?? null,
                ]);
                $body = is_array($result['body'] ?? null) ? $result['body'] : [];
                $respError = (string) ($body['error'] ?? '');
                $respMsg = (string) ($body['message'] ?? '');
                $failed = !($result['ok'] ?? false) || $respError !== '';
                if ($failed && !MarketplaceLink::looksMissingRemotely($respError . ' ' . $respMsg)) {
                    $errors[] = $label . ': ' . \App\Support\MarketplaceAnswer::errorText('Shopee', $result);
                    continue;
                }
                ActivityLogger::log('deleted', 'Shopee Product', $productId, 'Deleted item ' . $itemId . ' from ' . $label);
                $removed[] = $label . ' item ' . $itemId;
            }

            ShopeeItemCache::query()->where('shopee_setting_id', $storeId)->where('shopee_item_id', $itemId)->delete();
            ShopeeProductLink::query()->where('product_id', $productId)->where('shopee_setting_id', $storeId)->where('shopee_item_id', $itemId)->delete();
        }

        if ($errors === []) {
            ShopeeProductLink::query()->where('product_id', $productId)->delete();
            ShopeeListing::query()->where('product_id', $productId)->delete();
            ShopeeProductGroupProduct::query()->where('product_id', $productId)->delete();
        }

        return ['ok' => $errors === [], 'removed' => $removed, 'kept' => [], 'errors' => $errors];
    }

    /** @see \App\Integrations\Contracts\ProductRemover::strandedProductIds */
    public function stranded(): array
    {
        $pfx = (string) config('catalog.prefix');
        $ids = [];
        foreach (['shopee_product_links', 'shopee_listings', 'shopee_product_group_products'] as $table) {
            $ids = array_merge($ids, DB::table($table)
                ->whereNotNull('product_id')
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from($pfx . 'product as op')->whereColumn('op.product_id', $table . '.product_id'))
                ->distinct()->pluck('product_id')->map(fn ($v) => (int) $v)->all());
        }

        return array_values(array_unique($ids));
    }

    /** @return array<int, string> */
    private function stores($ids): array
    {
        return ShopeeSetting::query()->whereIn('id', collect($ids)->filter()->unique()->all())
            ->get()->mapWithKeys(fn ($s) => [(int) $s->id => (string) ($s->store_name ?: 'Shopee store #' . $s->id)])->all();
    }
}
