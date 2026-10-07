<?php

namespace Extensions\tiktok\Services\TikTok;

use App\Support\MarketplaceAnswer;
use Extensions\tiktok\Models\TikTokApiLog;
use Extensions\tiktok\Models\TikTokCategory;
use Extensions\tiktok\Models\TikTokSetting;

class TikTokStoreSetup
{
    public function __construct(private TikTokClient $client)
    {
    }

    public static function creds(TikTokSetting $raw): array
    {
        $d = $raw->decrypted();
        $sandbox = $raw->mode === 'sandbox';

        return [
            'sandbox' => $sandbox,
            'app_key' => $sandbox ? ($d->sandbox_app_key ?? '') : ($d->app_key ?? ''),
            'app_secret' => $sandbox ? ($d->sandbox_app_secret ?? '') : ($d->app_secret ?? ''),
            'token' => $sandbox ? ($d->sandbox_access_token ?? '') : ($d->access_token ?? ''),
            'shop_cipher' => $sandbox ? ($raw->sandbox_shop_cipher ?? '') : ($raw->shop_cipher ?? ''),
        ];
    }

    public function shop(TikTokSetting $raw): array
    {
        $c = self::creds($raw);
        $path = '/authorization/202309/shops';
        $result = $this->client->get($c['app_key'], $c['app_secret'], $c['token'], $path);
        $this->log('tiktok.shops', $path, ['app_key' => $c['app_key']], $result);
        $shops = is_array($result['body'] ?? null) ? ($result['body']['data']['shops'] ?? []) : [];
        if (! ($result['ok'] ?? false) || $shops === []) {
            return ['ok' => false, 'count' => 0, 'message' => 'TikTok did not answer with the shop: '
                . MarketplaceAnswer::plain('TikTok', ['ok' => false, 'body' => is_array($result['body'] ?? null) ? $result['body'] : []])];
        }
        $shop = $shops[0];
        $sandbox = $c['sandbox'];
        $raw->{$sandbox ? 'sandbox_shop_id' : 'shop_id'} = $shop['id'] ?? null;
        $raw->{$sandbox ? 'sandbox_shop_cipher' : 'shop_cipher'} = $shop['cipher'] ?? null;
        $raw->{$sandbox ? 'sandbox_shop_code' : 'shop_code'} = $shop['code'] ?? null;
        $raw->{$sandbox ? 'sandbox_shop_name' : 'shop_name'} = $shop['name'] ?? null;
        $raw->region = $shop['region'] ?? $raw->region;

        $whPath = '/logistics/202309/warehouses';
        $wh = $this->client->get($c['app_key'], $c['app_secret'], $c['token'], $whPath, [], $shop['cipher'] ?? null);
        $this->log('tiktok.warehouses', $whPath, [], $wh);
        $warehouses = ($wh['ok'] ?? false) ? ($wh['body']['data']['warehouses'] ?? []) : [];
        if ($warehouses !== []) {
            $sales = collect($warehouses)->firstWhere('type', 'SALES_WAREHOUSE');
            $raw->{$sandbox ? 'sandbox_warehouse_id' : 'warehouse_id'} = ($sales['id'] ?? null) ?: ($warehouses[0]['id'] ?? null);
        }
        $raw->save();

        $name = (string) ($shop['name'] ?? $shop['id'] ?? 'shop');

        return ['ok' => true, 'count' => 1, 'message' => 'Shop ' . $name . ' fetched' . ($warehouses !== [] ? ', with its warehouse.' : '. No warehouse was reported.')];
    }

    public function categories(TikTokSetting $raw): array
    {
        $c = self::creds($raw);
        if ($c['shop_cipher'] === '') {
            return ['ok' => false, 'count' => 0, 'message' => 'The shop has not been fetched yet, and categories need its cipher. Fetch the shop first.'];
        }
        $result = $this->client->getCategories($c['app_key'], $c['app_secret'], $c['token'], $c['shop_cipher']);
        $this->log('tiktok.categories.fetch', '/product/202309/categories', [], $result);
        $categories = ($result['ok'] ?? false) ? ($result['body']['data']['categories'] ?? []) : [];
        if (! ($result['ok'] ?? false) || $categories === []) {
            return ['ok' => false, 'count' => 0, 'message' => 'TikTok did not answer with its categories: '
                . MarketplaceAnswer::plain('TikTok', ['ok' => false, 'body' => is_array($result['body'] ?? null) ? $result['body'] : []])];
        }
        $now = now();
        foreach ($categories as $cat) {
            TikTokCategory::updateOrCreate(['id' => (string) $cat['id']], [
                'parent_id' => isset($cat['parent_id']) && $cat['parent_id'] !== '0' ? (string) $cat['parent_id'] : null,
                'name' => $cat['local_name'] ?? $cat['name'] ?? '',
                'is_leaf' => (bool) ($cat['is_leaf'] ?? false),
                'permission_statuses' => $cat['permission_statuses'] ?? null,
                'synced_at' => $now,
            ]);
        }

        return ['ok' => true, 'count' => count($categories), 'message' => number_format(count($categories)) . ' categories fetched.'];
    }

    private function log(string $pack, string $path, array $params, array $result): void
    {
        TikTokApiLog::safeCreate([
            'pack' => $pack, 'method' => 'GET', 'api_path' => $path, 'auth_required' => true,
            'request_params' => $params, 'response_status' => (int) ($result['status'] ?? 0),
            'ok' => (bool) ($result['ok'] ?? false), 'response_body' => $result['body'] ?? null, 'user_id' => auth()->id(),
        ]);
    }
}
