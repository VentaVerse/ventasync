<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

final class OrderSnapshot
{
    public const PLACEHOLDER = 'Variation';

    private static array $pairsMemo = [];

    private function __construct(private readonly array $lines)
    {
    }

    public static function take(?int $orderId): self
    {
        self::$pairsMemo = [];

        return new self(self::read($orderId));
    }

    private static function read(?int $orderId): array
    {
        $lines = [];
        if ($orderId) {
            $variations = DB::table('order_variations')->where('order_id', $orderId)
                ->orderBy('order_variation_id')
                ->get(['order_product_id', 'name', 'value', 'product_variation_type_id', 'product_variation_value_id', 'type'])
                ->groupBy('order_product_id');
            foreach (DB::table('order_products')->where('order_id', $orderId)->orderBy('order_product_id')
                ->get(['order_product_id', 'sku', 'name', 'cost']) as $op) {
                $key = self::key($op->sku, $op->name);
                if ($key === '' || isset($lines[$key])) {
                    continue;
                }
                $lines[$key] = [
                    'cost' => (float) $op->cost,
                    'variations' => ($variations->get($op->order_product_id) ?? collect())->map(fn ($v) => [
                        'name' => (string) $v->name,
                        'value' => (string) $v->value,
                        'product_variation_type_id' => (int) $v->product_variation_type_id,
                        'product_variation_value_id' => (int) $v->product_variation_value_id,
                        'type' => (string) ($v->type ?: 'text'),
                    ])->values()->all(),
                ];
            }
        }

        return $lines;
    }

    public static function keepOnOrder(?object $existing, array $orderData): array
    {
        if ($existing === null) {
            return $orderData;
        }
        if (trim((string) ($existing->store_name ?? '')) !== '') {
            unset($orderData['store_name']);
        }
        $saved = strtoupper(trim((string) ($existing->currency_code ?? '')));
        if ($saved !== '' && $saved === strtoupper(trim((string) ($orderData['currency_code'] ?? $saved)))) {
            unset($orderData['currency_id'], $orderData['currency_code'], $orderData['currency_value']);
        }

        return $orderData;
    }

    public static function storeNames(array $salesOrderIds): array
    {
        $ids = array_values(array_filter(array_map('intval', $salesOrderIds)));

        return $ids === [] ? [] : DB::table('orders')->whereIn('order_id', $ids)
            ->where('store_name', '!=', '')->pluck('store_name', 'order_id')->map(fn ($n) => (string) $n)->all();
    }

    public function cost(?string $sku, ?string $name, int $productId, int $variationValueId): float
    {
        $saved = $this->lines[self::key($sku, $name)]['cost'] ?? 0.0;

        return $saved > 0 ? $saved : LineCost::resolve($productId, $variationValueId);
    }

    public function variations(?string $sku, ?string $name, ?string $storeText, int $variationValueId = 0): array
    {
        $saved = $this->lines[self::key($sku, $name)]['variations'] ?? [];
        $isPlaceholder = $saved !== [] && collect($saved)->every(fn ($v) => $v['name'] === self::PLACEHOLDER);
        if ($saved !== [] && ! $isPlaceholder) {
            return $saved;
        }

        $sku = trim((string) $sku);
        $pairs = $sku !== '' ? (CatalogVariations::forSkus([$sku])[$sku] ?? []) : [];
        if ($pairs !== []) {
            return array_map(fn ($p) => [
                'name' => (string) $p['name'],
                'value' => (string) $p['value'],
                'product_variation_type_id' => 0,
                'product_variation_value_id' => $variationValueId,
                'type' => 'text',
            ], $pairs);
        }

        if ($saved !== []) {
            return $saved;
        }

        $text = trim((string) $storeText);

        return $text === '' ? [] : [[
            'name' => self::PLACEHOLDER,
            'value' => $text,
            'product_variation_type_id' => 0,
            'product_variation_value_id' => $variationValueId,
            'type' => 'text',
        ]];
    }

    public static function savedPairs(?int $orderId, ?string $sku, ?string $name = null): ?array
    {
        if (! $orderId) {
            return null;
        }
        if (! array_key_exists($orderId, self::$pairsMemo)) {
            self::$pairsMemo[$orderId] = array_map(fn ($line) => $line['variations'], self::read($orderId));
        }
        $rows = self::$pairsMemo[$orderId][self::key($sku, $name)] ?? null;
        if ($rows === null || $rows === [] || collect($rows)->every(fn ($v) => $v['name'] === self::PLACEHOLDER)) {
            return null;
        }

        return array_map(fn ($v) => ['name' => $v['name'], 'value' => $v['value']], $rows);
    }

    private static function key(?string $sku, ?string $name): string
    {
        $sku = strtolower(trim((string) $sku));
        if ($sku !== '') {
            return $sku;
        }
        $name = strtolower(trim((string) $name));

        return $name === '' ? '' : 'name:' . $name;
    }
}
