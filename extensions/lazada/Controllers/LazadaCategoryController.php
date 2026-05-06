<?php

namespace Extensions\lazada\Controllers;

use App\Http\Controllers\Controller;

use Extensions\lazada\Models\LazadaCategory;
use Extensions\lazada\Models\LazadaSetting;
use Extensions\lazada\Services\Lazada\LazadaClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LazadaCategoryController extends Controller
{
    public function index(Request $request)
    {
        $q = trim((string)$request->query('q', ''));

        $query = LazadaCategory::query();

        if ($q !== '') {
            $query->where(function ($sub) use ($q) {
                $sub->where('name', 'like', '%' . $q . '%');

                // Numeric search on category_id
                if (ctype_digit($q)) {
                    $sub->orWhere('category_id', (int)$q);
                }
            });
        }

        $categories = $query
            ->orderBy('name')
            ->paginate(50)
            ->withQueryString();

        return view('ext-lazada::categories.index', [
            'categories' => $categories,
            'q' => $q,
        ]);
    }

    public function fetch(Request $request, LazadaClient $client)
    {
        $setting = LazadaSetting::query()->first()?->decrypted();
        if (!$setting || !$setting->region || !$setting->app_key || !$setting->app_secret) {
            return redirect()->route('ext.lazada.categories.index')->with('error', 'Missing Lazada settings. Please configure Region, App Key, and App Secret first.');
        }

        // Base params required by Lazada signing.
        $timestamp = (string)round(microtime(true) * 1000);
        $params = [
            'app_key' => (string)$setting->app_key,
            'sign_method' => 'sha256',
            'timestamp' => $timestamp,
        ];

        $apiPath = '/category/tree/get';
        $params['sign'] = $client->sign($apiPath, $params, (string)$setting->app_secret);

        $result = $client->get((string)$setting->region, $apiPath, $params);
        $body = $result['body'] ?? null;

        // Lazada returns categories under `data`.
        $nodes = [];
        if (is_array($body)) {
            if (isset($body['data']) && is_array($body['data'])) {
                $nodes = $body['data'];
            } elseif (isset($body['data']['data']) && is_array($body['data']['data'])) {
                $nodes = $body['data']['data'];
            }
        }

        if (!$result['ok'] || !is_array($nodes) || count($nodes) === 0) {
            $msg = is_array($body) ? ($body['message'] ?? ($body['code'] ?? 'Failed')) : 'Failed';
            return redirect()->route('ext.lazada.categories.index')->with('error', 'Fetch failed: ' . $msg);
        }

        // Flatten tree and replace the local cache.
        $rows = [];
        $this->flattenTree($nodes, $rows, null, 0);

        if (!is_array($rows) || count($rows) === 0) {
            return redirect()->route('ext.lazada.categories.index')->with('error', 'Fetch returned no categories. Existing category cache was not modified.');
        }

        // Ensure IDs restart from 1 after refresh (without using ALTER TABLE inside a transaction).
        $i = 1;
        foreach ($rows as &$r) {
            $r['id'] = $i++;
        }
        unset($r);


        DB::transaction(function () use ($rows) {
            // We intentionally clear the cache only AFTER a successful API fetch.
            // Use delete() (not truncate) to keep this operation transactional.
            DB::table('lazada_categories')->delete();
            // Chunk inserts to avoid max packet issues.
            $chunks = array_chunk($rows, 1000);
            foreach ($chunks as $chunk) {
                DB::table('lazada_categories')->insert($chunk);
            }
        });

        return redirect()->route('ext.lazada.categories.index')->with('status', 'Lazada categories fetched and saved successfully. Total: ' . count($rows));
    }

    private function flattenTree(array $nodes, array &$rows, ?int $parentId, int $level): void
    {
        foreach ($nodes as $n) {
            if (!is_array($n)) {
                continue;
            }

            $categoryId = isset($n['category_id']) ? (int)$n['category_id'] : null;
            $name = isset($n['name']) ? (string)$n['name'] : '';

            if (!$categoryId || $name === '') {
                continue;
            }

            $rows[] = [
                'category_id' => $categoryId,
                'name' => $name,
                'leaf' => (bool)($n['leaf'] ?? false),
                'var' => array_key_exists('var', $n) ? (bool)$n['var'] : null,
                'parent_id' => $parentId,
                'level' => $level,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            if (!empty($n['children']) && is_array($n['children'])) {
                $this->flattenTree($n['children'], $rows, $categoryId, $level + 1);
            }
        }
    }

}
