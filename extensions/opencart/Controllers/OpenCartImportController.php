<?php

namespace Extensions\OpenCart\Controllers;

use App\Http\Controllers\Controller;
use Extensions\OpenCart\Models\OpenCartListingLink;
use Extensions\OpenCart\Models\OpenCartStore;
use Extensions\OpenCart\Services\OpenCart\OpenCartClient;
use Extensions\OpenCart\Services\OpenCart\OpenCartItemImport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OpenCartImportController extends Controller
{
    public function importPage(Request $request, int $store)
    {
        $setting = OpenCartStore::findOrFail($store);

        $fetched = null;
        $fetchError = null;
        if ($request->boolean('fetch')) {
            [$fetched, $fetchError] = $this->fetchUnmatched($setting);
        }

        return view('ext-opencart::products.import', [
            'setting' => $setting,
            'store' => $store,
            'fetched' => $fetched,
            'fetchError' => $fetchError,
        ]);
    }

    private function fetchUnmatched(OpenCartStore $store): array
    {
        if (! $store->enabled) {
            return [null, 'That OpenCart store is turned off, so nothing was fetched.'];
        }

        $client = new OpenCartClient($store);
        $known = $this->knownSkus();
        $linked = OpenCartListingLink::query()
            ->where('store_id', $store->id)
            ->pluck('oc_product_id')
            ->flip();
        $base = rtrim((string) $store->base_url, '/');

        $found = 0;
        $rows = [];
        $page = 1;
        do {
            $answer = $client->getProducts($page, 100);
            if (! ($answer['ok'] ?? false)) {
                return [null, 'OpenCart did not answer the fetch: '
                    . \App\Support\MarketplaceAnswer::plain('OpenCart', $answer)
                    . ($found > 0 ? " ({$found} products were read before it stopped.)" : '')];
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
                $rows[] = [
                    'ref' => (int) ($item['product_id'] ?? 0),
                    'sku' => $sku,
                    'channel_id' => (int) ($item['product_id'] ?? 0),
                    'name' => Str::limit(html_entity_decode((string) ($item['name'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'), 250, ''),
                    'image_url' => $rel !== '' ? $base . '/image/' . ltrim($rel, '/') : '',
                    'variants' => is_array($item['option_values'] ?? null) ? count($item['option_values']) : 0,
                ];
            }
            $page++;
        } while ($page <= $totalPages && $page <= 100);

        return [['found' => $found, 'rows' => $rows], null];
    }

    public function importOne(Request $request, int $store, OpenCartItemImport $import)
    {
        $data = $request->validate(['ref' => 'required|integer|min:1']);
        $setting = OpenCartStore::findOrFail($store);

        $r = $import->import($setting, (int) $data['ref']);

        return redirect()->back()->with($r['ok'] ? 'status' : 'error', $r['message']);
    }

    public function importSelected(Request $request, int $store, OpenCartItemImport $import)
    {
        $data = $request->validate(['refs' => 'required|array|min:1', 'refs.*' => 'integer|min:1']);
        $setting = OpenCartStore::findOrFail($store);

        $done = 0;
        $failed = [];
        $refs = array_values(array_unique(array_map('intval', $data['refs'])));
        foreach ($refs as $ref) {
            $r = $import->import($setting, $ref);
            if ($r['ok'] ?? false) {
                $done++;
            } else {
                $failed[] = '#' . $ref . ': ' . ($r['message'] ?? 'not imported');
            }
        }

        $line = \App\Support\BulkImportSummary::line($done, count($refs), $failed);

        return redirect()->back()->with($line['tone'], $line['message']);
    }

    public function linkItem(Request $request, int $store)
    {
        OpenCartStore::findOrFail($store);
        $data = $request->validate([
            'ref' => 'required|integer|min:1',
            'sku' => 'nullable|string|max:191',
            'product_id' => 'required|integer|min:1',
        ]);
        if (! DB::table('products')->where('product_id', (int) $data['product_id'])->exists()) {
            return redirect()->back()->with('error', 'That Master Catalog product does not exist.');
        }

        OpenCartListingLink::updateOrCreate(
            ['store_id' => $store, 'oc_product_id' => (int) $data['ref']],
            ['product_id' => (int) $data['product_id'], 'sku' => trim((string) ($data['sku'] ?? '')) ?: null]
        );

        return redirect()->back()->with('status', 'Linked OpenCart product #' . $data['ref'] . ' to Master Catalog product #' . $data['product_id'] . '.');
    }

    public function searchCatalog(Request $request, int $store)
    {
        OpenCartStore::findOrFail($store);
        $q = trim((string) $request->query('q', ''));
        if (strlen($q) < 2) {
            return response()->json(['items' => []]);
        }
        $langId = (int) config('catalog.default_language_id');
        $rows = DB::table('products as p')
            ->join('product_descriptions as pd', function ($j) use ($langId) {
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

        return collect()
            ->merge(DB::table('products')->whereNotNull('sku')->where('sku', '!=', '')->pluck('sku'))
            ->merge(DB::table('products')->whereNotNull('model')->where('model', '!=', '')->pluck('model'))
            ->merge(DB::table('product_variation_values')->whereNotNull('sku')->where('sku', '!=', '')->pluck('sku'))
            ->merge(DB::table('product_variations')->whereNotNull('sku')->where('sku', '!=', '')->pluck('sku'))
            ->map(fn ($v) => strtolower(trim((string) $v)))
            ->flip();
    }
}
