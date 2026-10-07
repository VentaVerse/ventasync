<?php

namespace Extensions\ventacart\Services\VentaCart;

use App\Support\FulfilmentSteps;
use Extensions\ventacart\Models\VentaCartSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class VentaCartStatusPlacements
{
    public const TABLE = 'ventacart_status_placements';

    private const STEP_FOR_PLACEMENT = [
        'unpaid' => 'unpaid',
        'to_pack' => 'to_pack',
        'to_handover' => 'to_handover',
        'shipping' => 'shipping',
        'failed' => 'failed',
        'lost_damaged' => 'failed',
        'delivered' => 'delivered',
        'cancelled' => 'cancelled',
    ];

    private static array $memo = [];

    public static function refresh(VentaCartSetting $setting, ?VentaCartClient $client = null): int
    {
        if (! Schema::hasTable(self::TABLE)) {
            return 0;
        }

        $client ??= new VentaCartClient($setting);
        $res = $client->get('order-statuses');
        $body = $res['body'] ?? [];
        $rows = is_array($body['data'] ?? null) ? $body['data'] : (is_array($body) && array_is_list($body) ? $body : []);

        if (! ($res['ok'] ?? false) || $rows === []) {
            return 0;
        }

        $now = now();
        $count = 0;
        foreach ($rows as $row) {
            if (! is_array($row) || empty($row['id'])) {
                continue;
            }
            DB::table(self::TABLE)->updateOrInsert(
                ['ventacart_setting_id' => (int) $setting->id, 'ventacart_status_id' => (int) $row['id']],
                [
                    'ventacart_status_name' => mb_substr((string) ($row['name'] ?? ''), 0, 64),
                    'placement' => isset($row['placement']) && $row['placement'] !== '' ? (string) $row['placement'] : null,
                    'fetched_at' => $now,
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
            $count++;
        }

        unset(self::$memo[(int) $setting->id]);

        return $count;
    }

    public static function stepFor(int $storeId, ?string $statusName): ?string
    {
        $key = self::key($statusName);
        $placed = self::map($storeId)[$key] ?? null;

        return $placed ?? FulfilmentSteps::stepFor('ventacart.orders', $statusName);
    }

    public static function resolver(int $storeId): \Closure
    {
        return fn (string $status): ?string => self::stepFor($storeId, $status);
    }

    public static function known(int $storeId): bool
    {
        return self::map($storeId) !== [];
    }

    private static function map(int $storeId): array
    {
        if (isset(self::$memo[$storeId])) {
            return self::$memo[$storeId];
        }
        if (! Schema::hasTable(self::TABLE)) {
            return self::$memo[$storeId] = [];
        }

        $map = [];
        $rows = DB::table(self::TABLE)->where('ventacart_setting_id', $storeId)->whereNotNull('placement')->get(['ventacart_status_name', 'placement']);
        foreach ($rows as $row) {
            $step = self::STEP_FOR_PLACEMENT[(string) $row->placement] ?? null;
            if ($step !== null && (string) $row->ventacart_status_name !== '') {
                $map[self::key($row->ventacart_status_name)] = $step;
            }
        }

        return self::$memo[$storeId] = $map;
    }

    private static function key(?string $name): string
    {
        return strtoupper(str_replace(' ', '_', trim((string) $name)));
    }

    public static function forget(): void
    {
        self::$memo = [];
    }
}
