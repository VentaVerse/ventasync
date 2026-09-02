<?php

namespace Extensions\shopee\Services\Shopee;

use Extensions\shopee\Models\ShopeeApiLog;

/**
 * The live half of the individual listing page: what Shopee says about an
 * item RIGHT NOW. Fetched on every page load by design (the owner, 2026-08-23):
 * status must be the marketplace's answer, never a cached copy presented as
 * live. When Shopee does not answer, the page says so instead of
 * substituting stale data.
 */
class ShopeeLiveListing
{
    public function __construct(private ShopeeClient $client)
    {
    }

    /**
     * @return array{live: ?array, error: ?string}
     */
    public function fetch(array $auth, int $itemId): array
    {
        try {
            $r = $this->client->shopGet(
                $auth['mode'],
                (int) $auth['partner_id'], (string) $auth['partner_key'],
                (string) $auth['access_token'], (int) $auth['shop_id'],
                '/api/v2/product/get_item_base_info',
                ['item_id_list' => (string) $itemId]
            );
        } catch (\Throwable $e) {
            // A connection failure must degrade to the page's "did not
            // answer" note, never to a 500 on a page that otherwise works.
            return ['live' => null, 'error' => \App\Support\TransportError::plain($e, 'Shopee')];
        }

        ShopeeApiLog::safeCreate([
            'pack' => 'shopee.listings.live', 'method' => 'GET',
            'api_path' => '/api/v2/product/get_item_base_info', 'auth_required' => true,
            'request_params' => ['item_id_list' => (string) $itemId],
            'response_status' => $r['status'] ?? null,
            'ok' => (bool) ($r['ok'] ?? false),
            'response_body' => $r['body'] ?? null, 'user_id' => auth()->id(),
        ]);

        if (!($r['ok'] ?? false)) {
            $msg = is_array($r['body'] ?? null)
                ? (string) ($r['body']['message'] ?? ($r['body']['error'] ?? 'no response'))
                : 'no response';
            return ['live' => null, 'error' => 'Shopee did not answer: ' . $msg];
        }

        $item = (($r['body'] ?? [])['response']['item_list'] ?? [])[0] ?? null;
        if (!$item) {
            return ['live' => null, 'error' => 'Shopee holds no record for item ' . $itemId . '. It may have been deleted on the marketplace.'];
        }

        $live = [
            'item_status' => (string) ($item['item_status'] ?? ''),
            'item_name' => (string) ($item['item_name'] ?? ''),
            'item_sku' => (string) ($item['item_sku'] ?? ''),
            'has_model' => (bool) ($item['has_model'] ?? false),
            'has_promotion' => (bool) ($item['has_promotion'] ?? false),
            'update_time' => (int) ($item['update_time'] ?? 0),
            'image_url' => (string) (($item['image']['image_url_list'] ?? [])[0] ?? ''),
            'price' => $this->priceOf($item['price_info'] ?? []),
            'stock' => $this->stockOf($item),
            'models' => [],
        ];

        if ($live['has_model']) {
            $live['models'] = $this->fetchModels($auth, $itemId);
        }

        return ['live' => $live, 'error' => null];
    }

    /** Every item_status get_item_list can be asked for. */
    public const STATUSES = ['NORMAL', 'UNLIST', 'BANNED', 'REVIEWING', 'SELLER_DELETE', 'SHOPEE_DELETE'];

    /**
     * Refresh the status mirror: walk the shop's items per status (100 a
     * page) and write each link row's live_status. A link the shop no
     * longer returns under any status becomes MISSING. One walk serves
     * every listings and group page until the next; a page view costs
     * Shopee nothing (the owner, 2026-08-28).
     *
     * @return array{error: ?string, counts: array<string, int>, links: int}
     */
    public function refreshMirror(array $auth): array
    {
        $idsByStatus = [];
        foreach (self::STATUSES as $status) {
            try {
                $idsByStatus[$status] = $this->itemIds($auth, $status);
            } catch (\Throwable $e) {
                return ['error' => \App\Support\TransportError::plain($e, 'Shopee'), 'counts' => [], 'links' => 0];
            }
        }

        $now = now();
        $seen = [];
        $counts = [];
        foreach ($idsByStatus as $status => $ids) {
            $counts[$status] = 0;
            foreach (array_chunk($ids, 500) as $chunk) {
                $counts[$status] += \Extensions\shopee\Models\ShopeeProductLink::query()
                    ->whereIn('shopee_item_id', $chunk)
                    ->update(['live_status' => $status, 'live_checked_at' => $now]);
                foreach ($chunk as $id) {
                    $seen[$id] = true;
                }
            }
        }
        $missing = \Extensions\shopee\Models\ShopeeProductLink::query()
            ->whereNotIn('shopee_item_id', array_keys($seen) ?: [0])
            ->update(['live_status' => 'MISSING', 'live_checked_at' => $now]);
        if ($missing > 0) {
            $counts['MISSING'] = $missing;
        }

        return [
            'error' => null,
            'counts' => array_filter($counts),
            'links' => (int) \Extensions\shopee\Models\ShopeeProductLink::query()->count(),
        ];
    }

    /**
     * The status of specific items, one get_item_base_info per 50, and
     * MISSING for an item Shopee does not return. Used to fill the
     * mirror's blanks: a link created by a push or a match has no status
     * until someone asks, and the listings page asks once, for those rows
     * only, the first time it shows them.
     *
     * @return array<int, string>  item_id => item_status
     */
    public function statusesFor(array $auth, array $itemIds): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique(array_map('intval', $itemIds))), 50) as $chunk) {
            $r = $this->client->shopGet(
                $auth['mode'],
                (int) $auth['partner_id'], (string) $auth['partner_key'],
                (string) $auth['access_token'], (int) $auth['shop_id'],
                '/api/v2/product/get_item_base_info',
                ['item_id_list' => implode(',', $chunk)]
            );
            if (!($r['ok'] ?? false)) {
                throw new \RuntimeException(is_array($r['body'] ?? null)
                    ? (string) ($r['body']['message'] ?? ($r['body']['error'] ?? 'no response'))
                    : 'no response');
            }
            $answered = [];
            foreach ((($r['body'] ?? [])['response']['item_list'] ?? []) as $it) {
                $itemId = (int) ($it['item_id'] ?? 0);
                $out[$itemId] = (string) ($it['item_status'] ?? '');
                $answered[$itemId] = true;
            }
            foreach ($chunk as $askedId) {
                if (!isset($answered[$askedId])) {
                    $out[$askedId] = 'MISSING';
                }
            }
        }

        return $out;
    }

    /**
     * Write-through after a push (Phase B, #184): ask Shopee what the
     * pushed items' status actually is and write the mirror, so a product
     * pushed a minute ago never reads "Not checked yet" and never reads a
     * guess either - a fresh item can be NORMAL or REVIEWING and only
     * Shopee knows which. Seven of the ten push paths used to skip the
     * mirror entirely; they all call this now.
     *
     * Failure is swallowed on purpose: the push already succeeded, and
     * reporting it as failed because the confirming READ failed would be
     * a lie. The row simply stays blank and the next sweep fills it.
     */
    public function confirm(array $auth, array $itemIds): void
    {
        $itemIds = array_values(array_filter(array_map('intval', $itemIds)));
        if ($itemIds === []) {
            return;
        }
        try {
            $statuses = $this->statusesFor($auth, $itemIds);
        } catch (\Throwable) {
            return;
        }
        $now = now();
        foreach ($statuses as $itemId => $status) {
            if ($status === '' || $status === null) {
                continue;
            }
            \Extensions\shopee\Models\ShopeeProductLink::query()
                ->where('shopee_item_id', $itemId)
                ->update(['live_status' => $status, 'live_checked_at' => $now]);
        }
    }

    /**
     * Fill the mirror for links that were never checked. One call per 50,
     * only for the given links, and only once: after this they carry a
     * status and a timestamp like every other row.
     *
     * @param  \Illuminate\Support\Collection<int, \Extensions\shopee\Models\ShopeeProductLink>  $links
     * @return bool  whether anything was written
     */
    public function fillBlanks(array $auth, $links): bool
    {
        $blank = $links->filter(fn ($l) => $l->live_status === null && (int) $l->shopee_item_id > 0);
        if ($blank->isEmpty()) {
            return false;
        }
        try {
            $statuses = $this->statusesFor($auth, $blank->pluck('shopee_item_id')->all());
        } catch (\Throwable) {
            return false;
        }
        $now = now();
        foreach ($blank as $l) {
            $status = $statuses[(int) $l->shopee_item_id] ?? null;
            if ($status === null || $status === '') {
                continue;
            }
            \Extensions\shopee\Models\ShopeeProductLink::query()->whereKey($l->id)
                ->update(['live_status' => $status, 'live_checked_at' => $now]);
            $l->live_status = $status;
            $l->live_checked_at = $now;
        }

        return true;
    }

    /** Every item id in one status, 100 a page, up to 5,000 items. Throws when Shopee refuses. */
    public function itemIds(array $auth, string $itemStatus): array
    {
        $itemIds = [];
        $offset = 0;
        for ($pageN = 0; $pageN < 50; $pageN++) {
            $r = $this->client->shopGet(
                $auth['mode'],
                (int) $auth['partner_id'], (string) $auth['partner_key'],
                (string) $auth['access_token'], (int) $auth['shop_id'],
                '/api/v2/product/get_item_list',
                ['offset' => $offset, 'page_size' => 100, 'item_status' => $itemStatus]
            );
            if (!($r['ok'] ?? false)) {
                throw new \RuntimeException(is_array($r['body'] ?? null)
                    ? (string) ($r['body']['message'] ?? ($r['body']['error'] ?? 'no response'))
                    : 'no response');
            }
            $resp = ($r['body'] ?? [])['response'] ?? [];
            foreach (($resp['item'] ?? []) as $it) {
                if ($id = $it['item_id'] ?? null) {
                    $itemIds[] = (int) $id;
                }
            }
            if (!($resp['has_next_page'] ?? false) || empty($resp['item'] ?? [])) {
                break;
            }
            $offset = (int) ($resp['next_offset'] ?? $offset + 100);
        }

        return array_values(array_unique($itemIds));
    }

    private function fetchModels(array $auth, int $itemId): array
    {
        try {
            $r = $this->client->shopGet(
                $auth['mode'],
                (int) $auth['partner_id'], (string) $auth['partner_key'],
                (string) $auth['access_token'], (int) $auth['shop_id'],
                '/api/v2/product/get_model_list',
                ['item_id' => $itemId]
            );
        } catch (\Throwable) {
            return [];
        }

        if (!($r['ok'] ?? false)) {
            return [];
        }

        $models = (($r['body'] ?? [])['response']['model'] ?? []);

        return array_map(fn ($m) => [
            'model_id' => (int) ($m['model_id'] ?? 0),
            'model_sku' => (string) ($m['model_sku'] ?? ''),
            'name' => (string) ($m['model_name'] ?? ''),
            'price' => $this->priceOf($m['price_info'] ?? []),
            'stock' => $this->stockOf($m),
        ], $models);
    }

    /** @return array{original: ?float, current: ?float} */
    private function priceOf(array $priceInfo): array
    {
        $first = $priceInfo[0] ?? null;
        if (!is_array($first)) {
            return ['original' => null, 'current' => null];
        }
        return [
            'original' => isset($first['original_price']) ? (float) $first['original_price'] : null,
            'current' => isset($first['current_price']) ? (float) $first['current_price'] : null,
        ];
    }

    /**
     * Shopee has shipped three stock shapes across API revisions; read them
     * newest-first so the page works on whichever the shop's region returns.
     */
    private function stockOf(array $item): ?int
    {
        $v2 = $item['stock_info_v2'] ?? null;
        if (is_array($v2)) {
            $summary = $v2['summary_info']['total_available_stock'] ?? null;
            if ($summary !== null) {
                return (int) $summary;
            }
            $seller = ($v2['seller_stock'] ?? [])[0]['stock'] ?? null;
            if ($seller !== null) {
                return (int) $seller;
            }
        }
        $v1 = ($item['stock_info'] ?? [])[0]['current_stock'] ?? null;
        return $v1 !== null ? (int) $v1 : null;
    }
}
