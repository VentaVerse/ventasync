<?php

namespace Extensions\lazada\Controllers;

use App\Http\Controllers\Controller;

use Extensions\lazada\Models\LazadaBrand;
use Extensions\lazada\Models\LazadaSetting;
use Extensions\lazada\Services\Lazada\LazadaClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LazadaBrandController extends Controller
{
    public function index(Request $request)
    {
        $q = trim((string)$request->query('q', ''));
        $region = $this->currentRegion();

        $query = LazadaBrand::query();
        if ($region) {
            $query->where('region', $region);
        }

        if ($q !== '') {
            $query->where(function ($sub) use ($q) {
                $sub->where('name', 'like', '%' . $q . '%');
                if (ctype_digit($q)) {
                    $sub->orWhere('brand_id', (int)$q);
                }
            });
        }

        $brands = $query->orderBy('name')->paginate(50)->withQueryString();

        return view('ext-lazada::brands.index', [
            'brands' => $brands,
            'q' => $q,
        ]);
    }

    public function fetch(Request $request, LazadaClient $client)
    {
        $setting = LazadaSetting::query()->first()?->decrypted();
        if (!$setting || !$setting->region || !$setting->app_key || !$setting->app_secret) {
            return redirect()->route('ext.lazada.brands.index')->with('error', 'Missing Lazada settings. Please configure Region, App Key, and App Secret first.');
        }

        $apiPath = '/category/brands/query';
        $region = (string)$setting->region;
        $pageSize = 200;
        $startRow = 0;
        $brandsById = [];
        $guard = 0;
        $emptyTries = 0;
        $expectedTotal = null;

        while (true) {
            $guard++;
            if ($guard > 5000) {
                // Safety guard against infinite loops.
                break;
            }

            $timestamp = (string)round(microtime(true) * 1000);
            $params = [
                'app_key' => (string)$setting->app_key,
                'sign_method' => 'sha256',
                'timestamp' => $timestamp,
                'startRow' => (string)$startRow,
                'pageSize' => (string)$pageSize,
            ];
            $params['sign'] = $client->sign($apiPath, $params, (string)$setting->app_secret);

            $result = $client->get($region, $apiPath, $params);
            $body = $result['body'] ?? null;

            if (!(bool)($result['ok'] ?? false)) {
                $msg = is_array($body) ? ((string)($body['message'] ?? $body['code'] ?? 'Failed')) : 'Failed';
                return redirect()->route('ext.lazada.brands.index')->with('error', 'Fetch failed: ' . $msg);
            }

            $rows = $this->extractBrandRows($body);

            // Best-effort total count helps us avoid stopping early when Lazada returns partial pages.
            $total = null;
            if (is_array($body)) {
                $d = $body['data'] ?? $body;
                if (is_array($d)) {
                    $total = $d['total'] ?? ($d['total_count'] ?? ($d['totalCount'] ?? null));
                }
            }
            if ($total !== null && is_numeric($total)) {
                $expectedTotal = (int)$total;
            }

            if (empty($rows)) {
                // If we have an expected total and haven't reached it yet, retry a few times instead of stopping early.
                if ($expectedTotal !== null && $startRow < $expectedTotal && $emptyTries < 3) {
                    $emptyTries++;
                    usleep(250000); // 250ms
                    continue;
                }
                break;
            }
            $emptyTries = 0;

            foreach ($rows as $b) {
                $brandId = (int)($b['brand_id'] ?? $b['id'] ?? 0);
                $name = trim((string)($b['name'] ?? $b['brand_name'] ?? ''));
                if ($brandId <= 0 || $name === '') {
                    continue;
                }
                $brandsById[$brandId] = [
                    'region' => $region,
                    'brand_id' => $brandId,
                    'name' => $name,
                    'raw' => is_array($b) ? json_encode($b) : null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            // Lazada paging: startRow is zero-based offset.
            // If we received fewer than the page size, we are done.
            if ($expectedTotal !== null) {
                $startRow += $pageSize;
                if ($startRow >= $expectedTotal) {
                    break;
                }
            } else {
                // Fallback heuristic.
                if (count($rows) < $pageSize) {
                    break;
                }
                $startRow += $pageSize;
            }
        }

        if (count($brandsById) === 0) {
            return redirect()->route('ext.lazada.brands.index')->with('error', 'Fetch returned no brands. Existing brand cache was not modified.');
        }

        $allRows = array_values($brandsById);

        DB::transaction(function () use ($region, $allRows) {
            // Clear cache only after successful fetch. Use delete() for transactional safety.
            DB::table('lazada_brands')->where('region', $region)->delete();
            foreach (array_chunk($allRows, 1000) as $chunk) {
                DB::table('lazada_brands')->insert($chunk);
            }
        });

        return redirect()->route('ext.lazada.brands.index')->with('status', 'Lazada brands fetched and saved successfully. Total: ' . count($allRows));
    }

    public function autocomplete(Request $request)
    {
        $q = trim((string)$request->query('q', ''));
        $region = $this->currentRegion();
        $limit = (int)$request->query('limit', 20);
        $limit = max(1, min(50, $limit));

        if ($q === '') {
            return response()->json(['ok' => true, 'items' => []]);
        }

        $itemsQ = LazadaBrand::query()->select(['brand_id', 'name']);
        if ($region) {
            $itemsQ->where('region', $region);
        }
        $items = $itemsQ
            ->where('name', 'like', '%' . $q . '%')
            ->orderBy('name')
            ->limit($limit)
            ->get()
            ->map(fn($b) => ['brand_id' => (int)$b->brand_id, 'name' => (string)$b->name]);

        return response()->json(['ok' => true, 'items' => $items]);
    }

    private function extractBrandRows($body): array
    {
        if (!is_array($body)) {
            return [];
        }

        $data = $body['data'] ?? $body;

        $candidates = [
            $data['brands'] ?? null,
            $data['brand_list'] ?? null,
            $data['brandList'] ?? null,
            $data['module']['brands'] ?? null,
            $data['result']['brands'] ?? null,
            $data['items'] ?? null,
        ];

        foreach ($candidates as $cand) {
            if (is_array($cand) && array_is_list($cand)) {
                return $cand;
            }
        }

        return $this->findBrandRowListRecursive($data) ?? [];
    }

    private function findBrandRowListRecursive($node): ?array
    {
        if (!is_array($node)) {
            return null;
        }

        if (array_is_list($node) && !empty($node) && is_array($node[0])) {
            $first = $node[0];
            $hasId = array_key_exists('brand_id', $first) || array_key_exists('id', $first);
            $hasName = array_key_exists('name', $first) || array_key_exists('brand_name', $first);
            if ($hasId && $hasName) {
                return $node;
            }
        }

        foreach ($node as $v) {
            if (is_array($v)) {
                $r = $this->findBrandRowListRecursive($v);
                if ($r !== null) {
                    return $r;
                }
            }
        }

        return null;
    }


    private function currentRegion(): ?string
    {
        $setting = LazadaSetting::query()->first()?->decrypted();
        if ($setting && !empty($setting->region)) {
            return (string)$setting->region;
        }
        return null;
    }

}
