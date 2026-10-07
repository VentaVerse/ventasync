<?php

namespace Extensions\lazada\Services\Lazada;

use App\Integrations\Listings\ListingVariations;
use App\Support\VariationRows;
use Extensions\lazada\Models\LazadaApiLog;

final class LazadaVariationSwitch
{
    private const OFF_STATES = ['inactive', 'deleted'];

    public function __construct(private LazadaClient $client, private LazadaPushPayload $payload)
    {
    }

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

    public static function line(?string ...$parts): ?string
    {
        $parts = array_values(array_filter(array_map(fn ($p) => trim((string) $p), $parts), fn ($p) => $p !== ''));

        return $parts === [] ? null : implode(' ', $parts);
    }

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
