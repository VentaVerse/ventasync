<?php

namespace Extensions\venta\Services\Venta;

use Extensions\venta\Models\VentaBrand;
use Extensions\venta\Models\VentaCategory;
use Extensions\venta\Models\VentaOrderStatusMap;
use Extensions\venta\Models\VentaSetting;

/**
 * The reads a Venta store needs before anything can be pushed or mapped:
 * its categories, its brands and its order statuses. One place for the
 * three (the owner 2026-09-06: every channel initialises the same way, behind
 * the setup popup, right after the first successful connection and again
 * on demand). Each method answers {ok, count, message}: a plain sentence,
 * never a payload.
 */
class VentaStoreSetup
{
    public function categories(VentaSetting $setting): array
    {
        $result = (new VentaClient($setting))->getCategories();
        if (! ($result['ok'] ?? false)) {
            return ['ok' => false, 'count' => 0, 'message' => 'The store did not answer with its categories: ' . self::why($result)];
        }
        $categories = is_array($result['body']) ? $result['body'] : [];
        VentaCategory::where('venta_setting_id', $setting->id)->delete();
        foreach ($categories as $c) {
            if (! is_array($c) || ! isset($c['id'])) {
                continue;
            }
            VentaCategory::create([
                'venta_setting_id' => $setting->id, 'venta_category_id' => $c['id'],
                'name' => $c['name'] ?? '', 'slug' => $c['slug'] ?? null, 'parent_id' => $c['parent_id'] ?? null, 'is_active' => $c['is_active'] ?? true,
            ]);
        }
        $setting->forceFill(['last_category_sync_at' => now()])->save();
        $n = count($categories);

        return ['ok' => true, 'count' => $n, 'message' => number_format($n) . ' ' . ($n === 1 ? 'category' : 'categories') . ' fetched.'];
    }

    public function brands(VentaSetting $setting): array
    {
        $result = (new VentaClient($setting))->getBrands();
        if (! ($result['ok'] ?? false)) {
            return ['ok' => false, 'count' => 0, 'message' => 'The store did not answer with its brands: ' . self::why($result)];
        }
        $brands = is_array($result['body']) ? $result['body'] : [];
        VentaBrand::where('venta_setting_id', $setting->id)->delete();
        foreach ($brands as $b) {
            if (! is_array($b) || ! isset($b['id'])) {
                continue;
            }
            VentaBrand::create([
                'venta_setting_id' => $setting->id, 'venta_brand_id' => $b['id'],
                'name' => $b['name'] ?? '', 'slug' => $b['slug'] ?? null, 'is_active' => $b['is_active'] ?? true,
            ]);
        }
        $n = count($brands);

        return ['ok' => true, 'count' => $n, 'message' => number_format($n) . ' ' . ($n === 1 ? 'brand' : 'brands') . ' fetched.'];
    }

    /**
     * The store's order statuses, delta-merged into the status map: an
     * existing mapping is kept, a renamed status is renamed, a vanished one
     * goes, a new one arrives unmapped (order_status_id 0) for the operator
     * to pick under Status mapping.
     */
    public function statuses(VentaSetting $setting): array
    {
        $result = (new VentaClient($setting))->get('order-statuses');
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
            $row = VentaOrderStatusMap::firstOrNew(['venta_setting_id' => $setting->id, 'venta_status_id' => $vid]);
            if (! $row->exists) {
                $row->order_status_id = 0;
            }
            $row->venta_status_name = (string) ($s['name'] ?? $s['label'] ?? '');
            $row->save();
            $apiIds[] = $vid;
        }
        VentaOrderStatusMap::where('venta_setting_id', $setting->id)->whereNotIn('venta_status_id', $apiIds ?: [0])->delete();
        $n = count($apiIds);

        return ['ok' => true, 'count' => $n, 'message' => number_format($n) . ' ' . ($n === 1 ? 'status' : 'statuses') . ' fetched. Map them under Status mapping.'];
    }

    private static function why(array $result): string
    {
        $body = $result['body'] ?? [];

        return (string) (is_array($body) ? ($body['error'] ?? $body['message'] ?? 'no answer') : 'no answer');
    }
}
