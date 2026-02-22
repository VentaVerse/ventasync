<?php

namespace App\Http\Controllers;

use App\Models\ShopeeCategory;
use App\Models\ShopeeCategoryTemplate;
use App\Models\ShopeeLogistic;
use App\Models\ShopeeProfile;
use App\Models\ShopeeProfileAttribute;
use App\Models\ShopeeSetting;
use App\Services\Shopee\ShopeeClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ShopeeProfileController extends Controller
{
    public function index()
    {
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $profiles = ShopeeProfile::query()->orderByDesc('id')->get();

        // Gather referenced catalog category IDs
        $catIds = $profiles->pluck('catalog_category_ids')->filter()->flatten()->unique()->values()->all();
        $categoryNames = collect();
        if (!empty($catIds)) {
            $categoryNames = DB::table($pfx . 'category_description')
                ->whereIn('category_id', $catIds)
                ->where('language_id', $langId)
                ->pluck('name', 'category_id');
        }

        // Gather referenced manufacturer IDs
        $mfgIds = $profiles->pluck('manufacturer_ids')->filter()->flatten()->unique()->values()->all();
        $manufacturerNames = collect();
        if (!empty($mfgIds)) {
            $manufacturerNames = DB::table($pfx . 'manufacturer')
                ->whereIn('manufacturer_id', $mfgIds)
                ->pluck('name', 'manufacturer_id');
        }

        // Shopee category names
        $shopeeCatIds = $profiles->pluck('shopee_category_id')->filter()->unique()->values()->all();
        $shopeeCategoryNames = collect();
        if (!empty($shopeeCatIds)) {
            $shopeeCategoryNames = ShopeeCategory::query()
                ->whereIn('category_id', $shopeeCatIds)
                ->pluck('name', 'category_id');
        }

        return view('shopee.profiles.index', [
            'profiles' => $profiles,
            'categoryNames' => $categoryNames,
            'manufacturerNames' => $manufacturerNames,
            'shopeeCategoryNames' => $shopeeCategoryNames,
        ]);
    }

    public function create()
    {
        return $this->form(new ShopeeProfile(), 'create');
    }

    public function edit(int $id)
    {
        $profile = ShopeeProfile::query()->findOrFail($id);
        return $this->form($profile, 'edit');
    }

    private function form(ShopeeProfile $profile, string $mode)
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

        $shopeeCategories = ShopeeCategory::query()->orderBy('name')->limit(5000)->get();

        $productCount = $this->countMatchingProducts($profile);

        // Fetch logistics channels from Shopee API
        $logistics = $this->fetchLogistics();

        // Template & attributes
        $template = null;
        $attributes = [];
        $saved = [];
        if ($profile->shopee_category_id) {
            $template = ShopeeCategoryTemplate::query()
                ->where('category_id', (int) $profile->shopee_category_id)
                ->first();

            if ($template && $template->attributes) {
                $attributes = $this->extractAttributes($template->attributes);
            }

            if ($profile->exists) {
                $saved = ShopeeProfileAttribute::query()
                    ->where('shopee_profile_id', $profile->id)
                    ->pluck('value', 'attribute_key')
                    ->toArray();
            }
        }

        return view('shopee.profiles.form', [
            'profile' => $profile,
            'mode' => $mode,
            'catalogCategories' => $catalogCategories,
            'manufacturers' => $manufacturers,
            'shopeeCategories' => $shopeeCategories,
            'productCount' => $productCount,
            'logistics' => $logistics,
            'template' => $template,
            'attributes' => $attributes,
            'saved' => $saved,
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
            'shopee_category_id' => 'nullable|integer|min:1',
            'logistic_ids' => 'nullable|array',
            'logistic_ids.*' => 'integer',
            'markup_fixed' => 'nullable|numeric|min:0',
            'markup_percent' => 'nullable|numeric|min:0',
        ]);

        $catIds = array_filter($data['catalog_category_ids'] ?? []);
        $mfgIds = array_filter($data['manufacturer_ids'] ?? []);
        $logIds = array_filter($data['logistic_ids'] ?? []);

        $profile = ShopeeProfile::create([
            'name' => $data['name'],
            'catalog_category_ids' => !empty($catIds) ? array_values(array_map('intval', $catIds)) : null,
            'manufacturer_ids' => !empty($mfgIds) ? array_values(array_map('intval', $mfgIds)) : null,
            'shopee_category_id' => $data['shopee_category_id'] ?? null,
            'logistic_ids' => !empty($logIds) ? array_values(array_map('intval', $logIds)) : null,
            'markup_fixed' => $data['markup_fixed'] ?? null,
            'markup_percent' => $data['markup_percent'] ?? null,
        ]);

        $this->saveProfileAttributes($profile, (array) $request->input('attributes', []));

        return redirect()->route('shopee.profiles.edit', $profile->id)
            ->with('status', 'Profile created.');
    }

    public function update(Request $request, int $id)
    {
        $profile = ShopeeProfile::query()->findOrFail($id);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'catalog_category_ids' => 'nullable|array',
            'catalog_category_ids.*' => 'integer|min:1',
            'manufacturer_ids' => 'nullable|array',
            'manufacturer_ids.*' => 'integer|min:1',
            'shopee_category_id' => 'nullable|integer|min:1',
            'logistic_ids' => 'nullable|array',
            'logistic_ids.*' => 'integer',
            'markup_fixed' => 'nullable|numeric|min:0',
            'markup_percent' => 'nullable|numeric|min:0',
        ]);

        $catIds = array_filter($data['catalog_category_ids'] ?? []);
        $mfgIds = array_filter($data['manufacturer_ids'] ?? []);
        $logIds = array_filter($data['logistic_ids'] ?? []);

        $profile->update([
            'name' => $data['name'],
            'catalog_category_ids' => !empty($catIds) ? array_values(array_map('intval', $catIds)) : null,
            'manufacturer_ids' => !empty($mfgIds) ? array_values(array_map('intval', $mfgIds)) : null,
            'shopee_category_id' => $data['shopee_category_id'] ?? null,
            'logistic_ids' => !empty($logIds) ? array_values(array_map('intval', $logIds)) : null,
            'markup_fixed' => $data['markup_fixed'] ?? null,
            'markup_percent' => $data['markup_percent'] ?? null,
        ]);

        $this->saveProfileAttributes($profile, (array) $request->input('attributes', []));

        return redirect()->route('shopee.profiles.edit', $profile->id)
            ->with('status', 'Profile saved.');
    }

    public function destroy(int $id)
    {
        $profile = ShopeeProfile::query()->findOrFail($id);
        $name = $profile->name;
        $profile->delete();

        return redirect()->route('shopee.profiles.index')
            ->with('status', 'Profile "' . e($name) . '" deleted.');
    }

    public function syncProducts(int $id)
    {
        $profile = ShopeeProfile::query()->findOrFail($id);

        if (!$profile->shopee_category_id) {
            return redirect()->route('shopee.profiles.edit', $profile->id)
                ->with('status', 'Set a Shopee category before syncing products.');
        }

        $productIds = $this->getMatchingProductIds($profile);

        return redirect()->route('shopee.profiles.edit', $profile->id)
            ->with('status', count($productIds) . ' matching product(s) found.');
    }

    public function syncTemplate(Request $request, int $id, ShopeeClient $client)
    {
        $profile = ShopeeProfile::query()->findOrFail($id);

        $request->validate(['shopee_category_id' => 'required|integer|min:1']);
        $shopeeCategoryId = (int) $request->input('shopee_category_id');
        $profile->update(['shopee_category_id' => $shopeeCategoryId]);

        $setting = ShopeeSetting::query()->first()?->decrypted();
        if (!$setting || !$setting->partner_id || !$setting->partner_key || !$setting->access_token || !$setting->shop_id) {
            return redirect()->route('shopee.profiles.edit', $profile->id)
                ->with('error', 'Missing Shopee settings.');
        }

        $path = '/api/v2/product/get_attribute_tree';

        $result = $client->shopGet(
            $setting->mode ?? 'sandbox',
            (int)$setting->partner_id,
            (string)$setting->partner_key,
            (string)$setting->access_token,
            (int)$setting->shop_id,
            $path,
            ['category_id' => $shopeeCategoryId, 'language' => $setting->region ?: 'en']
        );

        $body = $result['body'] ?? null;

        if (!($result['ok'] ?? false)) {
            $msg = is_array($body) ? ($body['message'] ?? ($body['error'] ?? 'API call failed')) : 'API call failed';
            return redirect()->route('shopee.profiles.edit', $profile->id)
                ->with('error', 'Sync failed: ' . $msg);
        }

        // Try multiple response keys — Shopee may use attribute_list or attribute_tree
        $attrList = null;
        if (is_array($body)) {
            $response = $body['response'] ?? $body;
            $attrList = $response['attribute_list']
                ?? $response['attribute_tree']
                ?? $response['attributes']
                ?? null;
        }

        ShopeeCategoryTemplate::query()->updateOrCreate(
            ['category_id' => $shopeeCategoryId],
            [
                'region' => (string)($setting->region ?? ''),
                'attributes' => $attrList ?? ($response ?? $body),
                'fetched_at' => now(),
            ]
        );

        $count = is_array($attrList) ? count($attrList) : 0;
        $debugKeys = is_array($response ?? null) ? implode(', ', array_keys($response)) : 'n/a';

        return redirect()->route('shopee.profiles.edit', $profile->id)
            ->with('status', "Category template synced. Attributes found: {$count}. Response keys: {$debugKeys}");
    }

    // ── Helpers ──────────────────────────────────────────────────────

    private function getMatchingProductIds(ShopeeProfile $profile): array
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

    private function countMatchingProducts(ShopeeProfile $profile): int
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

    private function saveProfileAttributes(ShopeeProfile $profile, array $attrs): void
    {
        foreach ($attrs as $key => $value) {
            $key = trim((string) $key);
            if ($key === '') continue;

            if ($value === null || $value === '') {
                ShopeeProfileAttribute::query()
                    ->where('shopee_profile_id', $profile->id)
                    ->where('attribute_key', $key)
                    ->delete();
            } else {
                ShopeeProfileAttribute::query()->updateOrCreate(
                    ['shopee_profile_id' => $profile->id, 'attribute_key' => $key],
                    ['value' => (string) $value]
                );
            }
        }
    }

    private function extractAttributes($data): array
    {
        if (!is_array($data)) {
            return [];
        }

        $list = $data;
        if (isset($data['attribute_list']) && is_array($data['attribute_list'])) {
            $list = $data['attribute_list'];
        }

        if (!is_array($list) || empty($list)) {
            return [];
        }

        $out = [];
        foreach ($list as $a) {
            if (!is_array($a)) continue;

            $name = (string)($a['original_attribute_name'] ?? $a['display_attribute_name'] ?? $a['attribute_name'] ?? '');
            $id = $a['attribute_id'] ?? null;
            $required = (bool)($a['is_mandatory'] ?? $a['mandatory'] ?? false);
            $inputType = (string)($a['input_type'] ?? $a['input_validation_type'] ?? 'text');
            $options = $a['attribute_value_list'] ?? $a['options'] ?? $a['values'] ?? null;

            $key = is_scalar($id) ? (string)$id : ($name !== '' ? $name : '');
            if ($key === '') continue;

            $out[] = [
                'key' => $key,
                'name' => $name !== '' ? $name : $key,
                'required' => $required,
                'input_type' => $inputType,
                'options' => is_array($options) ? $options : [],
            ];
        }
        return $out;
    }

    private function fetchLogistics(): array
    {
        return ShopeeLogistic::query()
            ->orderByDesc('enabled')
            ->orderBy('logistics_channel_name')
            ->get()
            ->map(fn($ch) => [
                'logistic_id' => $ch->logistics_channel_id,
                'logistic_name' => $ch->logistics_channel_name,
                'enabled' => $ch->enabled,
            ])
            ->toArray();
    }

}
