<?php

namespace Extensions\shopee\Controllers;

use App\Http\Controllers\Controller;

use Extensions\shopee\Models\ShopeeCategory;
use Extensions\shopee\Models\ShopeeApiLog;
use Extensions\shopee\Models\ShopeeSetting;
use Extensions\shopee\Services\Shopee\ShopeeClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ShopeeCategoryController extends Controller
{
    public function index(Request $request)
    {
        $q = trim((string)$request->query('q', ''));

        $query = ShopeeCategory::query();

        if ($q !== '') {
            $query->where(function ($sub) use ($q) {
                $sub->where('name', 'like', '%' . $q . '%');

                if (ctype_digit($q)) {
                    $sub->orWhere('category_id', (int)$q);
                }
            });
        }

        $categories = $query
            ->orderBy('name')
            ->paginate(50)
            ->withQueryString();

        return view('ext-shopee::categories.index', [
            'categories' => $categories,
            'q' => $q,
        ]);
    }

    public function fetch(Request $request, ShopeeClient $client)
    {
        $setting = ShopeeSetting::query()->first()?->decrypted();
        if (!$setting || !$setting->partner_id || !$setting->partner_key || !$setting->access_token || !$setting->shop_id) {
            return redirect()->route('ext.shopee.categories.index')
                ->with('error', 'Missing Shopee settings. Please configure Partner ID, Partner Key, Access Token, and Shop ID first.');
        }

        $path = '/api/v2/product/get_category';

        $result = $client->shopGet(
            $setting->mode ?? 'sandbox',
            (int)$setting->partner_id,
            (string)$setting->partner_key,
            (string)$setting->access_token,
            (int)$setting->shop_id,
            $path,
            ['language' => $setting->region ?: 'en']
        );

        ShopeeApiLog::safeCreate([
            'pack' => 'shopee.categories.fetch',
            'method' => 'GET',
            'api_path' => $path,
            'auth_required' => true,
            'request_params' => ['language' => $setting->region ?: 'en'],
            'response_status' => $result['status'] ?? null,
            'ok' => (bool)($result['ok'] ?? false),
            'response_body' => $result['body'] ?? null,
            'user_id' => auth()->id(),
        ]);

        $body = $result['body'] ?? null;

        // Shopee returns { response: { category_list: [...] } }
        $nodes = [];
        if (is_array($body)) {
            $response = $body['response'] ?? $body;
            if (isset($response['category_list']) && is_array($response['category_list'])) {
                $nodes = $response['category_list'];
            }
        }

        if (!$result['ok'] || empty($nodes)) {
            $msg = is_array($body) ? ($body['message'] ?? ($body['error'] ?? 'Failed')) : 'Failed';
            return redirect()->route('ext.shopee.categories.index')
                ->with('error', 'Fetch failed: ' . $msg);
        }

        // Flatten the category list into rows
        $rows = [];
        $this->flattenCategories($nodes, $rows, null, 0);

        if (empty($rows)) {
            return redirect()->route('ext.shopee.categories.index')
                ->with('error', 'Fetch returned no categories.');
        }

        // Assign sequential IDs
        $i = 1;
        foreach ($rows as &$r) {
            $r['id'] = $i++;
        }
        unset($r);

        DB::transaction(function () use ($rows) {
            DB::table('shopee_categories')->delete();
            $chunks = array_chunk($rows, 1000);
            foreach ($chunks as $chunk) {
                DB::table('shopee_categories')->insert($chunk);
            }
        });

        return redirect()->route('ext.shopee.categories.index')
            ->with('status', 'Shopee categories fetched and saved. Total: ' . count($rows));
    }

    private function flattenCategories(array $nodes, array &$rows, ?int $parentId, int $level): void
    {
        foreach ($nodes as $n) {
            if (!is_array($n)) {
                continue;
            }

            $categoryId = isset($n['category_id']) ? (int)$n['category_id'] : null;
            $name = (string)($n['original_category_name'] ?? $n['display_category_name'] ?? $n['category_name'] ?? '');
            $hasChildren = (bool)($n['has_children'] ?? false);

            if (!$categoryId || $name === '') {
                continue;
            }

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
                $this->flattenCategories($n['children'], $rows, $categoryId, $level + 1);
            }
        }
    }

}
