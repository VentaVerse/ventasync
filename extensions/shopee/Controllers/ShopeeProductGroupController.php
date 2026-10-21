<?php

namespace Extensions\Shopee\Controllers;


use Extensions\Shopee\Models\ShopeeBrand;
use Extensions\Shopee\Models\ShopeeCategory;
use Extensions\Shopee\Models\ShopeeCategoryTemplate;
use Extensions\Shopee\Models\ShopeeProductGroup;
use Extensions\Shopee\Models\ShopeeStore;
use Extensions\Shopee\Services\Shopee\ShopeeClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ShopeeProductGroupController extends \App\Http\Controllers\Channels\ProductGroupController
{
    protected function channel(): string
    {
        return 'shopee';
    }

    protected function groupModel(): string
    {
        return \Extensions\Shopee\Models\ShopeeProductGroup::class;
    }

    protected function membership(): array
    {
        return ['ch_shopee_product_group_products', 'shopee_product_group_id'];
    }

    public function __construct(
        private readonly \Extensions\Shopee\Services\Shopee\ShopeeLinkCheck $linkCheck,
    ) {
    }

    public function attributeListFromSaved(array $savedByKey, int $categoryId): array
    {
        if (empty($savedByKey) || $categoryId <= 0) {
            return [];
        }

        $template = ShopeeCategoryTemplate::query()
            ->where('category_id', $categoryId)
            ->first();
        if (!$template) {
            return [];
        }
        $raw = is_array($template->attributes) ? $template->attributes : (json_decode((string) $template->attributes, true) ?: []);
        $list = $raw['attribute_list'] ?? $raw['attribute_tree'] ?? $raw['attributes'] ?? $raw;
        if (!is_array($list)) {
            return [];
        }

        $out = [];
        foreach ($list as $attr) {
            if (!is_array($attr)) continue;
            $attrId = (int) ($attr['attribute_id'] ?? 0);
            $attrKey = (string) ($attr['display_attribute_name'] ?? $attr['original_attribute_name'] ?? $attr['attribute_name'] ?? '');
            if ($attrId <= 0 || $attrKey === '') continue;

            $value = trim((string) ($savedByKey[(string) $attrId] ?? $savedByKey[$attrKey] ?? ''));
            if ($value === '') continue;

            $valueList = $attr['attribute_value_list'] ?? $attr['values'] ?? $attr['options'] ?? [];
            $matchedValueId = null;
            if (is_array($valueList)) {
                foreach ($valueList as $opt) {
                    if (!is_array($opt)) continue;
                    $optName = (string) ($opt['display_value_name'] ?? $opt['original_value_name'] ?? $opt['value_name'] ?? '');
                    if ($optName !== '' && strcasecmp($optName, $value) === 0) {
                        $matchedValueId = (int) ($opt['value_id'] ?? 0);
                        break;
                    }
                }
            }

            if ($matchedValueId !== null && $matchedValueId > 0) {
                $out[] = [
                    'attribute_id' => $attrId,
                    'attribute_value_list' => [
                        ['value_id' => $matchedValueId],
                    ],
                ];
            } else {
                $out[] = [
                    'attribute_id' => $attrId,
                    'attribute_value_list' => [
                        ['value_id' => 0, 'original_value_name' => $value],
                    ],
                ];
            }
        }

        return $out;
    }

    public function normalizedAttributesFor(int $categoryId): array
    {
        $template = $categoryId > 0
            ? ShopeeCategoryTemplate::query()->where('category_id', $categoryId)->first()
            : null;

        return [
            'template' => $template,
            'attributes' => $template ? \Extensions\Shopee\Services\Shopee\ShopeeCategoryAttributes::extract($template->attributes) : [],
        ];
    }

    public function ensureTemplate(int $categoryId, ShopeeClient $client, ?array $auth = null): void
    {
        if ($categoryId <= 0 || ShopeeCategoryTemplate::query()->where('category_id', $categoryId)->exists()) {
            return;
        }

        if ($auth === null) {
            $store = ShopeeStore::defaultStore()?->decrypted();
            $auth = ShopeeStore::activeAuth($store);
            if (!$store) {
                return;
            }
        }
        if (!($auth['complete'] ?? false)) {
            return;
        }

        try {
            $result = $client->shopGet(
                $auth['mode'],
                (int) $auth['partner_id'], (string) $auth['partner_key'],
                (string) $auth['access_token'], (int) $auth['shop_id'],
                '/api/v2/product/get_attribute_tree',
                ['category_id' => $categoryId, 'language' => $auth['region'] ?: 'en']
            );
        } catch (\Throwable) {
            return;
        }

        if (!($result['ok'] ?? false) || !\App\Support\MarketplaceVerdict::ok('shopee', true, $result['body'] ?? null)) {
            return;
        }

        $body = $result['body'] ?? null;
        $response = is_array($body) ? ($body['response'] ?? $body) : null;
        $attrList = is_array($response)
            ? ($response['attribute_list'] ?? $response['attribute_tree'] ?? $response['attributes'] ?? null)
            : null;

        ShopeeCategoryTemplate::query()->updateOrCreate(
            ['category_id' => $categoryId],
            [
                'region' => (string) ($auth['region'] ?? ''),
                'attributes' => $attrList ?? ($response ?? $body),
                'fetched_at' => now(),
            ]
        );
    }

    public function buildBrandPayload(int $categoryId, int $brandId): array
    {
        if ($brandId <= 0) {
            return ['brand_id' => 0, 'original_brand_name' => 'No Brand'];
        }
        $row = ShopeeBrand::query()
            ->where('category_id', $categoryId)
            ->where('brand_id', $brandId)
            ->first();
        $name = $row ? (string) $row->name : 'No Brand';
        return ['brand_id' => $brandId, 'original_brand_name' => $name];
    }

    public function searchCategories(Request $request)
    {
        $q = trim((string) $request->input('q', ''));
        $limit = max(1, min(50, (int) $request->input('limit', 25)));

        $query = ShopeeCategory::query()->where('leaf', 1);

        if ($q !== '') {
            $query->where(function ($w) use ($q) {
                $w->where('name', 'like', '%' . $q . '%');
                if (ctype_digit($q)) {
                    $w->orWhere('category_id', (int) $q);
                }
            });
        }

        $rows = $query->orderBy('name')->limit($limit)->get(['category_id', 'name']);

        return response()->json([
            'ok' => true,
            'categories' => $rows->map(fn ($c) => [
                'category_id' => (int) $c->category_id,
                'name'        => (string) $c->name,
            ])->values()->all(),
        ]);
    }

    public function categoryLookup(Request $request)
    {
        $cid = (int) $request->input('category_id', 0);
        if ($cid <= 0) {
            return response()->json(['ok' => false], 422);
        }
        $row = ShopeeCategory::query()
            ->where('category_id', $cid)
            ->first(['category_id', 'name', 'leaf']);
        if (!$row) {
            return response()->json(['ok' => false], 404);
        }
        return response()->json([
            'ok' => true,
            'category' => [
                'category_id' => (int) $row->category_id,
                'name'        => (string) $row->name,
                'leaf'        => (bool) $row->leaf,
            ],
        ]);
    }

    private function brandsCompleteKey(int $categoryId): string
    {
        return 'shopee.brands.complete.' . $categoryId;
    }

    private function warmBrands(int $categoryId, int $offset, ShopeeClient $client)
    {
        $read = fn () => ShopeeBrand::query()->where('category_id', $categoryId)->count();

        if (\Illuminate\Support\Facades\Cache::get($this->brandsCompleteKey($categoryId))) {
            return response()->json(['ok' => true, 'complete' => true, 'read' => $read()]);
        }

        $store = ShopeeStore::defaultStore()?->decrypted();
        $auth = ShopeeStore::activeAuth($store);
        if (!$store || !$auth['complete']) {
            return response()->json(['ok' => false, 'message' => 'This store is not connected yet. Authorise it with Shopee first.'], 412);
        }

        $pageSize = 100;
        $result = $client->shopGet(
            $auth['mode'],
            (int) $auth['partner_id'], (string) $auth['partner_key'],
            (string) $auth['access_token'], (int) $auth['shop_id'],
            '/api/v2/product/get_brand_list',
            ['category_id' => $categoryId, 'offset' => $offset, 'page_size' => $pageSize, 'status' => 1]
        );

        if (!($result['ok'] ?? false)) {
            $body = $result['body'] ?? null;
            $msg = is_array($body) ? ($body['message'] ?? ($body['error'] ?? 'the call failed')) : 'the call failed';

            return response()->json(['ok' => false, 'message' => 'Shopee did not answer with its brands: ' . $msg], 502);
        }

        $body = $result['body'] ?? [];
        $resp = $body['response'] ?? $body;
        $now = now();
        $before = $read();
        foreach ((array) ($resp['brand_list'] ?? []) as $b) {
            if (!is_array($b)) continue;
            $bid = (int) ($b['brand_id'] ?? 0);
            $name = trim((string) ($b['original_brand_name'] ?? $b['display_brand_name'] ?? $b['name'] ?? ''));
            if ($bid <= 0 || $name === '') continue;
            ShopeeBrand::updateOrCreate(
                ['category_id' => $categoryId, 'brand_id' => $bid],
                ['name' => $name, 'status' => 1, 'raw' => $b, 'updated_at' => $now]
            );
        }

        $after = $read();
        $nextOffset = (int) ($resp['next_offset'] ?? 0);
        if ($nextOffset <= $offset) {
            $nextOffset = $offset + $pageSize;
        }
        $hasNext = (bool) ($resp['has_next_page'] ?? false) && !empty($resp['brand_list']) && $after > $before && $nextOffset < 20000;
        if (!$hasNext) {
            \Illuminate\Support\Facades\Cache::forever($this->brandsCompleteKey($categoryId), true);
        }

        return response()->json([
            'ok' => true,
            'complete' => !$hasNext,
            'next_offset' => $nextOffset,
            'read' => $after,
        ]);
    }

    public function brandsForCategory(Request $request, ShopeeClient $client)
    {
        $categoryId = (int) $request->query('category_id', 0);
        if ($categoryId <= 0) {
            return response()->json(['ok' => false, 'message' => 'category_id is required.'], 422);
        }

        $q = trim((string) $request->query('q', ''));
        $limit = (int) $request->query('limit', 0);
        $filterBrands = function ($query) use ($q, $limit) {
            if ($q !== '') {
                $query->where(function ($w) use ($q) {
                    $w->where('name', 'like', '%' . $q . '%');
                    if (ctype_digit($q)) {
                        $w->orWhere('brand_id', (int) $q);
                    }
                });
            }
            if ($limit > 0) {
                $query->limit(max(1, min(50, $limit)));
            }

            return $query;
        };

        if ($request->boolean('warm')) {
            return $this->warmBrands($categoryId, max(0, (int) $request->query('offset', 0)), $client);
        }

        $cacheHasAny = ShopeeBrand::query()->where('category_id', $categoryId)->exists();
        if ($cacheHasAny) {
            $cached = $filterBrands(ShopeeBrand::query()
                ->where('category_id', $categoryId)
                ->orderBy('name'))
                ->get(['brand_id', 'name']);

            return response()->json([
                'ok' => true,
                'cached' => true,
                'brands' => $cached->map(fn ($b) => ['brand_id' => (int) $b->brand_id, 'name' => (string) $b->name])->all(),
            ]);
        }

        $store = ShopeeStore::defaultStore()?->decrypted();
        $auth = ShopeeStore::activeAuth($store);
        if (!$store || !$auth['complete']) {
            $modeLabel = $auth['mode'] === 'sandbox' ? 'Sandbox' : 'Production';
            return response()->json([
                'ok' => false,
                'message' => "Missing Shopee {$modeLabel} settings.",
            ], 412);
        }

        $path = '/api/v2/product/get_brand_list';
        $offset = 0;
        $pageSize = 100;
        $collected = [];
        $maxPages = 50;

        for ($page = 0; $page < $maxPages; $page++) {
            $result = $client->shopGet(
                $auth['mode'],
                (int) $auth['partner_id'],
                (string) $auth['partner_key'],
                (string) $auth['access_token'],
                (int) $auth['shop_id'],
                $path,
                [
                    'category_id' => $categoryId,
                    'offset'      => $offset,
                    'page_size'   => $pageSize,
                    'status'      => 1,
                ]
            );

            if (!($result['ok'] ?? false)) {
                $body = $result['body'] ?? null;
                $msg = is_array($body) ? ($body['message'] ?? ($body['error'] ?? 'API call failed')) : 'API call failed';
                return response()->json([
                    'ok' => false,
                    'message' => 'Failed to fetch brands: ' . $msg,
                ], 502);
            }

            $body = $result['body'] ?? [];
            $resp = $body['response'] ?? $body;
            $brandList = $resp['brand_list'] ?? [];
            if (!is_array($brandList) || empty($brandList)) {
                break;
            }

            foreach ($brandList as $b) {
                if (!is_array($b)) continue;
                $bid = (int) ($b['brand_id'] ?? 0);
                $name = trim((string) ($b['original_brand_name'] ?? $b['display_brand_name'] ?? $b['name'] ?? ''));
                if ($bid <= 0 || $name === '') continue;

                $collected[] = ['brand_id' => $bid, 'name' => $name, 'raw' => $b];
            }

            $hasNext = (bool) ($resp['has_next_page'] ?? false);
            if (!$hasNext) {
                break;
            }
            $offset += $pageSize;
        }

        $now = now();
        foreach ($collected as $row) {
            ShopeeBrand::updateOrCreate(
                ['category_id' => $categoryId, 'brand_id' => $row['brand_id']],
                ['name' => $row['name'], 'status' => 1, 'raw' => $row['raw'], 'updated_at' => $now]
            );
        }

        $brands = $filterBrands(ShopeeBrand::query()
            ->where('category_id', $categoryId)
            ->orderBy('name'))
            ->get(['brand_id', 'name'])
            ->map(fn ($b) => ['brand_id' => (int) $b->brand_id, 'name' => (string) $b->name])
            ->all();

        return response()->json([
            'ok' => true,
            'cached' => false,
            'brands' => $brands,
            'fetched' => count($collected),
        ]);
    }

    public function refreshCategories(ShopeeClient $client)
    {
        $store = ShopeeStore::defaultStore()?->decrypted();
        $auth = ShopeeStore::activeAuth($store);
        if (!$store || !$auth['complete']) {
            $modeLabel = $auth['mode'] === 'sandbox' ? 'Sandbox' : 'Production';
            return response()->json(['ok' => false, 'message' => "Missing Shopee {$modeLabel} settings."]);
        }

        $result = $client->shopGet(
            $auth['mode'],
            (int) $auth['partner_id'],
            (string) $auth['partner_key'],
            (string) $auth['access_token'],
            (int) $auth['shop_id'],
            '/api/v2/product/get_category',
            ['language' => $auth['region'] ?: 'en']
        );

        $body = $result['body'] ?? null;
        $nodes = [];
        if (is_array($body)) {
            $response = $body['response'] ?? $body;
            if (isset($response['category_list']) && is_array($response['category_list'])) {
                $nodes = $response['category_list'];
            }
        }

        if (!($result['ok'] ?? false) || empty($nodes)) {
            $msg = \App\Support\ChannelAnswer::plain('Shopee', ['ok' => false, 'body' => is_array($body) ? $body : []]);
            return response()->json(['ok' => false, 'message' => 'Fetch failed: ' . $msg]);
        }

        $rows = [];
        $this->flattenCategoryTree($nodes, $rows, null, 0);
        if (empty($rows)) {
            return response()->json(['ok' => false, 'message' => 'No categories returned.']);
        }

        $i = 1;
        foreach ($rows as &$r) { $r['id'] = $i++; }
        unset($r);

        DB::transaction(function () use ($rows) {
            DB::table('ch_shopee_categories')->delete();
            foreach (array_chunk($rows, 1000) as $chunk) {
                DB::table('ch_shopee_categories')->insert($chunk);
            }
        });

        $leafQuery = ShopeeCategory::query()->where('leaf', 1)->orderBy('name')->limit(5000);
        $categories = $leafQuery->get(['category_id', 'name']);
        if ($categories->isEmpty()) {
            $categories = ShopeeCategory::query()->orderBy('name')->limit(5000)->get(['category_id', 'name']);
        }
        return response()->json(['ok' => true, 'count' => count($rows), 'categories' => $categories]);
    }

    private function flattenCategoryTree(array $nodes, array &$rows, ?int $parentId, int $level): void
    {
        foreach ($nodes as $n) {
            if (!is_array($n)) continue;
            $categoryId = isset($n['category_id']) ? (int) $n['category_id'] : null;
            $name = (string) ($n['original_category_name'] ?? $n['display_category_name'] ?? $n['category_name'] ?? '');
            $hasChildren = (bool) ($n['has_children'] ?? false);
            if (!$categoryId || $name === '') continue;
            $rows[] = [
                'category_id' => $categoryId,
                'parent_id' => $n['parent_category_id'] ?? $parentId,
                'name' => $name,
                'level' => $level,
                'leaf' => !$hasChildren,
                'created_at' => now(),
                'updated_at' => now(),
            ];
            if (!empty($n['children']) && is_array($n['children'])) {
                $this->flattenCategoryTree($n['children'], $rows, $categoryId, $level + 1);
            }
        }
    }

    public function fetchAttributesAjax(Request $request, ShopeeClient $client)
    {
        $categoryId = (int) $request->input('shopee_category_id', 0);
        if ($categoryId <= 0) {
            return response()->json(['ok' => false, 'html' => '']);
        }

        $store = ShopeeStore::defaultStore()?->decrypted();
        $auth = ShopeeStore::activeAuth($store);
        if (!$store || !$auth['complete']) {
            $modeLabel = $auth['mode'] === 'sandbox' ? 'Sandbox' : 'Production';
            return response()->json(['ok' => false, 'html' => '<div class="text-muted">Missing Shopee ' . $modeLabel . ' settings.</div>']);
        }

        $result = $client->shopGet(
            $auth['mode'],
            (int) $auth['partner_id'],
            (string) $auth['partner_key'],
            (string) $auth['access_token'],
            (int) $auth['shop_id'],
            '/api/v2/product/get_attribute_tree',
            ['category_id' => $categoryId, 'language' => $auth['region'] ?: 'en']
        );

        $body = $result['body'] ?? null;
        $attrList = null;
        if (is_array($body)) {
            $response = $body['response'] ?? $body;
            $attrList = $response['attribute_list'] ?? $response['attribute_tree'] ?? $response['attributes'] ?? null;
        }

        $template = ShopeeCategoryTemplate::query()->updateOrCreate(
            ['category_id' => $categoryId],
            [
                'region' => (string) ($auth['region'] ?? ''),
                'attributes' => $attrList ?? ($response ?? $body),
                'fetched_at' => now(),
            ]
        );

        $attributes = \Extensions\Shopee\Services\Shopee\ShopeeCategoryAttributes::extract($template->attributes);

        $html = view('ext-shopee::product-groups._attributes', [
            'attributes' => $attributes,
            'saved' => [],
            'template' => $template,
        ])->render();

        return response()->json(['ok' => true, 'html' => $html, 'count' => count($attributes)]);
    }
}
