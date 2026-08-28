<?php

namespace Extensions\venta\Services\Venta;

use App\Support\FulfilmentSteps;
use Extensions\venta\Models\VentaSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Where a Venta store places each of its order statuses, as the store
 * says it (#167).
 *
 * The store's queue is placement-driven: every status sits in one tab
 * from the store's own settings, and GET /order-statuses reports it. The
 * ERP mirrors that, so an order the storefront moved to To Handover is
 * in To Handover here, whatever the status is called. The name list in
 * FulfilmentSteps stays as the fallback for a status the store has not
 * placed, or a store that has not been asked yet.
 *
 * Refreshed on every pull and every single-order re-read: one small GET
 * beside the orders themselves, so the placement can never be older than
 * the status it is applied to.
 */
class VentaStatusPlacements
{
    public const TABLE = 'venta_status_placements';

    /** The store's tab words onto the shared lifecycle steps. */
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

    /** @var array<int, array<string, string>> per store, normalised status name => step, for this request */
    private static array $memo = [];

    /**
     * Ask the store and write what it says. Nothing is written when the
     * store does not answer, so a store that is down keeps the placements
     * it last reported rather than losing them.
     *
     * @return int how many statuses the store reported
     */
    public static function refresh(VentaSetting $setting, ?VentaClient $client = null): int
    {
        if (! Schema::hasTable(self::TABLE)) {
            return 0;
        }

        $client ??= new VentaClient($setting);
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
                ['venta_setting_id' => (int) $setting->id, 'venta_status_id' => (int) $row['id']],
                [
                    'venta_status_name' => mb_substr((string) ($row['name'] ?? ''), 0, 64),
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

    /**
     * The step a status sits in on this store, by the store's placement
     * first and the shared name list second. Null only when neither
     * knows, which the caller files under Other.
     */
    public static function stepFor(int $storeId, ?string $statusName): ?string
    {
        $key = self::key($statusName);
        $placed = self::map($storeId)[$key] ?? null;

        return $placed ?? FulfilmentSteps::stepFor('venta.orders', $statusName);
    }

    /** A resolver FulfilmentSteps::bucket() can take, bound to one store. */
    public static function resolver(int $storeId): \Closure
    {
        return fn (string $status): ?string => self::stepFor($storeId, $status);
    }

    /** Whether the store has ever reported its placements. */
    public static function known(int $storeId): bool
    {
        return self::map($storeId) !== [];
    }

    /** @return array<string, string> normalised status name => step */
    private static function map(int $storeId): array
    {
        if (isset(self::$memo[$storeId])) {
            return self::$memo[$storeId];
        }
        if (! Schema::hasTable(self::TABLE)) {
            return self::$memo[$storeId] = [];
        }

        $map = [];
        $rows = DB::table(self::TABLE)->where('venta_setting_id', $storeId)->whereNotNull('placement')->get(['venta_status_name', 'placement']);
        foreach ($rows as $row) {
            $step = self::STEP_FOR_PLACEMENT[(string) $row->placement] ?? null;
            if ($step !== null && (string) $row->venta_status_name !== '') {
                $map[self::key($row->venta_status_name)] = $step;
            }
        }

        return self::$memo[$storeId] = $map;
    }

    /** Keyed the way FulfilmentSteps keys names: uppercased, spaces folded to underscores. */
    private static function key(?string $name): string
    {
        return strtoupper(str_replace(' ', '_', trim((string) $name)));
    }

    public static function forget(): void
    {
        self::$memo = [];
    }
}
