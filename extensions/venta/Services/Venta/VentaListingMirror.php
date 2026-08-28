<?php

namespace Extensions\venta\Services\Venta;

use Extensions\venta\Models\VentaListing;
use Extensions\venta\Models\VentaProductLink;
use Extensions\venta\Models\VentaSetting;
use Illuminate\Support\Collection;

/**
 * The listing status MIRROR for one store (#166 applied to Venta, #171):
 * what the store says each linked product is, kept on the listing row
 * (live_status, live_checked_at, live_price, live_quantity) so the index
 * and its strip read a table, not the store.
 *
 * Refreshed three ways, as on the marketplaces: on a schedule
 * (venta:refresh-listing-status, every 30 minutes once enabled), on
 * demand by the Refresh button on the index, and written through by
 * every push, unlist and relist the ERP makes. The index also fills its
 * own blanks: a linked row never checked is asked about once, up to ten
 * a view, so a product pushed a minute ago never reads "Not checked yet".
 */
class VentaListingMirror
{
    /** The index's tabs: key => [label, mirrored status or null for all]. */
    public const TABS = [
        'all' => ['All', null],
        'active' => ['Active', VentaListing::STATUS_ACTIVE],
        'unlisted' => ['Unlisted', VentaListing::STATUS_INACTIVE],
        'missing' => ['Not on the store', VentaListing::STATUS_MISSING],
    ];

    /** Mirrored status => [label, badge tone]. */
    public const STATUS_MAP = [
        VentaListing::STATUS_ACTIVE => ['Active', 'success'],
        VentaListing::STATUS_INACTIVE => ['Unlisted', 'warning'],
        VentaListing::STATUS_MISSING => ['Not on the store', 'danger'],
    ];

    public function __construct(
        private VentaSetting $setting,
        private VentaClient $client,
    ) {
    }

    public static function for(VentaSetting $setting): self
    {
        return new self($setting, new VentaClient($setting));
    }

    /**
     * One walk of the store's product list, written onto every linked
     * product's listing row. The list carries id, sku and status for up
     * to 200 products a page, so a shop of a thousand is five calls, not
     * a thousand. A link the walk does not find is MISSING: the store no
     * longer has it. Any page failing writes nothing, because a partial
     * walk would file every product after the failure as missing.
     *
     * @return array{error: ?string, counts: array<string, int>, links: int}
     */
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
                        : VentaProductPush::failureText($result, ''),
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
                    ? ($item['status'] ? VentaListing::STATUS_ACTIVE : VentaListing::STATUS_INACTIVE)
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
        $links = VentaProductLink::where('venta_setting_id', $this->setting->id)->get();

        foreach ($links as $link) {
            $facts = $byId[(int) $link->venta_product_id]
                ?? $bySku[mb_strtolower(trim((string) $link->sku))]
                ?? null;

            $status = $facts['status'] ?? VentaListing::STATUS_MISSING;
            if ($facts !== null && $status === null) {
                // The store answered without a status field: an older
                // storefront. Leave the status alone rather than guess.
                continue;
            }

            VentaListing::query()->updateOrCreate(
                ['venta_setting_id' => $this->setting->id, 'product_id' => (int) $link->product_id],
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

    /**
     * The blanks on one page, filled: linked rows with no status yet are
     * asked about once, up to $limit. Stops at the first non-answer so a
     * store that is down leaves the rest blank rather than guessed.
     *
     * @param  Collection<int, VentaProductLink>  $links  the page's links, keyed by product id
     * @return int how many were filled
     */
    public function fillBlanks(Collection $links, int $limit = 10): int
    {
        $store = (int) $this->setting->id;
        $ids = $links->keys()->all();
        $checked = VentaListing::query()->where('venta_setting_id', $store)
            ->whereIn('product_id', $ids ?: [0])->whereNotNull('live_status')
            ->pluck('product_id')->map(fn ($v) => (int) $v)->all();

        $push = new VentaProductPush($this->setting, $this->client);
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
                break;
            }

            $row = VentaListing::query()->firstOrNew(['venta_setting_id' => $store, 'product_id' => (int) $productId]);
            $row->mirror($live['status'], $live['price'], $live['quantity']);
            $filled++;
        }

        return $filled;
    }

    /**
     * The strip's buckets from the mirror, linked products only, keyed
     * the way TABS names them.
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        $store = (int) $this->setting->id;
        $linked = VentaProductLink::where('venta_setting_id', $store)->pluck('product_id')->map(fn ($v) => (int) $v)->all();

        $byStatus = VentaListing::query()
            ->where('venta_setting_id', $store)
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
        $max = VentaListing::query()->where('venta_setting_id', $this->setting->id)->max('live_checked_at');

        return $max ? \Illuminate\Support\Carbon::parse($max) : null;
    }
}
