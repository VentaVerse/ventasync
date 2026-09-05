<?php

namespace Extensions\lazada\Services\Lazada;

use App\Support\MarketplaceAnswer;
use Extensions\lazada\Models\LazadaApiLog;
use Extensions\lazada\Models\LazadaBrand;
use Extensions\lazada\Models\LazadaSetting;
use Illuminate\Support\Facades\DB;

/**
 * The reads a Lazada store needs before anything can be pushed: the
 * category tree and the brand list. One place for both (the Shopee shape,
 * the owner 2026-09-06: every channel initialises the same way, behind the
 * setup popup, right after authorisation and again on demand). Each
 * method answers {ok, count, message}: the message is the plain sentence
 * an operator reads, never a payload; the payload is on the API log.
 */
class LazadaStoreSetup
{
    public function __construct(private LazadaClient $client)
    {
    }

    /** @param array $creds LazadaSetting::activeCredentials() for the store */
    public function categories(LazadaSetting $setting, array $creds): array
    {
        $apiPath = '/category/tree/get';
        $params = [
            'app_key' => (string) $creds['app_key'],
            'sign_method' => 'sha256',
            'timestamp' => (string) round(microtime(true) * 1000),
        ];
        $params['sign'] = $this->client->sign($apiPath, $params, (string) $creds['app_secret']);
        $result = $this->client->get((string) $setting->region, $apiPath, $params);
        $this->log('lazada.categories.fetch', $apiPath, $params, $result);

        $body = $result['body'] ?? null;
        $nodes = [];
        if (is_array($body)) {
            if (isset($body['data']) && is_array($body['data'])) {
                $nodes = $body['data'];
            }
        }
        if (! ($result['ok'] ?? false) || $nodes === []) {
            return ['ok' => false, 'count' => 0, 'message' => 'Lazada did not answer with its categories: '
                . MarketplaceAnswer::plain('Lazada', ['ok' => false, 'body' => is_array($body) ? $body : []])];
        }
        $rows = [];
        $this->flatten($nodes, $rows, null, 0);
        if ($rows === []) {
            return ['ok' => false, 'count' => 0, 'message' => 'Lazada answered with an empty category list. The stored categories were left as they were.'];
        }
        $i = 1;
        foreach ($rows as &$r) {
            $r['id'] = $i++;
        }
        unset($r);
        DB::transaction(function () use ($rows) {
            DB::table('lazada_categories')->delete();
            foreach (array_chunk($rows, 1000) as $chunk) {
                DB::table('lazada_categories')->insert($chunk);
            }
        });

        return ['ok' => true, 'count' => count($rows), 'message' => number_format(count($rows)) . ' categories fetched.'];
    }

    /**
     * The brand list, paged. Lazada's brand catalogue runs to tens of
     * thousands of rows; twenty pages of two hundred is what the Catalog
     * page has always read, and what a listing's brand picker needs.
     *
     * @param array $creds LazadaSetting::activeCredentials() for the store
     */
    public function brands(LazadaSetting $setting, array $creds, int $maxPages = 20, int $pageSize = 200): array
    {
        $apiPath = '/category/brands/query';
        $region = (string) $setting->region;
        $saved = 0;
        $lastResult = null;
        for ($pageNo = 1; $pageNo <= $maxPages; $pageNo++) {
            $params = [
                'app_key' => (string) $creds['app_key'],
                'sign_method' => 'sha256',
                'timestamp' => (string) round(microtime(true) * 1000),
                'startRow' => (string) (($pageNo - 1) * $pageSize),
                'pageSize' => (string) $pageSize,
            ];
            $params['sign'] = $this->client->sign($apiPath, $params, (string) $creds['app_secret']);
            $result = $this->client->get($region, $apiPath, $params);
            $lastResult = $result;
            $this->log('lazada.brands.query', $apiPath, $params, $result);
            if (! ($result['ok'] ?? false)) {
                break;
            }
            $brands = self::brandRows($result['body'] ?? null);
            if ($brands === []) {
                break;
            }
            DB::transaction(function () use ($brands, $region, &$saved) {
                foreach ($brands as $b) {
                    $brandId = (int) ($b['brand_id'] ?? $b['id'] ?? 0);
                    $name = trim((string) ($b['name'] ?? $b['brand_name'] ?? ''));
                    if ($brandId <= 0 || $name === '') {
                        continue;
                    }
                    LazadaBrand::query()->updateOrCreate(['region' => $region, 'brand_id' => $brandId], ['name' => $name, 'raw' => $b]);
                    $saved++;
                }
            });
            if (count($brands) < $pageSize) {
                break;
            }
        }
        if ($saved === 0) {
            return ['ok' => false, 'count' => 0, 'message' => 'Lazada did not answer with its brands: '
                . MarketplaceAnswer::plain('Lazada', ['ok' => false, 'body' => is_array($lastResult['body'] ?? null) ? $lastResult['body'] : []])];
        }

        return ['ok' => true, 'count' => $saved, 'message' => number_format($saved) . ' brands fetched.'];
    }

    /** Lazada's brand answers vary in shape; the first list of brand-looking rows wins. */
    public static function brandRows($body): array
    {
        if (! is_array($body)) {
            return [];
        }
        $data = $body['data'] ?? $body;
        foreach ([$data['brands'] ?? null, $data['brand_list'] ?? null, $data['brandList'] ?? null, $data['module']['brands'] ?? null, $data['result']['brands'] ?? null, $data['items'] ?? null, is_array($data) && array_is_list($data) ? $data : null] as $cand) {
            if (is_array($cand) && $cand !== [] && is_array($cand[0] ?? null) && (isset($cand[0]['brand_id']) || isset($cand[0]['id']))) {
                return array_values(array_filter($cand, 'is_array'));
            }
        }

        return [];
    }

    private function flatten(array $nodes, array &$rows, ?int $parentId, int $level): void
    {
        foreach ($nodes as $n) {
            if (! is_array($n)) {
                continue;
            }
            $categoryId = isset($n['category_id']) ? (int) $n['category_id'] : null;
            $name = (string) ($n['name'] ?? '');
            if (! $categoryId || $name === '') {
                continue;
            }
            $rows[] = [
                'category_id' => $categoryId, 'name' => $name,
                'leaf' => (bool) ($n['leaf'] ?? false), 'var' => array_key_exists('var', $n) ? (bool) $n['var'] : null,
                'parent_id' => $parentId, 'level' => $level, 'created_at' => now(), 'updated_at' => now(),
            ];
            if (! empty($n['children']) && is_array($n['children'])) {
                $this->flatten($n['children'], $rows, $categoryId, $level + 1);
            }
        }
    }

    private function log(string $pack, string $path, array $params, array $result): void
    {
        LazadaApiLog::safeCreate([
            'pack' => $pack, 'method' => 'GET', 'api_path' => $path, 'auth_required' => false,
            'request_params' => $params, 'response_status' => (int) ($result['status'] ?? 0),
            'ok' => (bool) ($result['ok'] ?? false), 'response_body' => $result['body'] ?? $result, 'user_id' => auth()->id(),
        ]);
    }
}
