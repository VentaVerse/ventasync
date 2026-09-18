<?php

namespace App\Integrations\Listings;

use App\Models\WatermarkTemplate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WHICH MARK EACH ROW OF A LISTINGS PAGE WEARS.
 *
 * A page shows the picture as it goes up, mark and all, so nobody is quietly
 * unaware that a watermark is on or off. Resolving that per row would be two
 * queries a row, so every listing and every group on the page is read once and
 * answered from memory.
 *
 * The order is the listing's own template, then the one its product group
 * lends, which is the order every other setting on a listing already keeps.
 */
final class ListingMarks
{
    /** @var array<string, ?WatermarkTemplate> */
    private array $templates = [];

    /** @var array<int, ?int> */
    private array $byProduct = [];

    /**
     * @param  list<int>  $productIds  the rows on this page
     * @param  string  $listingsTable  where this channel keeps its listings
     * @param  string  $storeColumn  how a listing names its store, or '' when it keeps one
     */
    public function __construct(
        private string $channel,
        private int $storeId,
        array $productIds = [],
        string $listingsTable = '',
        string $storeColumn = '',
        string $groupsTable = '',
        string $membersTable = '',
        string $groupKey = '',
        string $groupStoreColumn = '',
    ) {
        if ($productIds === [] || ! Schema::hasTable($listingsTable)) {
            return;
        }

        $own = DB::table($listingsTable)
            ->whereIn('product_id', $productIds)
            ->when($storeColumn !== '', fn ($q) => $q->where($storeColumn, $this->storeId))
            ->whereNotNull('watermark_template_id')
            ->pluck('watermark_template_id', 'product_id');

        foreach ($own as $productId => $templateId) {
            $this->byProduct[(int) $productId] = (int) $templateId;
        }

        $missing = array_values(array_diff($productIds, array_keys($this->byProduct)));
        if ($missing === [] || ! Schema::hasTable($groupsTable) || ! Schema::hasTable($membersTable)) {
            return;
        }

        // What each product's group lends, for the rows that named none of their own.
        $lent = DB::table($membersTable . ' as m')
            ->join($groupsTable . ' as g', 'g.id', '=', 'm.' . $groupKey)
            ->whereIn('m.product_id', $missing)
            ->when($groupStoreColumn !== '', fn ($q) => $q->where('g.' . $groupStoreColumn, $this->storeId))
            ->whereNotNull('g.watermark_template_id')
            ->orderBy('m.id')
            ->pluck('g.watermark_template_id', 'm.product_id');

        foreach ($lent as $productId => $templateId) {
            $this->byProduct[(int) $productId] = (int) $templateId;
        }
    }

    /**
     * The picture this row shows: marked when a mark applies to it, plain
     * otherwise. A mark that cannot be drawn is never a reason to show
     * nothing, so the plain copy stands in.
     */
    public function thumb(int $productId, ?string $path): ?string
    {
        $path = trim((string) $path);
        if ($path === '') {
            return null;
        }

        return \App\Services\Media\ImageCache::markedUrl($path, $this->template($productId));
    }

    /** The template this row wears, read once per template rather than per row. */
    public function template(int $productId): ?WatermarkTemplate
    {
        $id = $this->byProduct[$productId] ?? null;
        if ($id === null) {
            return null;
        }

        $key = (string) $id;
        if (! array_key_exists($key, $this->templates)) {
            $this->templates[$key] = WatermarkTemplate::ofStore($this->channel, $this->storeId)->find($id);
        }

        return $this->templates[$key];
    }
}
