<?php

namespace Extensions\ventacart\Services\VentaCart;

use Extensions\ventacart\Models\VentaCartListing;
use Extensions\ventacart\Models\VentaCartProductLink;
use Extensions\ventacart\Models\VentaCartSetting;
use Illuminate\Support\Collection;

class VentaCartListingMirror
{
    public const TABS = [
        'all' => ['All', null],
        'active' => ['Active', VentaCartListing::STATUS_ACTIVE],
        'unlisted' => ['Unlisted', VentaCartListing::STATUS_INACTIVE],
        'missing' => ['Not on the store', VentaCartListing::STATUS_MISSING],
    ];

    public const STATUS_MAP = [
        VentaCartListing::STATUS_ACTIVE => ['Active', 'success'],
        VentaCartListing::STATUS_INACTIVE => ['Unlisted', 'warning'],
        VentaCartListing::STATUS_MISSING => ['Not on the store', 'danger'],
    ];

    public function __construct(
        private VentaCartSetting $setting,
        private VentaCartClient $client,
    ) {
    }

    public static function for(VentaCartSetting $setting): self
    {
        return new self($setting, new VentaCartClient($setting));
    }

    public function refresh(): array
    {
        $byId = [];
        $bySku = [];
        $page = 1;

        do {
            $result = $this->client->get('products', ['per_page' => 200, 'page' => $page]);
            if (! ($result['ok'] ?? false)) {
                $status = (int) ($result['status'] ?? 0);

                return [
                    'error' => $status === 0
                        ? 'The store could not be reached, so the statuses were not refreshed.'
                        : VentaCartProductPush::failureText($result, ''),
                    'counts' => [],
                    'links' => 0,
                ];
            }

            $body = $result['body'] ?? [];
            $items = is_array($body['data'] ?? null) ? $body['data'] : (array_is_list($body) ? $body : []);
            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $status = array_key_exists('status', $item)
                    ? ($item['status'] ? VentaCartListing::STATUS_ACTIVE : VentaCartListing::STATUS_INACTIVE)
                    : null;
                $facts = [
                    'status' => $status,
                    'price' => isset($item['price']) ? (float) $item['price'] : null,
                    'quantity' => isset($item['quantity']) ? (int) $item['quantity'] : null,
                ];
                if (! empty($item['id'])) {
                    $byId[(int) $item['id']] = $facts;
                }
                if (! empty($item['sku'])) {
                    $bySku[mb_strtolower(trim((string) $item['sku']))] = $facts;
                }
            }

            $lastPage = (int) ($body['last_page'] ?? 1);
            $page++;
        } while ($page <= $lastPage && $items !== []);

        $now = now();
        $counts = [];
        $links = VentaCartProductLink::where('ventacart_setting_id', $this->setting->id)->get();

        foreach ($links as $link) {
            $facts = $byId[(int) $link->ventacart_product_id]
                ?? $bySku[mb_strtolower(trim((string) $link->sku))]
                ?? null;

            $status = $facts['status'] ?? VentaCartListing::STATUS_MISSING;
            if ($facts !== null && $status === null) {
                continue;
            }

            VentaCartListing::query()->updateOrCreate(
                ['ventacart_setting_id' => $this->setting->id, 'product_id' => (int) $link->product_id],
                [
                    'live_status' => $status,
                    'live_checked_at' => $now,
                    'live_price' => $facts['price'] ?? null,
                    'live_quantity' => $facts['quantity'] ?? null,
                ]
            );
            $counts[$status] = ($counts[$status] ?? 0) + 1;
        }

        return ['error' => null, 'counts' => $counts, 'links' => $links->count()];
    }

    public function fillBlanks(Collection $links, int $limit = 10, ?string &$error = null): int
    {
        $store = (int) $this->setting->id;
        $ids = $links->keys()->all();
        $checked = VentaCartListing::query()->where('ventacart_setting_id', $store)
            ->whereIn('product_id', $ids ?: [0])->whereNotNull('live_status')
            ->pluck('product_id')->map(fn ($v) => (int) $v)->all();

        $push = new VentaCartProductPush($this->setting, $this->client);
        $filled = 0;

        foreach ($links as $productId => $link) {
            if (in_array((int) $productId, $checked, true)) {
                continue;
            }
            if ($filled >= $limit) {
                break;
            }

            $live = $push->read((int) $productId);
            if ($live['state'] === 'unreachable') {
                $error = (string) ($live['message'] ?? '');
                break;
            }

            $row = VentaCartListing::query()->firstOrNew(['ventacart_setting_id' => $store, 'product_id' => (int) $productId]);
            $row->mirror($live['status'], $live['price'], $live['quantity']);
            if ($live['state'] === 'found') {
                $push->rememberHeld((int) $productId, array_column($live['variants'], 'sku'));
            }
            $filled++;
        }

        return $filled;
    }

    public function counts(): array
    {
        $store = (int) $this->setting->id;
        $linked = VentaCartProductLink::where('ventacart_setting_id', $store)->pluck('product_id')->map(fn ($v) => (int) $v)->all();

        $byStatus = VentaCartListing::query()
            ->where('ventacart_setting_id', $store)
            ->whereIn('product_id', $linked ?: [0])
            ->whereNotNull('live_status')
            ->selectRaw('live_status, COUNT(*) as n')
            ->groupBy('live_status')
            ->pluck('n', 'live_status');

        $out = [];
        foreach (self::TABS as $key => [$label, $status]) {
            if ($status !== null) {
                $out[$key] = (int) ($byStatus[$status] ?? 0);
            }
        }

        return $out;
    }

    public function checkedAt(): ?\Illuminate\Support\Carbon
    {
        $max = VentaCartListing::query()->where('ventacart_setting_id', $this->setting->id)->max('live_checked_at');

        return $max ? \Illuminate\Support\Carbon::parse($max) : null;
    }
}
