<?php

namespace Extensions\tiktok\Services\TikTok;

use App\Services\ActivityLogger;
use App\Support\MarketplaceLink;
use Extensions\tiktok\Models\TikTokApiLog;
use Extensions\tiktok\Models\TikTokListing;
use Extensions\tiktok\Models\TikTokProductGroup;
use Extensions\tiktok\Models\TikTokProductGroupProduct;
use Extensions\tiktok\Models\TikTokSetting;
use Illuminate\Support\Facades\DB;

/**
 * TikTok Shop's half of the catalog delete (ProductRemover, 2026-09-05):
 * the product is deleted on every store that holds it, then every local
 * row goes. A store that refuses keeps its rows so the retry sees them.
 */
final class TikTokProductRemover
{
    private const GONE = ['MISSING', 'DELETED', 'PLATFORM_DEACTIVATED', 'FREEZE'];

    public function __construct(private readonly TikTokClient $client) {}

    /** @see \App\Integrations\Contracts\ProductRemover::productPresence */
    public function presence(array $productIds): array
    {
        $out = [];
        $stores = [];
        foreach ($this->holdings($productIds) as $h) {
            $stores[$h['store_id']] ??= $this->storeName($h['store_id']);
            $status = strtoupper((string) $h['live_status']);
            $out[$h['product_id']][] = [
                'channel' => 'TikTok Shop',
                'store' => $stores[$h['store_id']],
                'item' => $h['tiktok_product_id'],
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

        foreach ($this->holdings([$productId]) as $h) {
            $storeId = $h['store_id'];
            $label = 'TikTok Shop (' . $this->storeName($storeId) . ')';
            $ttId = $h['tiktok_product_id'];
            $gone = in_array(strtoupper((string) $h['live_status']), self::GONE, true);

            if (!$gone) {
                $c = $this->credentials($storeId);
                if (!$c) {
                    $errors[] = $label . ' is not connected, so product ' . $ttId . ' could not be deleted there.';
                    continue;
                }
                try {
                    $result = $this->client->deleteProducts($c['app_key'], $c['app_secret'], $c['token'], [$ttId], $c['shop_cipher'] ?: null);
                } catch (\Throwable $e) {
                    $result = ['ok' => false, 'status' => 0, 'body' => ['message' => \App\Support\TransportError::plain($e, 'TikTok Shop')]];
                }
                TikTokApiLog::safeCreate([
                    'tiktok_setting_id' => $storeId,
                    'pack' => 'tiktok.listings', 'method' => 'DELETE', 'api_path' => '/product/202309/products', 'auth_required' => true,
                    'request_params' => ['product_ids' => [$ttId], 'product_id' => $productId],
                    'response_status' => $result['status'] ?? 0, 'ok' => (bool) ($result['ok'] ?? false),
                    'response_body' => $result['body'] ?? [], 'user_id' => auth()->id(),
                ]);
                $ok = ($result['ok'] ?? false) && (int) ($result['body']['code'] ?? -1) === 0;
                if (!$ok) {
                    $text = \App\Support\MarketplaceAnswer::errorText('TikTok Shop', $result);
                    if (!MarketplaceLink::looksMissingRemotely($text)) {
                        $errors[] = $label . ': ' . $text;
                        continue;
                    }
                }
                ActivityLogger::log('deleted', 'TikTok Product', $productId, 'Deleted product ' . $ttId . ' from ' . $label);
                $removed[] = $label . ' product ' . $ttId;
            }

            TikTokListing::query()->where('product_id', $productId)->where('tiktok_setting_id', $storeId)->delete();
            TikTokProductGroupProduct::query()->where('product_id', $productId)
                ->whereIn('tiktok_product_group_id', TikTokProductGroup::query()->where('tiktok_setting_id', $storeId)->select('id'))
                ->delete();
        }

        if ($errors === []) {
            TikTokListing::query()->where('product_id', $productId)->delete();
            TikTokProductGroupProduct::query()->where('product_id', $productId)->delete();
        }

        return ['ok' => $errors === [], 'removed' => $removed, 'kept' => [], 'errors' => $errors];
    }

    /** @see \App\Integrations\Contracts\ProductRemover::strandedProductIds */
    public function stranded(): array
    {
        $pfx = (string) config('catalog.prefix');
        $ids = [];
        foreach (['tiktok_listings', 'tiktok_product_group_products'] as $table) {
            $ids = array_merge($ids, DB::table($table)
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from($pfx . 'product as op')->whereColumn('op.product_id', $table . '.product_id'))
                ->distinct()->pluck('product_id')->map(fn ($v) => (int) $v)->all());
        }

        return array_values(array_unique($ids));
    }

    /**
     * Every (store, TikTok product id) the products sit on: the listing
     * row's own id, and the group pivots' ids for stores whose listing
     * row carries none (the legacy shape).
     *
     * @return list<array{product_id:int, store_id:int, tiktok_product_id:string, live_status:?string}>
     */
    private function holdings(array $productIds): array
    {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));
        if ($productIds === []) {
            return [];
        }
        $out = [];
        $seen = [];
        $add = function (int $pid, int $storeId, string $ttId, ?string $status) use (&$out, &$seen) {
            $key = $pid . ':' . $storeId . ':' . $ttId;
            if ($ttId === '' || isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
            $out[] = ['product_id' => $pid, 'store_id' => $storeId, 'tiktok_product_id' => $ttId, 'live_status' => $status];
        };

        foreach (TikTokListing::query()->whereIn('product_id', $productIds)->whereNotNull('tiktok_product_id')->get() as $l) {
            $add((int) $l->product_id, (int) $l->tiktok_setting_id, (string) $l->tiktok_product_id, $l->live_status);
        }
        $pivots = DB::table('tiktok_product_group_products as pv')
            ->join('tiktok_product_groups as g', 'g.id', '=', 'pv.tiktok_product_group_id')
            ->whereIn('pv.product_id', $productIds)->whereNotNull('pv.tiktok_product_id')
            ->get(['pv.product_id', 'pv.tiktok_product_id', 'g.tiktok_setting_id']);
        foreach ($pivots as $p) {
            $add((int) $p->product_id, (int) $p->tiktok_setting_id, (string) $p->tiktok_product_id, null);
        }

        return $out;
    }

    private function storeName(int $storeId): string
    {
        $s = TikTokSetting::query()->find($storeId);

        return (string) ($s?->store_name ?: ($s?->shop_name ?: 'TikTok store #' . $storeId));
    }

    /** @return array{app_key:string, app_secret:string, token:string, shop_cipher:string}|null */
    private function credentials(int $storeId): ?array
    {
        $s = TikTokSetting::query()->find($storeId);
        if (!$s) {
            return null;
        }
        $d = $s->decrypted();
        $sandbox = $s->mode === 'sandbox';
        $c = [
            'app_key' => (string) ($sandbox ? ($d->sandbox_app_key ?? '') : ($d->app_key ?? '')),
            'app_secret' => (string) ($sandbox ? ($d->sandbox_app_secret ?? '') : ($d->app_secret ?? '')),
            'token' => (string) ($sandbox ? ($d->sandbox_access_token ?? '') : ($d->access_token ?? '')),
            'shop_cipher' => (string) ($sandbox ? ($s->sandbox_shop_cipher ?? '') : ($s->shop_cipher ?? '')),
        ];

        return ($c['app_key'] === '' || $c['app_secret'] === '' || $c['token'] === '') ? null : $c;
    }
}
