<?php

namespace Extensions\opencart\Controllers;

use App\Http\Controllers\Controller;
use Extensions\opencart\Models\OpenCartProductLink;
use Extensions\opencart\Models\OpenCartSetting;
use Extensions\opencart\Models\OpenCartUnmatchedItem;
use Extensions\opencart\Services\OpenCart\OpenCartClient;
use Extensions\opencart\Services\OpenCart\OpenCartItemImport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The Import page (cleanup point 11, the owner 2026-09-04): OpenCart joins
 * the other channels - its own page under the Catalog menu holding the
 * scan and the queue, Import / Link / Dismiss per row, store-scoped.
 * The api/erp store module this queue reads from ships in this repo
 * (upload-this-to-opencart/), so the shapes are certain.
 */
class OpenCartImportController extends Controller
{
    public function importPage(int $store)
    {
        $setting = OpenCartSetting::findOrFail($store);

        return view('ext-opencart::products.import', [
            'setting' => $setting,
            'store' => $store,
            'unmatchedItems' => OpenCartUnmatchedItem::query()
                ->where('opencart_setting_id', $store)
                ->where('status', 'unmatched')
                ->orderBy('name')
                ->get(),
        ]);
    }

    /**
     * Scan: page through the store's products and queue everything the
     * catalog cannot place - SKU (or model, OpenCart's habitual SKU field)
     * not on a product, a variation, or an existing link.
     */
    public function syncUnmatched(int $store)
    {
        $setting = OpenCartSetting::findOrFail($store);
        if (! $setting->enabled) {
            return redirect()->back()->with('error', 'That OpenCart store is turned off, so nothing was scanned.');
        }

        $client = new OpenCartClient($setting);
        $known = $this->knownSkus();
        $linked = OpenCartProductLink::query()
            ->where('opencart_setting_id', $store)
            ->pluck('oc_product_id')
            ->flip();
        $base = rtrim((string) $setting->base_url, '/');

        $found = 0;
        $queued = 0;
        $page = 1;
        do {
            $answer = $client->getProducts($page, 100);
            if (! ($answer['ok'] ?? false)) {
                return redirect()->back()->with('error', 'OpenCart did not answer the scan: '
                    . \App\Support\MarketplaceAnswer::plain('OpenCart', $answer)
                    . ($found > 0 ? " ({$found} products were read before it stopped.)" : ''));
            }
            $items = array_values((array) ($answer['body']['data'] ?? []));
            $totalPages = (int) ($answer['body']['pagination']['total_pages'] ?? $page);
            foreach ($items as $item) {
                $found++;
                if (isset($linked[(int) ($item['product_id'] ?? 0)])) {
                    continue;
                }
                $sku = trim((string) ($item['sku'] ?? '')) ?: trim((string) ($item['model'] ?? ''));
                if ($sku !== '' && isset($known[strtolower($sku)])) {
                    continue;
                }
                $rel = trim((string) ($item['image'] ?? ''));
                // The scan refreshes the FACTS, never the verdict: a dismissed
                // row stays dismissed and a linked one stays linked (the owner,
                // 2026-09-04 - updateOrCreate used to resurrect both every scan).
                $row = OpenCartUnmatchedItem::firstOrNew(['opencart_setting_id' => $store, 'oc_product_id' => (int) ($item['product_id'] ?? 0)]);
                if (! $row->exists) {
                    $row->status = 'unmatched';
                }
                $row->fill([
                        'sku' => $sku ?: null,
                        'name' => Str::limit(html_entity_decode((string) ($item['name'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'), 250, ''),
                        'price' => (float) ($item['price'] ?? 0),
                        'quantity' => (int) ($item['quantity'] ?? 0),
                        'image_url' => $rel !== '' ? $base . '/image/' . ltrim($rel, '/') : '',
                        'variant_count' => is_array($item['option_values'] ?? null) ? count($item['option_values']) : 0,
                ])->save();
                if ($row->status === 'unmatched') {
                    $queued++;
                }
            }
            $page++;
        } while ($page <= $totalPages && $page <= 100);

        return redirect()->back()->with('status',
            "Scanned {$found} products on " . ($setting->store_name ?: 'OpenCart') . '. '
            . ($queued > 0 ? "{$queued} not in the catalog are waiting below." : 'Everything matches a catalog SKU.'));
    }

    public function importUnmatched(int $store, int $unmatchedId, OpenCartItemImport $import)
    {
        $setting = OpenCartSetting::findOrFail($store);
        $row = OpenCartUnmatchedItem::query()
            ->where('opencart_setting_id', $store)->findOrFail($unmatchedId);

        $r = $import->import($setting, $row);

        return redirect()->back()->with($r['ok'] ? 'status' : 'error', $r['message']);
    }

    /** Import selected (the owner, 2026-09-04): the single import, per ticked row. */
    public function importSelectedUnmatched(Request $request, int $store, OpenCartItemImport $import)
    {
        $data = $request->validate(['unmatched_ids' => 'required|array|min:1|max:100', 'unmatched_ids.*' => 'integer|min:1']);

        $setting = OpenCartSetting::findOrFail($store);
        $rows = OpenCartUnmatchedItem::query()
            ->where('opencart_setting_id', $store)
            ->whereIn('id', $data['unmatched_ids'])->where('status', 'unmatched')->get();
        if ($rows->isEmpty()) {
            return redirect()->back()->with('error', 'None of the selected products are still waiting to be imported.');
        }

        $done = 0;
        $failed = [];
        foreach ($rows as $row) {
            $r = $import->import($setting, $row);
            if ($r['ok'] ?? false) {
                $done++;
            } else {
                $failed[] = ($row->name ?: $row->sku) . ': ' . ($r['message'] ?? 'not imported');
            }
        }

        $line = \App\Support\BulkImportSummary::line($done, $rows->count(), $failed);

        return redirect()->back()->with($line['tone'], $line['message']);
    }

    public function linkUnmatched(Request $request, int $store, int $unmatchedId)
    {
        OpenCartSetting::findOrFail($store);
        $row = OpenCartUnmatchedItem::query()
            ->where('opencart_setting_id', $store)->findOrFail($unmatchedId);

        $data = $request->validate(['product_id' => 'required|integer|min:1']);
        $pfx = (string) config('catalog.prefix');
        if (! DB::table($pfx . 'product')->where('product_id', (int) $data['product_id'])->exists()) {
            return redirect()->back()->with('error', 'That catalog product does not exist.');
        }

        OpenCartProductLink::updateOrCreate(
            ['opencart_setting_id' => $store, 'oc_product_id' => (int) $row->oc_product_id],
            ['product_id' => (int) $data['product_id'], 'sku' => $row->sku]
        );
        $row->update(['status' => 'linked', 'linked_product_id' => (int) $data['product_id']]);

        return redirect()->back()->with('status', 'Linked "' . ($row->name ?: $row->sku) . '" to catalog product #' . $data['product_id'] . '.');
    }

    public function dismissUnmatched(int $store, int $unmatchedId)
    {
        OpenCartSetting::findOrFail($store);
        OpenCartUnmatchedItem::query()
            ->where('opencart_setting_id', $store)->findOrFail($unmatchedId)
            ->update(['status' => 'dismissed']);

        return redirect()->back()->with('status', 'Dismissed. Scans will not list it again.');
    }

    /** The Link typeahead's catalog search, same JSON every channel serves. */
    public function searchCatalog(Request $request, int $store)
    {
        OpenCartSetting::findOrFail($store);
        $q = trim((string) $request->query('q', ''));
        if (strlen($q) < 2) {
            return response()->json(['items' => []]);
        }

        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');
        $rows = DB::table($pfx . 'product as p')
            ->join($pfx . 'product_description as pd', function ($j) use ($langId) {
                $j->on('p.product_id', '=', 'pd.product_id')->where('pd.language_id', '=', $langId);
            })
            ->where(function ($w) use ($q) {
                $w->where('pd.name', 'like', "%{$q}%")
                    ->orWhere('p.sku', 'like', "%{$q}%")
                    ->orWhere('p.model', 'like', "%{$q}%");
            })
            ->orderBy('p.product_id', 'desc')
            ->limit(20)
            ->get(['p.product_id', 'pd.name', 'p.sku', 'p.model']);

        return response()->json(['items' => $rows]);
    }

    private function knownSkus(): \Illuminate\Support\Collection
    {
        $pfx = (string) config('catalog.prefix');

        return collect()
            ->merge(DB::table($pfx . 'product')->whereNotNull('sku')->where('sku', '!=', '')->pluck('sku'))
            ->merge(DB::table($pfx . 'product')->whereNotNull('model')->where('model', '!=', '')->pluck('model'))
            ->merge(DB::table($pfx . 'product_option_value')->whereNotNull('sku')->where('sku', '!=', '')->pluck('sku'))
            ->merge(DB::table('product_option_combinations')->whereNotNull('sku')->where('sku', '!=', '')->pluck('sku'))
            ->map(fn ($v) => strtolower(trim((string) $v)))
            ->flip();
    }
}
