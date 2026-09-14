<?php

namespace Extensions\lazada\Services\Lazada;

use App\Integrations\Listings\ListingVariations;
use App\Support\VariationRows;
use Extensions\lazada\Models\LazadaApiLog;

/**
 * A variation switched off for this store stops selling on its Lazada
 * item, and one switched back on sells again. Either way it is the same
 * SKU, disabled rather than removed, so it returns with its history.
 *
 * plan() reads the item as the update's own read found it. A SKU the store
 * must not sell that Lazada still holds active is deactivated through
 * /product/deactivate, one call per SKU, so each refusal names its own SKU.
 * A SKU the store sells that Lazada holds inactive is sent active in the
 * update, but only while the item itself is active: an item switched off
 * as a whole stays off until it is activated.
 */
final class LazadaVariationSwitch
{
    /** SKU statuses that are already off, or gone, on Lazada. */
    private const OFF_STATES = ['inactive', 'deleted'];

    public function __construct(private LazadaClient $client, private LazadaPushPayload $payload)
    {
    }

    /**
     * What an update does with each variation's status on an item already
     * on Lazada: the SKU rows to send (a returning SKU carries Status
     * active), the SKUs to deactivate, and the seller SKUs switched back on.
     * No read, no change.
     *
     * @param  array<int, array<string, true>>  $hidden  ListingVariations::hidden() for this store
     * @param  array{status?: string, skus?: list<array{sku_id: ?int, seller_sku: string, status: string}>}|null  $liveItem  LazadaLiveListing::shapeOf()
     * @param  list<array<string, mixed>>  $skus  the SKU rows the update sends
     * @return array{skus: list<array<string, mixed>>, off: list<array{sku_id: int, seller_sku: string}>, back: list<string>}
     */
    public static function plan(int $productId, array $hidden, ?array $liveItem, array $skus): array
    {
        $out = ['skus' => $skus, 'off' => [], 'back' => []];
        if ($liveItem === null) {
            return $out;
        }

        $sent = [];
        foreach ($skus as $i => $row) {
            $k = self::key($row['SellerSku'] ?? '');
            if ($k !== '') {
                $sent[$k] = $i;
            }
        }
        $itemActive = ($liveItem['status'] ?? '') === 'active';

        foreach ((array) ($liveItem['skus'] ?? []) as $live) {
            $sku = trim((string) ($live['seller_sku'] ?? ''));
            if ($sku === '') {
                continue;
            }
            $status = (string) ($live['status'] ?? '');

            if (! ListingVariations::allows($hidden, $productId, $sku)) {
                if (($live['sku_id'] ?? null) !== null && ! in_array($status, self::OFF_STATES, true)) {
                    $out['off'][] = ['sku_id' => (int) $live['sku_id'], 'seller_sku' => $sku];
                }

                continue;
            }

            $i = $sent[self::key($sku)] ?? null;
            if ($itemActive && $status === 'inactive' && $i !== null) {
                $out['skus'][$i]['Status'] = 'active';
                $out['back'][] = $sku;
            }
        }

        return $out;
    }

    /**
     * Deactivate each SKU on the item. Lazada saying one is already
     * inactive (E0004) counts as done.
     *
     * @param  list<array{sku_id: int, seller_sku: string}>  $off
     * @return array{off: list<string>, refused: array<string, string>} the seller SKUs now off, and each refused one with Lazada's reason
     */
    public function switchOff(object $setting, array $creds, string $itemId, array $off): array
    {
        $done = [];
        $refused = [];
        if (trim($itemId) === '') {
            return ['off' => $done, 'refused' => $refused];
        }

        foreach ($off as $sku) {
            $apiPath = '/product/deactivate';
            $params = [
                'app_key' => (string) $creds['app_key'],
                'sign_method' => 'sha256',
                'timestamp' => (string) round(microtime(true) * 1000),
                'access_token' => (string) $creds['access_token'],
                'payload' => json_encode(['Request' => ['Product' => [
                    'ItemId' => (int) $itemId,
                    'Skus' => ['SkuId' => (int) $sku['sku_id'], 'SellerSku' => (string) $sku['seller_sku']],
                ]]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ];
            $params['sign'] = $this->client->sign($apiPath, $params, (string) $creds['app_secret']);

            try {
                $result = $this->client->post((string) $setting->region, $apiPath, $params);
            } catch (\Throwable $e) {
                $refused[$sku['seller_sku']] = \App\Support\TransportError::plain($e, 'Lazada');

                continue;
            }

            LazadaApiLog::safeCreate([
                'pack' => 'lazada.listings.variation_off', 'method' => 'POST',
                'api_path' => $apiPath, 'auth_required' => true,
                'request_params' => $params,
                'response_status' => (int) ($result['status'] ?? 0),
                'ok' => (bool) ($result['ok'] ?? false),
                'response_body' => $result['body'] ?? null, 'user_id' => auth()->id(),
            ]);

            $e = $this->payload->extractLazadaError($result);
            if (($e['ok'] ?? false) || self::alreadyOff($e)) {
                $done[] = (string) $sku['seller_sku'];

                continue;
            }
            $code = (string) ($e['code'] ?? '');
            $refused[$sku['seller_sku']] = rtrim((string) ($e['message'] ?? 'no reason given'), '.')
                . ($code !== '' && $code !== 'UNKNOWN' ? ' (code ' . $code . ')' : '');
        }

        return ['off' => $done, 'refused' => $refused];
    }

    /**
     * The sentence a result message carries for what changed, or null.
     *
     * @param  list<string>  $off
     * @param  list<string>  $back
     */
    public static function summary(int $productId, array $off, array $back): ?string
    {
        $parts = [];
        if ($off !== []) {
            $parts[] = 'Switched off on Lazada: ' . implode(', ', self::labels($productId, $off)) . '.';
        }
        if ($back !== []) {
            $parts[] = 'Switched back on: ' . implode(', ', self::labels($productId, $back)) . '.';
        }

        return $parts === [] ? null : implode(' ', $parts);
    }

    /**
     * The line a refused switch-off leaves on the listing, or null.
     *
     * @param  array<string, string>  $refused  seller SKU => Lazada's reason
     */
    public static function refusal(int $productId, array $refused): ?string
    {
        if ($refused === []) {
            return null;
        }
        $labels = self::labels($productId, array_map('strval', array_keys($refused)));
        $lines = [];
        foreach (array_values($refused) as $i => $reason) {
            $lines[] = 'Lazada refused to switch off ' . $labels[$i] . ': ' . rtrim($reason, '.') . '.';
        }

        return implode(' ', $lines);
    }

    /** The non-empty parts joined by a space, or null when there are none. */
    public static function line(?string ...$parts): ?string
    {
        $parts = array_values(array_filter(array_map(fn ($p) => trim((string) $p), $parts), fn ($p) => $p !== ''));

        return $parts === [] ? null : implode(' ', $parts);
    }

    /**
     * Each seller SKU as "Red (SKU)", by the catalog's variation name, or the
     * bare SKU when the catalog has no name for it.
     *
     * @param  list<string>  $skus
     * @return list<string>
     */
    private static function labels(int $productId, array $skus): array
    {
        $names = [];
        foreach (VariationRows::forProducts([$productId])->get($productId) ?? [] as $row) {
            $k = self::key($row->sku ?? '');
            $name = trim((string) ($row->option_value_name ?? ''));
            if ($k !== '' && $name !== '') {
                $names[$k] = $name;
            }
        }

        return array_map(function ($sku) use ($names) {
            $name = $names[self::key($sku)] ?? '';

            return $name !== '' ? $name . ' (' . $sku . ')' : $sku;
        }, $skus);
    }

    /** @param array{code?: ?string, message?: ?string} $error */
    private static function alreadyOff(array $error): bool
    {
        return strtoupper(trim((string) ($error['code'] ?? ''))) === 'E0004'
            || str_contains(strtoupper((string) ($error['message'] ?? '')), 'E0004');
    }

    private static function key(mixed $sku): string
    {
        return strtolower(trim((string) $sku));
    }
}
