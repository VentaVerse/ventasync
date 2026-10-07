<?php

namespace Extensions\ventacart\Services\VentaCart;

use Extensions\ventacart\Models\VentaCartBrand;
use Extensions\ventacart\Models\VentaCartCategory;
use Extensions\ventacart\Models\VentaCartOrderStatusMap;
use Extensions\ventacart\Models\VentaCartSetting;

class VentaCartStoreSetup
{
    public function categories(VentaCartSetting $setting): array
    {
        $result = (new VentaCartClient($setting))->getCategories();
        if (! ($result['ok'] ?? false)) {
            return ['ok' => false, 'count' => 0, 'message' => 'The store did not answer with its categories: ' . self::why($result)];
        }
        $categories = is_array($result['body']) ? $result['body'] : [];
        VentaCartCategory::where('ventacart_setting_id', $setting->id)->delete();
        foreach ($categories as $c) {
            if (! is_array($c) || ! isset($c['id'])) {
                continue;
            }
            VentaCartCategory::create([
                'ventacart_setting_id' => $setting->id, 'ventacart_category_id' => $c['id'],
                'name' => $c['name'] ?? '', 'slug' => $c['slug'] ?? null, 'parent_id' => $c['parent_id'] ?? null, 'is_active' => $c['is_active'] ?? true,
            ]);
        }
        $setting->forceFill(['last_category_sync_at' => now()])->save();
        $n = count($categories);

        return ['ok' => true, 'count' => $n, 'message' => number_format($n) . ' ' . ($n === 1 ? 'category' : 'categories') . ' fetched.'];
    }

    public function brands(VentaCartSetting $setting): array
    {
        $result = (new VentaCartClient($setting))->getBrands();
        if (! ($result['ok'] ?? false)) {
            return ['ok' => false, 'count' => 0, 'message' => 'The store did not answer with its brands: ' . self::why($result)];
        }
        $brands = is_array($result['body']) ? $result['body'] : [];
        VentaCartBrand::where('ventacart_setting_id', $setting->id)->delete();
        foreach ($brands as $b) {
            if (! is_array($b) || ! isset($b['id'])) {
                continue;
            }
            VentaCartBrand::create([
                'ventacart_setting_id' => $setting->id, 'ventacart_brand_id' => $b['id'],
                'name' => $b['name'] ?? '', 'slug' => $b['slug'] ?? null, 'is_active' => $b['is_active'] ?? true,
            ]);
        }
        $n = count($brands);

        return ['ok' => true, 'count' => $n, 'message' => number_format($n) . ' ' . ($n === 1 ? 'brand' : 'brands') . ' fetched.'];
    }

    public function statuses(VentaCartSetting $setting): array
    {
        $result = (new VentaCartClient($setting))->get('order-statuses');
        if (! ($result['ok'] ?? false)) {
            return ['ok' => false, 'count' => 0, 'message' => 'The store did not answer with its order statuses: ' . self::why($result)];
        }
        $statuses = $result['body']['data'] ?? $result['body'] ?? [];
        if (! is_array($statuses) || $statuses === []) {
            return ['ok' => false, 'count' => 0, 'message' => 'The store answered with no order statuses.'];
        }
        $apiIds = [];
        foreach ($statuses as $s) {
            $vid = (int) ($s['id'] ?? $s['order_status_id'] ?? 0);
            if ($vid <= 0) {
                continue;
            }
            $row = VentaCartOrderStatusMap::firstOrNew(['ventacart_setting_id' => $setting->id, 'ventacart_status_id' => $vid]);
            if (! $row->exists) {
                $row->order_status_id = 0;
            }
            $row->ventacart_status_name = (string) ($s['name'] ?? $s['label'] ?? '');
            $row->save();
            $apiIds[] = $vid;
        }
        VentaCartOrderStatusMap::where('ventacart_setting_id', $setting->id)->whereNotIn('ventacart_status_id', $apiIds ?: [0])->delete();
        $n = count($apiIds);

        return ['ok' => true, 'count' => $n, 'message' => number_format($n) . ' ' . ($n === 1 ? 'status' : 'statuses') . ' fetched. Map them under Status mapping.'];
    }

    private static function why(array $result): string
    {
        $body = $result['body'] ?? [];

        return (string) (is_array($body) ? ($body['error'] ?? $body['message'] ?? 'no answer') : 'no answer');
    }
}
