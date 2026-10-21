<?php

namespace Extensions\Lazada\Controllers;


use Extensions\Lazada\Models\LazadaCategoryTemplate;
use Extensions\Lazada\Models\LazadaCategory;
use Extensions\Lazada\Models\LazadaProductGroup;
use Extensions\Lazada\Models\LazadaStore;
use Extensions\Lazada\Services\Lazada\LazadaAttributes;
use Extensions\Lazada\Services\Lazada\LazadaClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LazadaProductGroupController extends \App\Http\Controllers\Channels\ProductGroupController
{
    protected function channel(): string
    {
        return 'lazada';
    }

    protected function groupModel(): string
    {
        return \Extensions\Lazada\Models\LazadaProductGroup::class;
    }

    protected function membership(): array
    {
        return ['ch_lazada_product_group_products', 'lazada_product_group_id'];
    }

    private function attrs(): LazadaAttributes
    {
        return app(LazadaAttributes::class);
    }

    public function refreshCategories(LazadaClient $client)
    {
        $store = LazadaStore::defaultStore()?->decrypted();
        $creds = LazadaStore::activeCredentials($store);
        if (!$store || !$store->region || !$creds['app_key'] || !$creds['app_secret']) {
            return response()->json(['ok' => false, 'message' => 'Missing Lazada settings.']);
        }

        $timestamp = (string) round(microtime(true) * 1000);
        $params = [
            'app_key' => (string) $creds['app_key'],
            'sign_method' => 'sha256',
            'timestamp' => $timestamp,
        ];
        $apiPath = '/category/tree/get';
        $params['sign'] = $client->sign($apiPath, $params, (string) $creds['app_secret']);
        $result = $client->get((string) $store->region, $apiPath, $params);
        $body = $result['body'] ?? null;

        $nodes = [];
        if (is_array($body)) {
            if (isset($body['data']) && is_array($body['data'])) {
                $nodes = $body['data'];
            } elseif (isset($body['data']['data']) && is_array($body['data']['data'])) {
                $nodes = $body['data']['data'];
            }
        }

        if (!$result['ok'] || empty($nodes)) {
            $msg = \App\Support\ChannelAnswer::plain('Lazada', ['ok' => false, 'body' => is_array($body) ? $body : []]);
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
            DB::table('ch_lazada_categories')->delete();
            foreach (array_chunk($rows, 1000) as $chunk) {
                DB::table('ch_lazada_categories')->insert($chunk);
            }
        });

        $categories = LazadaCategory::query()->orderBy('name')->limit(5000)->get(['category_id', 'name']);
        return response()->json(['ok' => true, 'count' => count($rows), 'categories' => $categories]);
    }

    private function flattenCategoryTree(array $nodes, array &$rows, ?int $parentId, int $level): void
    {
        foreach ($nodes as $n) {
            if (!is_array($n)) continue;
            $categoryId = isset($n['category_id']) ? (int) $n['category_id'] : null;
            $name = isset($n['name']) ? (string) $n['name'] : '';
            if (!$categoryId || $name === '') continue;
            $rows[] = [
                'category_id' => $categoryId,
                'name' => $name,
                'leaf' => (bool) ($n['leaf'] ?? false),
                'var' => array_key_exists('var', $n) ? (bool) $n['var'] : null,
                'parent_id' => $parentId,
                'level' => $level,
                'created_at' => now(),
                'updated_at' => now(),
            ];
            if (!empty($n['children']) && is_array($n['children'])) {
                $this->flattenCategoryTree($n['children'], $rows, $categoryId, $level + 1);
            }
        }
    }

    public function fetchAttributesAjax(Request $request, LazadaClient $client)
    {
        $categoryId = (int) $request->input('lazada_category_id', 0);
        if ($categoryId <= 0) {
            return response()->json(['ok' => false, 'html' => '']);
        }

        $store = LazadaStore::defaultStore()?->decrypted();
        $creds = LazadaStore::activeCredentials($store);
        if (!$store || !$store->region || !$creds['app_key'] || !$creds['app_secret']) {
            return response()->json(['ok' => false, 'html' => '<div class="text-muted">Missing Lazada settings.</div>']);
        }

        $apiPath = '/category/attributes/get';
        $timestamp = (string) round(microtime(true) * 1000);
        $params = [
            'app_key' => (string) $creds['app_key'],
            'sign_method' => 'sha256',
            'timestamp' => $timestamp,
            'primary_category_id' => (string) $categoryId,
        ];
        $params['sign'] = $client->sign($apiPath, $params, (string) $creds['app_secret']);
        $result = $client->get((string) $store->region, $apiPath, $params);

        $template = LazadaCategoryTemplate::query()->updateOrCreate(
            ['region' => (string) $store->region, 'primary_category_id' => $categoryId],
            ['template_body' => $result['body'] ?? null, 'fetched_at' => now()]
        );

        $attributes = $this->attrs()->extractAttributes($template->template_body);
        $catalogSourceFields = \Extensions\Lazada\Controllers\LazadaAttributes::CATALOG_SOURCE_FIELDS;

        $html = view('ext-lazada::product-groups._attributes', [
            'attributes' => $attributes,
            'saved' => [],
            'catalogSourceFields' => $catalogSourceFields,
            'template' => $template,
        ])->render();

        return response()->json(['ok' => true, 'html' => $html, 'count' => count($attributes)]);
    }
}
