<?php

namespace App\Http\Controllers;

use App\Models\LazadaCategoryTemplate;
use App\Models\LazadaCategory;
use App\Models\LazadaProduct;
use App\Models\LazadaProductAttribute;
use App\Models\LazadaProfile;
use App\Models\LazadaProfileAttribute;
use App\Models\LazadaSetting;
use App\Services\Lazada\LazadaClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LazadaProfileController extends Controller
{
    public function index()
    {
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $profiles = LazadaProfile::query()->orderByDesc('id')->get();

        // Gather all referenced catalog category IDs
        $catIds = $profiles->pluck('catalog_category_ids')->filter()->flatten()->unique()->values()->all();
        $categoryNames = collect();
        if (!empty($catIds)) {
            $categoryNames = DB::table($pfx . 'category_description')
                ->whereIn('category_id', $catIds)
                ->where('language_id', $langId)
                ->pluck('name', 'category_id');
        }

        // Gather all referenced manufacturer IDs
        $mfgIds = $profiles->pluck('manufacturer_ids')->filter()->flatten()->unique()->values()->all();
        $manufacturerNames = collect();
        if (!empty($mfgIds)) {
            $manufacturerNames = DB::table($pfx . 'manufacturer')
                ->whereIn('manufacturer_id', $mfgIds)
                ->pluck('name', 'manufacturer_id');
        }

        // Lazada category names
        $lazCatIds = $profiles->pluck('lazada_category_id')->filter()->unique()->values()->all();
        $lazadaCategoryNames = collect();
        if (!empty($lazCatIds)) {
            $lazadaCategoryNames = LazadaCategory::query()
                ->whereIn('category_id', $lazCatIds)
                ->pluck('name', 'category_id');
        }

        // Product counts per profile (via pivot)
        $productCounts = DB::table('lazada_product_profile')
            ->selectRaw('lazada_profile_id, COUNT(*) as cnt')
            ->groupBy('lazada_profile_id')
            ->pluck('cnt', 'lazada_profile_id');

        return view('lazada.profiles.index', [
            'profiles' => $profiles,
            'categoryNames' => $categoryNames,
            'manufacturerNames' => $manufacturerNames,
            'lazadaCategoryNames' => $lazadaCategoryNames,
            'productCounts' => $productCounts,
        ]);
    }

    public function create()
    {
        return $this->form(new LazadaProfile(), 'create');
    }

    public function edit(int $id)
    {
        $profile = LazadaProfile::query()->findOrFail($id);
        return $this->form($profile, 'edit');
    }

    private function form(LazadaProfile $profile, string $mode)
    {
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $catalogCategories = DB::table($pfx . 'category_description')
            ->where('language_id', $langId)
            ->orderBy('name')
            ->get(['category_id', 'name']);

        $manufacturers = DB::table($pfx . 'manufacturer')
            ->orderBy('name')
            ->get(['manufacturer_id', 'name']);

        $lazadaCategories = LazadaCategory::query()->orderBy('name')->limit(5000)->get();

        $productCount = $this->countMatchingProducts($profile);

        // Template & attributes
        $template = null;
        $attributes = [];
        $saved = [];
        if ($profile->lazada_category_id) {
            $template = LazadaCategoryTemplate::query()
                ->where('region', $this->region())
                ->where('primary_category_id', (int) $profile->lazada_category_id)
                ->first();

            if ($template && $template->template_body) {
                $attributes = $this->extractAttributes($template->template_body);
            }

            if ($profile->exists) {
                $saved = LazadaProfileAttribute::query()
                    ->where('lazada_profile_id', $profile->id)
                    ->pluck('value', 'attribute_key')
                    ->toArray();
            }
        }

        $selectedBrandName = '';
        if ($profile->brand_id) {
            $brand = \App\Models\LazadaBrand::query()->where('brand_id', $profile->brand_id)->first();
            if ($brand) $selectedBrandName = $brand->name;
        }

        return view('lazada.profiles.form', [
            'profile' => $profile,
            'mode' => $mode,
            'catalogCategories' => $catalogCategories,
            'manufacturers' => $manufacturers,
            'lazadaCategories' => $lazadaCategories,
            'productCount' => $productCount,
            'template' => $template,
            'attributes' => $attributes,
            'saved' => $saved,
            'selectedBrandName' => $selectedBrandName,
            'erpSourceFields' => LazadaProductController::ERP_SOURCE_FIELDS,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'catalog_category_ids' => 'nullable|array',
            'catalog_category_ids.*' => 'integer|min:1',
            'manufacturer_ids' => 'nullable|array',
            'manufacturer_ids.*' => 'integer|min:1',
            'lazada_category_id' => 'nullable|integer|min:1',
            'brand_id' => 'nullable|integer|min:1',
            'no_brand' => 'nullable',
            'markup_fixed' => 'nullable|numeric|min:0',
            'markup_percent' => 'nullable|numeric|min:0',
        ]);

        $catIds = array_filter($data['catalog_category_ids'] ?? []);
        $mfgIds = array_filter($data['manufacturer_ids'] ?? []);

        $profile = LazadaProfile::create([
            'name' => $data['name'],
            'catalog_category_ids' => !empty($catIds) ? array_values(array_map('intval', $catIds)) : null,
            'manufacturer_ids' => !empty($mfgIds) ? array_values(array_map('intval', $mfgIds)) : null,
            'lazada_category_id' => $data['lazada_category_id'] ?? null,
            'brand_id' => $data['brand_id'] ?? null,
            'brand_name_override' => !empty($request->input('no_brand')) ? 'No Brand' : null,
            'markup_fixed' => $data['markup_fixed'] ?? null,
            'markup_percent' => $data['markup_percent'] ?? null,
        ]);

        $this->saveProfileAttributes($profile, (array) $request->input('attributes', []));

        $syncMsg = '';
        if ($profile->lazada_category_id) {
            $syncMsg = ' ' . $this->autoSyncProducts($profile);
        }

        return redirect()->route('lazada.profiles.edit', $profile->id)
            ->with('status', 'Profile created.' . $syncMsg);
    }

    public function update(Request $request, int $id)
    {
        $profile = LazadaProfile::query()->findOrFail($id);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'catalog_category_ids' => 'nullable|array',
            'catalog_category_ids.*' => 'integer|min:1',
            'manufacturer_ids' => 'nullable|array',
            'manufacturer_ids.*' => 'integer|min:1',
            'lazada_category_id' => 'nullable|integer|min:1',
            'brand_id' => 'nullable|integer|min:1',
            'no_brand' => 'nullable',
            'markup_fixed' => 'nullable|numeric|min:0',
            'markup_percent' => 'nullable|numeric|min:0',
        ]);

        $catIds = array_filter($data['catalog_category_ids'] ?? []);
        $mfgIds = array_filter($data['manufacturer_ids'] ?? []);

        $profile->update([
            'name' => $data['name'],
            'catalog_category_ids' => !empty($catIds) ? array_values(array_map('intval', $catIds)) : null,
            'manufacturer_ids' => !empty($mfgIds) ? array_values(array_map('intval', $mfgIds)) : null,
            'lazada_category_id' => $data['lazada_category_id'] ?? null,
            'brand_id' => $data['brand_id'] ?? null,
            'brand_name_override' => !empty($request->input('no_brand')) ? 'No Brand' : null,
            'markup_fixed' => $data['markup_fixed'] ?? null,
            'markup_percent' => $data['markup_percent'] ?? null,
        ]);

        $this->saveProfileAttributes($profile, (array) $request->input('attributes', []));

        $syncMsg = '';
        if ($profile->lazada_category_id) {
            $syncMsg = ' ' . $this->autoSyncProducts($profile);
        }

        return redirect()->route('lazada.profiles.edit', $profile->id)
            ->with('status', 'Profile saved.' . $syncMsg);
    }

    public function destroy(int $id)
    {
        $profile = LazadaProfile::query()->findOrFail($id);

        // Get product IDs linked to this profile before deletion
        $linkedProductIds = DB::table('lazada_product_profile')
            ->where('lazada_profile_id', $profile->id)
            ->pluck('lazada_product_id')
            ->toArray();

        // Delete profile (CASCADE removes its pivot rows)
        $profile->delete();

        // Check which products no longer have any profile links
        $removed = 0;
        $kept = 0;
        if (!empty($linkedProductIds)) {
            $stillLinked = DB::table('lazada_product_profile')
                ->whereIn('lazada_product_id', $linkedProductIds)
                ->pluck('lazada_product_id')
                ->unique()
                ->toArray();

            $orphanIds = array_diff($linkedProductIds, $stillLinked);

            if (!empty($orphanIds)) {
                $removed = LazadaProduct::query()->whereIn('id', $orphanIds)->delete();
            }
            $kept = count($stillLinked);
        }

        $parts = ['Profile "' . e($profile->name) . '" deleted.'];
        if ($removed > 0) $parts[] = "{$removed} product(s) removed.";
        if ($kept > 0) $parts[] = "{$kept} product(s) still linked to other profiles.";

        return redirect()->route('lazada.profiles.index')
            ->with('status', implode(' ', $parts));
    }

    public function syncProducts(int $id)
    {
        $profile = LazadaProfile::query()->findOrFail($id);

        if (!$profile->lazada_category_id) {
            return redirect()->route('lazada.profiles.edit', $profile->id)
                ->with('status', 'Set a Lazada category before syncing products.');
        }

        $productIds = $this->getMatchingProductIds($profile);

        if (empty($productIds)) {
            return redirect()->route('lazada.profiles.edit', $profile->id)
                ->with('status', 'No matching products found.');
        }

        // Find existing LazadaProducts for these catalog product IDs
        $existingMap = LazadaProduct::query()
            ->whereIn('product_id', $productIds)
            ->pluck('id', 'product_id');

        $added = 0;
        $linked = 0;

        $profileAttrs = LazadaProfileAttribute::query()
            ->where('lazada_profile_id', $profile->id)
            ->get();

        // IDs already linked to THIS profile
        $alreadyLinked = DB::table('lazada_product_profile')
            ->where('lazada_profile_id', $profile->id)
            ->pluck('lazada_product_id')
            ->toArray();

        foreach ($productIds as $pid) {
            if ($existingMap->has($pid)) {
                // Product exists — link to this profile if not already
                $lpId = $existingMap->get($pid);
                if (!in_array($lpId, $alreadyLinked)) {
                    DB::table('lazada_product_profile')->insert([
                        'lazada_product_id' => $lpId,
                        'lazada_profile_id' => $profile->id,
                    ]);
                    $linked++;
                }
                continue;
            }

            $lp = LazadaProduct::create([
                'product_id' => $pid,
                'primary_category_id' => $profile->lazada_category_id,
                'brand_id' => $profile->brand_id,
                'brand_name_override' => $profile->brand_name_override,
            ]);

            // Create pivot link
            DB::table('lazada_product_profile')->insert([
                'lazada_product_id' => $lp->id,
                'lazada_profile_id' => $profile->id,
            ]);

            foreach ($profileAttrs as $pa) {
                LazadaProductAttribute::create([
                    'lazada_product_id' => $lp->id,
                    'attribute_key' => $pa->attribute_key,
                    'value' => $pa->value,
                ]);
            }

            $added++;
        }

        $skipped = count($productIds) - $added - $linked;
        $parts = [];
        if ($added > 0) $parts[] = "{$added} product(s) added";
        if ($linked > 0) $parts[] = "{$linked} linked to this profile";
        if ($skipped > 0) $parts[] = "{$skipped} already linked";

        return redirect()->route('lazada.profiles.edit', $profile->id)
            ->with('status', implode(', ', $parts) . '.');
    }

    public function syncTemplate(Request $request, int $id, LazadaClient $client)
    {
        $profile = LazadaProfile::query()->findOrFail($id);

        $request->validate(['lazada_category_id' => 'required|integer|min:1']);
        $lazadaCategoryId = (int) $request->input('lazada_category_id');
        $profile->update(['lazada_category_id' => $lazadaCategoryId]);

        $setting = $this->decryptSetting(LazadaSetting::query()->first());
        if (!$setting || !$setting->region || !$setting->app_key || !$setting->app_secret) {
            return redirect()->route('lazada.profiles.edit', $profile->id)
                ->with('status', 'Missing Lazada settings.');
        }

        $apiPath = '/category/attributes/get';
        $timestamp = (string) round(microtime(true) * 1000);
        $params = [
            'app_key' => (string) $setting->app_key,
            'sign_method' => 'sha256',
            'timestamp' => $timestamp,
            'primary_category_id' => (string) $lazadaCategoryId,
        ];
        $params['sign'] = $client->sign($apiPath, $params, (string) $setting->app_secret);
        $result = $client->get((string) $setting->region, $apiPath, $params);

        LazadaCategoryTemplate::query()->updateOrCreate(
            ['region' => (string) $setting->region, 'primary_category_id' => $lazadaCategoryId],
            ['template_body' => $result['body'] ?? null, 'fetched_at' => now()]
        );

        return redirect()->route('lazada.profiles.edit', $profile->id)
            ->with('status', 'Category template synced.');
    }

    // ── Helpers ──────────────────────────────────────────────────────

    private function autoSyncProducts(LazadaProfile $profile): string
    {
        $matchingIds = $this->getMatchingProductIds($profile);

        // Find products currently linked to this profile (via pivot)
        $profileProductIds = DB::table('lazada_product_profile')
            ->where('lazada_profile_id', $profile->id)
            ->pluck('lazada_product_id')
            ->toArray();

        $profileProducts = LazadaProduct::query()
            ->whereIn('id', $profileProductIds)
            ->get(['id', 'product_id', 'lazada_item_id']);

        // Remove/unlink products that no longer match the filter
        $removed = 0;
        $unlinked = 0;
        foreach ($profileProducts as $lp) {
            if (!in_array($lp->product_id, $matchingIds)) {
                // Remove pivot link
                DB::table('lazada_product_profile')
                    ->where('lazada_product_id', $lp->id)
                    ->where('lazada_profile_id', $profile->id)
                    ->delete();

                // Check if product still has other profile links
                $otherLinks = DB::table('lazada_product_profile')
                    ->where('lazada_product_id', $lp->id)
                    ->exists();

                if (!$otherLinks && empty($lp->lazada_item_id)) {
                    // No other profiles and not uploaded — safe to delete
                    $lp->delete();
                    $removed++;
                } else {
                    $unlinked++;
                }
            }
        }

        // Find all product_ids that already exist in lazada_products (any profile)
        $existingMap = LazadaProduct::query()
            ->whereIn('product_id', $matchingIds)
            ->pluck('id', 'product_id');

        // Refresh which IDs are already linked to this profile
        $alreadyLinked = DB::table('lazada_product_profile')
            ->where('lazada_profile_id', $profile->id)
            ->pluck('lazada_product_id')
            ->toArray();

        // Add new products / link existing ones
        $added = 0;
        $linked = 0;
        $profileAttrs = LazadaProfileAttribute::query()
            ->where('lazada_profile_id', $profile->id)
            ->get();

        // Batch-fetch ERP prices for truly new products
        $pfx = (string) config('catalog.prefix');
        $newProductIds = array_values(array_diff($matchingIds, $existingMap->keys()->toArray()));
        $erpPrices = [];
        if (!empty($newProductIds)) {
            $erpPrices = DB::table($pfx . 'product')
                ->whereIn('product_id', $newProductIds)
                ->pluck('price', 'product_id')
                ->toArray();
        }

        foreach ($matchingIds as $pid) {
            if ($existingMap->has($pid)) {
                // Product exists — link to this profile if not already
                $lpId = $existingMap->get($pid);
                if (!in_array($lpId, $alreadyLinked)) {
                    DB::table('lazada_product_profile')->insert([
                        'lazada_product_id' => $lpId,
                        'lazada_profile_id' => $profile->id,
                    ]);
                    $linked++;
                }
                continue;
            }

            $basePrice = (float)($erpPrices[$pid] ?? 0);
            $finalPrice = LazadaProductController::computeFinalPrice(
                $basePrice, $profile->markup_fixed, $profile->markup_percent
            );

            $lp = LazadaProduct::create([
                'product_id' => $pid,
                'primary_category_id' => $profile->lazada_category_id,
                'brand_id' => $profile->brand_id,
                'brand_name_override' => $profile->brand_name_override,
                'total_price' => $finalPrice,
            ]);

            // Create pivot link
            DB::table('lazada_product_profile')->insert([
                'lazada_product_id' => $lp->id,
                'lazada_profile_id' => $profile->id,
            ]);

            foreach ($profileAttrs as $pa) {
                LazadaProductAttribute::create([
                    'lazada_product_id' => $lp->id,
                    'attribute_key' => $pa->attribute_key,
                    'value' => $pa->value,
                ]);
            }

            $added++;
        }

        $skipped = count($matchingIds) - $added - $linked;
        $parts = [];
        if ($added > 0) $parts[] = "{$added} product(s) added";
        if ($linked > 0) $parts[] = "{$linked} linked to this profile";
        if ($removed > 0) $parts[] = "{$removed} removed";
        if ($unlinked > 0) $parts[] = "{$unlinked} unlinked (already uploaded)";
        if ($skipped > 0) $parts[] = "{$skipped} already linked";

        return !empty($parts) ? implode(', ', $parts) . '.' : 'No product changes.';
    }

    private function getMatchingProductIds(LazadaProfile $profile): array
    {
        $pfx = (string) config('catalog.prefix');
        $catIds = $profile->catalog_category_ids ?? [];
        $mfgIds = $profile->manufacturer_ids ?? [];

        if (empty($catIds) && empty($mfgIds)) {
            return DB::table($pfx . 'product')->pluck('product_id')->toArray();
        }

        $query = DB::table($pfx . 'product as p')->select('p.product_id');

        if (!empty($catIds)) {
            $query->join($pfx . 'product_to_category as ptc', 'p.product_id', '=', 'ptc.product_id')
                ->whereIn('ptc.category_id', $catIds);
        }

        if (!empty($mfgIds)) {
            $query->whereIn('p.manufacturer_id', $mfgIds);
        }

        return $query->distinct()->pluck('p.product_id')->toArray();
    }

    private function countMatchingProducts(LazadaProfile $profile): int
    {
        $pfx = (string) config('catalog.prefix');
        $catIds = $profile->catalog_category_ids ?? [];
        $mfgIds = $profile->manufacturer_ids ?? [];

        if (empty($catIds) && empty($mfgIds)) {
            return DB::table($pfx . 'product')->count();
        }

        $query = DB::table($pfx . 'product as p');

        if (!empty($catIds)) {
            $query->join($pfx . 'product_to_category as ptc', 'p.product_id', '=', 'ptc.product_id')
                ->whereIn('ptc.category_id', $catIds);
        }

        if (!empty($mfgIds)) {
            $query->whereIn('p.manufacturer_id', $mfgIds);
        }

        return $query->distinct()->count('p.product_id');
    }

    private function saveProfileAttributes(LazadaProfile $profile, array $attrs): void
    {
        foreach ($attrs as $key => $value) {
            $key = trim((string) $key);
            if ($key === '' || strtolower($key) === 'brand') continue;

            if ($value === null || $value === '') {
                LazadaProfileAttribute::query()
                    ->where('lazada_profile_id', $profile->id)
                    ->where('attribute_key', $key)
                    ->delete();
            } else {
                LazadaProfileAttribute::query()->updateOrCreate(
                    ['lazada_profile_id' => $profile->id, 'attribute_key' => $key],
                    ['value' => (string) $value]
                );
            }
        }
    }

    private function region(): string
    {
        $setting = LazadaSetting::query()->first();
        return (string) ($setting->region ?? '');
    }

    private function decryptSetting(?LazadaSetting $setting): ?LazadaSetting
    {
        if (!$setting) return null;
        $copy = $setting->replicate();
        foreach (['app_secret', 'access_token', 'refresh_token', 'auth_code'] as $field) {
            try {
                if ($copy->{$field}) $copy->{$field} = decrypt($copy->{$field});
            } catch (\Throwable $e) {}
        }
        return $copy;
    }

    private function extractAttributes($body): array
    {
        if (!is_array($body)) return [];
        $data = $body['data'] ?? $body;
        if (is_array($data) && isset($data['attributes']) && is_array($data['attributes'])) {
            return $this->normalizeAttributes($data['attributes']);
        }
        if (is_array($data) && array_is_list($data)) {
            return $this->normalizeAttributes($data);
        }
        return [];
    }

    private function normalizeAttributes(array $raw): array
    {
        $out = [];
        foreach ($raw as $a) {
            if (!is_array($a)) continue;
            $name = (string) ($a['name'] ?? $a['attribute_name'] ?? '');
            $id = $a['id'] ?? $a['attribute_id'] ?? null;
            $required = (bool) ($a['is_mandatory'] ?? $a['isMandatory'] ?? $a['mandatory'] ?? $a['is_required'] ?? $a['required'] ?? false);
            $inputType = (string) ($a['input_type'] ?? $a['type'] ?? $a['inputType'] ?? 'text');
            $options = $a['options'] ?? $a['option_values'] ?? $a['values'] ?? null;
            $key = $name !== '' ? $name : (is_scalar($id) ? (string) $id : '');
            if ($key === '') continue;
            $out[] = [
                'key' => $key, 'name' => $name !== '' ? $name : $key,
                'required' => $required, 'input_type' => $inputType,
                'options' => is_array($options) ? $options : [],
            ];
        }
        return $out;
    }
}
