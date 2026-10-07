<?php

namespace App\Support\Fulfilment;

final class CourierTally
{
    public const UNKNOWN = 'Unknown';

    private array $couriers = [];

    private int $total = 0;

    public function add(int $orderId, ?string $rawCourier): void
    {
        $label = self::displayName($rawCourier);
        $key = self::key($label);

        if (! isset($this->couriers[$key])) {
            $this->couriers[$key] = ['label' => $label, 'count' => 0, 'ids' => []];
        }

        $this->couriers[$key]['count']++;
        $this->couriers[$key]['ids'][] = $orderId;
        $this->total++;
    }

    public function idsFor(string $key): ?array
    {
        return $this->couriers[$key]['ids'] ?? null;
    }

    public function has(string $key): bool
    {
        return isset($this->couriers[$key]);
    }

    public function forStrip(string $activeKey = ''): array
    {
        $items = $this->couriers;
        uasort($items, fn ($a, $b) => $b['count'] <=> $a['count']);

        return [
            'total' => $this->total,
            'active' => $this->has($activeKey) ? $activeKey : '',
            'items' => array_map(
                fn ($c) => ['label' => $c['label'], 'count' => $c['count']],
                $items
            ),
        ];
    }

    public function isEmpty(): bool
    {
        return $this->total === 0;
    }

    public static function displayName(?string $raw): string
    {
        $name = trim((string) $raw);

        if ($name === '') {
            return self::UNKNOWN;
        }

        if (preg_match('/delivery:\s*([^,]+)/i', $name, $m)) {
            $name = trim($m[1]);
        } elseif (preg_match('/pickup:\s*([^,]+)/i', $name, $m)) {
            $name = trim($m[1]);
        }

        $name = preg_replace('/\s+PH$/i', '', $name) ?? $name;

        return $name !== '' ? $name : self::UNKNOWN;
    }

    public static function key(string $displayName): string
    {
        return mb_strtolower(trim($displayName));
    }
}
