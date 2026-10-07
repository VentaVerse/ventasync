<?php

namespace Extensions\shopee\Services\Shopee;

use App\Support\MarketplaceAnswer;
use Extensions\shopee\Models\ShopeeApiLog;
use Extensions\shopee\Models\ShopeeLogistic;
use Illuminate\Support\Facades\DB;

class ShopeeStoreSetup
{
    public function __construct(private ShopeeClient $client)
    {
    }

    public function categories(array $auth): array
    {
        $path = '/api/v2/product/get_category';
        $language = $auth['region'] ?: 'en';

        $result = $this->client->shopGet(
            $auth['mode'],
            (int) $auth['partner_id'],
            (string) $auth['partner_key'],
            (string) $auth['access_token'],
            (int) $auth['shop_id'],
            $path,
            ['language' => $language]
        );
        $this->log('shopee.categories.fetch', $path, ['language' => $language], $result);

        $body = $result['body'] ?? null;
        $nodes = [];
        if (is_array($body)) {
            $response = $body['response'] ?? $body;
            if (isset($response['category_list']) && is_array($response['category_list'])) {
                $nodes = $response['category_list'];
            }
        }
        if (!($result['ok'] ?? false) || empty($nodes)) {
            return ['ok' => false, 'count' => 0, 'message' => 'Shopee did not answer with its categories: '
                . MarketplaceAnswer::plain('Shopee', ['ok' => false, 'body' => is_array($body) ? $body : []])];
        }

        $rows = [];
        $this->flatten($nodes, $rows, null, 0);
        if (empty($rows)) {
            return ['ok' => false, 'count' => 0, 'message' => 'Shopee answered with an empty category list. The stored categories were left as they were.'];
        }
        $i = 1;
        foreach ($rows as &$r) {
            $r['id'] = $i++;
        }
        unset($r);

        DB::transaction(function () use ($rows) {
            DB::table('shopee_categories')->delete();
            foreach (array_chunk($rows, 1000) as $chunk) {
                DB::table('shopee_categories')->insert($chunk);
            }
        });

        return ['ok' => true, 'count' => count($rows), 'message' => number_format(count($rows)) . ' categories fetched.'];
    }

    public function couriers(array $auth): array
    {
        $path = '/api/v2/logistics/get_channel_list';

        $result = $this->client->shopGet(
            $auth['mode'],
            (int) $auth['partner_id'],
            (string) $auth['partner_key'],
            (string) $auth['access_token'],
            (int) $auth['shop_id'],
            $path
        );
        $this->log('shopee.logistics.fetch', $path, [], $result);

        if (!($result['ok'] ?? false)) {
            $body = $result['body'] ?? null;
            return ['ok' => false, 'count' => 0, 'message' => 'Shopee did not answer with its couriers: '
                . MarketplaceAnswer::plain('Shopee', ['ok' => false, 'body' => is_array($body) ? $body : []])];
        }

        $body = $result['body'] ?? [];
        $list = ($body['response'] ?? $body)['logistics_channel_list'] ?? [];
        if (empty($list)) {
            return ['ok' => false, 'count' => 0, 'message' => 'Shopee answered with no couriers. Switch couriers on in Seller Centre, then fetch again.'];
        }

        $saved = 0;
        foreach ($list as $ch) {
            $channelId = (int) ($ch['logistics_channel_id'] ?? 0);
            if (!$channelId) {
                continue;
            }
            ShopeeLogistic::updateOrCreate(
                ['logistics_channel_id' => $channelId],
                [
                    'logistics_channel_name' => (string) ($ch['logistics_channel_name'] ?? ''),
                    'cod_enabled' => (bool) ($ch['cod_enabled'] ?? false),
                    'enabled' => (bool) ($ch['enabled'] ?? false),
                    'force_enable' => (bool) ($ch['force_enable'] ?? false),
                    'fee_type' => (string) ($ch['fee_type'] ?? ''),
                    'weight_limit' => $ch['weight_limit'] ?? null,
                    'item_max_dimension' => $ch['item_max_dimension'] ?? null,
                    'volume_limit' => $ch['volume_limit'] ?? null,
                    'mask_channel_id' => (int) ($ch['mask_channel_id'] ?? 0),
                    'logistics_description' => (string) ($ch['logistics_description'] ?? ''),
                    'support_pre_order' => (bool) ($ch['support_pre_order'] ?? false),
                    'support_cross_border' => (bool) ($ch['support_cross_border'] ?? false),
                    'raw_data' => $ch,
                ]
            );
            $saved++;
        }

        return ['ok' => true, 'count' => $saved, 'message' => $saved . ' ' . ($saved === 1 ? 'courier' : 'couriers') . ' fetched.'];
    }

    private function log(string $pack, string $path, array $params, array $result): void
    {
        ShopeeApiLog::safeCreate([
            'pack' => $pack,
            'method' => 'GET',
            'api_path' => $path,
            'auth_required' => true,
            'request_params' => $params,
            'response_status' => $result['status'] ?? null,
            'ok' => (bool) ($result['ok'] ?? false),
            'response_body' => $result['body'] ?? null,
            'user_id' => auth()->id(),
        ]);
    }

    private function flatten(array $nodes, array &$rows, ?int $parentId, int $level): void
    {
        foreach ($nodes as $n) {
            if (!is_array($n)) {
                continue;
            }
            $categoryId = isset($n['category_id']) ? (int) $n['category_id'] : null;
            $name = (string) ($n['original_category_name'] ?? $n['display_category_name'] ?? $n['category_name'] ?? '');
            $hasChildren = (bool) ($n['has_children'] ?? false);
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
                $this->flatten($n['children'], $rows, $categoryId, $level + 1);
            }
        }
    }
}
