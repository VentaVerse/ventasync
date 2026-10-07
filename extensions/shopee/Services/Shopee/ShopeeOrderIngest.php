<?php

namespace Extensions\shopee\Services\Shopee;

use Extensions\shopee\Models\ShopeeApiLog;
use Extensions\shopee\Models\ShopeeOrder;
use Extensions\shopee\Models\ShopeeOrderProduct;
use Extensions\shopee\Models\ShopeeReturn;
use Extensions\shopee\Services\ShopeeCatalogOrderSync;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

// Each order is written under a lock, or two writers both read the old status and move stock twice.
// A pushed status is never applied; the order's current state is read from Shopee instead.
class ShopeeOrderIngest
{
    private const LOCK_SECONDS = 120;

    private const LOCK_WAIT_SECONDS = 20;

    public function __construct(private readonly ShopeeClient $client)
    {
    }

    public function orders(object $setting, array $auth, array $orderSns): array
    {
        $out = ['created' => 0, 'updated' => 0, 'errors' => 0, 'busy' => 0, 'missing' => []];
        $region = $auth['region'] ?? 'ph';
        $orderSns = array_values(array_filter(array_map('strval', $orderSns), fn ($sn) => $sn !== ''));
        if (self::pushesOn()) {
            $orderSns = array_values(array_unique($orderSns));
        }

        foreach (array_chunk($orderSns, 50) as $chunk) {
            $detailRes = $this->client->shopGet(
                $auth['mode'],
                (int) $auth['partner_id'],
                (string) $auth['partner_key'],
                (string) $auth['access_token'],
                (int) $auth['shop_id'],
                '/api/v2/order/get_order_detail',
                [
                    'order_sn_list' => implode(',', $chunk),
                    'response_optional_fields' => 'buyer_username,recipient_address,total_amount,item_list,pay_time,shipping_carrier,tracking_no,payment_method,currency',
                ]
            );

            if (!($detailRes['ok'] ?? false)) {
                $out['errors'] += count($chunk);
                continue;
            }

            $detailBody = $detailRes['body'] ?? [];
            $detailResp = $detailBody['response'] ?? $detailBody;
            $detailList = $detailResp['order_list'] ?? [];
            if (!is_array($detailList)) $detailList = [];

            $invoiceMap = $this->buyerInvoices($setting, $auth, $chunk);

            $answered = [];
            foreach ($detailList as $o) {
                if (!is_array($o)) continue;

                $orderSn = (string) ($o['order_sn'] ?? '');
                if ($orderSn === '') continue;
                $answered[] = $orderSn;

                $written = self::locked($orderSn, function () use ($setting, $region, $auth, $o, $orderSn, $invoiceMap, &$out) {
                    $this->apply($setting, $region, $auth, $o, $orderSn, $invoiceMap, $out);
                });
                if (! $written) {
                    $out['busy']++;
                }
            }

            $out['missing'] = array_merge($out['missing'], array_values(array_diff($chunk, $answered)));
        }

        return $out;
    }

    private function apply(object $setting, string $region, array $auth, array $o, string $orderSn, array $invoiceMap, array &$out): void
    {
        $existing = ShopeeOrder::query()
            ->where('shopee_setting_id', (int) $setting->id)
            ->where('order_sn', $orderSn)
            ->first();

        if ($existing) {
            $fillExisting = [
                'status'           => (string) ($o['order_status'] ?? $existing->status),
                'order_created_at' => $this->parseTimestamp($o['create_time'] ?? null) ?? $existing->order_created_at,
                'order_updated_at' => $this->parseTimestamp($o['update_time'] ?? null) ?? $existing->order_updated_at,
                'raw'              => $o,
            ];
            if (array_key_exists($orderSn, $invoiceMap)) {
                $fillExisting['buyer_invoice'] = $invoiceMap[$orderSn];
            }
            $existing->fill($fillExisting)->save();
            $out['updated']++;

            try {
                $this->syncOrderProducts($existing, $o);
            } catch (\Throwable $e) {
                Log::warning('Shopee sync: failed to sync items for order ' . $orderSn, ['error' => $e->getMessage()]);
            }

            try {
                (new ShopeeCatalogOrderSync)->sync($existing);
            } catch (\Throwable $e) {
                Log::warning('Shopee sync: catalog sync failed for order ' . $orderSn, ['error' => $e->getMessage()]);
            }

            $this->feesIfReady($auth, $existing);

            return;
        }

        try {
            $newOrder = ShopeeOrder::query()->create([
                'shopee_setting_id' => (int) $setting->id,
                'region'           => $region,
                'order_sn'         => $orderSn,
                'status'           => (string) ($o['order_status'] ?? ''),
                'order_created_at' => $this->parseTimestamp($o['create_time'] ?? null),
                'order_updated_at' => $this->parseTimestamp($o['update_time'] ?? null),
                'raw'              => $o,
                'buyer_invoice'    => $invoiceMap[$orderSn] ?? null,
            ]);
            $out['created']++;

            try {
                $this->syncOrderProducts($newOrder, $o);
            } catch (\Throwable $e) {
                Log::warning('Shopee sync: failed to sync items for order ' . $orderSn, ['error' => $e->getMessage()]);
            }

            try {
                (new ShopeeCatalogOrderSync)->sync($newOrder);
            } catch (\Throwable $e) {
                Log::warning('Shopee sync: failed to sync order ' . $orderSn . ' to catalog', ['error' => $e->getMessage()]);
            }

            $this->feesIfReady($auth, $newOrder);
        } catch (\Throwable $e) {
            $out['errors']++;
            Log::error('Shopee sync: failed to create order ' . $orderSn, ['error' => $e->getMessage()]);
        }
    }

    public function returnBySn(object $setting, array $auth, string $returnSn, array $listed = []): ?string
    {
        $detailRes = $this->client->shopGet(
            $auth['mode'],
            (int) $auth['partner_id'],
            (string) $auth['partner_key'],
            (string) $auth['access_token'],
            (int) $auth['shop_id'],
            '/api/v2/returns/get_return_detail',
            ['return_sn' => $returnSn]
        );

        if (!($detailRes['ok'] ?? false)) {
            return "Failed to fetch detail for return_sn {$returnSn}";
        }

        $detailBody = $detailRes['body'] ?? [];
        $detail = $detailBody['response'] ?? $detailBody;
        $detail = is_array($detail) ? $detail : [];
        $ret = $listed;
        $region = $auth['region'] ?? 'ph';

        $orderSn = (string) ($detail['order_sn'] ?? ($ret['order_sn'] ?? ''));

        $shopeeOrder = null;
        if ($orderSn !== '') {
            $shopeeOrder = ShopeeOrder::query()
                ->where('shopee_setting_id', (int) $setting->id)
                ->where('order_sn', $orderSn)
                ->first();
        }

        try {
            ShopeeReturn::query()->updateOrCreate(
                [
                    'shopee_setting_id' => (int) $setting->id,
                    'return_sn' => $returnSn,
                ],
                [
                    'region'            => $region,
                    'order_sn'          => $orderSn,
                    'shopee_order_id'   => $shopeeOrder?->id,
                    'status'            => (string) ($detail['status'] ?? ($ret['status'] ?? '')),
                    'needs_logistics'          => array_key_exists('needs_logistics', $detail) ? (bool) $detail['needs_logistics'] : null,
                    'return_solution'          => array_key_exists('return_solution', $detail) ? (int) $detail['return_solution'] : null,
                    'reverse_logistics_status' => (string) ($detail['reverse_logistics_status'] ?? ''),
                    'is_arrived_at_warehouse'  => array_key_exists('is_arrived_at_warehouse', $detail) ? (bool) $detail['is_arrived_at_warehouse'] : null,
                    'seller_compensation_status' => (string) ($detail['seller_compensation']['seller_compensation_status'] ?? ''),
                    'reason'            => (string) ($detail['reason'] ?? ($ret['reason'] ?? '')),
                    'reason_text'       => (string) ($detail['text_reason'] ?? ($detail['reason_text'] ?? '')),
                    'refund_amount'     => (float) ($detail['refund_amount'] ?? ($ret['refund_amount'] ?? 0)),
                    'currency'          => (string) ($detail['currency'] ?? ''),
                    'items'             => $detail['item'] ?? ($detail['items'] ?? null),
                    'negotiation'       => $detail['negotiation'] ?? null,
                    'raw'               => $detail,
                    'return_created_at' => $this->parseTimestamp($detail['create_time'] ?? null),
                    'return_updated_at' => $this->parseTimestamp($detail['update_time'] ?? null),
                ]
            );
        } catch (\Throwable $e) {
            Log::error('Shopee sync: failed to save return ' . $returnSn, ['error' => $e->getMessage()]);

            return 'Failed to save return ' . $returnSn;
        }

        return null;
    }

    public function feesIfReady(array $auth, ShopeeOrder $order): void
    {
        $status = strtoupper(trim((string) $order->status));

        $eligibleStatuses = ['SHIPPED', 'TO_CONFIRM_RECEIVE', 'COMPLETED'];
        if (!in_array($status, $eligibleStatuses)) return;

        if (!empty($order->fees)) return;

        try {
            $escrowRes = $this->client->shopGet(
                $auth['mode'],
                (int) $auth['partner_id'],
                (string) $auth['partner_key'],
                (string) $auth['access_token'],
                (int) $auth['shop_id'],
                '/api/v2/payment/get_escrow_detail',
                ['order_sn' => $order->order_sn]
            );

            ShopeeApiLog::safeCreate([
                'pack'            => 'shopee.sync.get_escrow_detail',
                'method'          => 'GET',
                'api_path'        => '/api/v2/payment/get_escrow_detail',
                'auth_required'   => true,
                'request_params'  => ['order_sn' => $order->order_sn],
                'response_status' => (int) ($escrowRes['status'] ?? 0),
                'ok'              => (bool) ($escrowRes['ok'] ?? false),
                'response_body'   => is_array($escrowRes['body'] ?? null) ? $escrowRes['body'] : null,
            ]);

            if (($escrowRes['ok'] ?? false) && is_array($escrowRes['body'] ?? null)) {
                $escrowBody = $escrowRes['body'];
                $escrowData = $escrowBody['response'] ?? $escrowBody;
                if (is_array($escrowData) && !empty($escrowData)) {
                    $order->fees = $escrowData;
                    $order->save();
                }
            }
        } catch (\Throwable $e) {
        }
    }

    public function parseTimestamp($value): ?string
    {
        if ($value === null) return null;

        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            $num = (int) $value;
            if ($num > 0) return date('Y-m-d H:i:s', $num);
            return null;
        }

        $str = trim((string) $value);
        if ($str === '') return null;

        $ts = strtotime($str);
        return $ts === false ? null : date('Y-m-d H:i:s', $ts);
    }

    public static function locked(string $orderSn, callable $write): bool
    {
        if (! self::pushesOn()) {
            $write();

            return true;
        }

        try {
            Cache::lock('shopee_order_write:' . $orderSn, self::LOCK_SECONDS)->block(self::LOCK_WAIT_SECONDS, $write);

            return true;
        } catch (LockTimeoutException) {
            Log::info('Shopee order ' . $orderSn . ' was being written elsewhere; left to that writer');

            return false;
        }
    }

    public static function pushesOn(): bool
    {
        static $on = null;

        return $on ??= \Extensions\shopee\Models\ShopeeSetting::query()
            ->where('enabled', true)
            ->where('apply_order_pushes', true)
            ->exists();
    }

    private function syncOrderProducts(ShopeeOrder $order, array $detail): void
    {
        $itemList = $detail['item_list'] ?? [];
        if (!is_array($itemList)) return;

        foreach ($itemList as $it) {
            if (!is_array($it)) continue;

            $itemId = (string) ($it['item_id'] ?? '');
            $modelId = (string) ($it['model_id'] ?? '');

            $sku = trim((string) ($it['model_sku'] ?? ''));
            if ($sku === '') {
                $sku = trim((string) ($it['item_sku'] ?? ''));
            }

            ShopeeOrderProduct::query()->updateOrCreate(
                [
                    'shopee_order_id' => $order->id,
                    'item_id'         => $itemId !== '' ? $itemId : null,
                    'model_id'        => $modelId !== '' ? $modelId : null,
                ],
                [
                    'sku'       => $sku !== '' ? $sku : null,
                    'name'      => ($v = trim((string) ($it['item_name'] ?? ''))) !== '' ? $v : null,
                    'variation' => ($v = trim((string) ($it['model_name'] ?? ''))) !== '' ? $v : null,
                    'quantity'  => max(1, (int) ($it['model_quantity_purchased'] ?? ($it['quantity'] ?? 1))),
                    'price'     => (float) ($it['model_discounted_price'] ?? ($it['model_original_price'] ?? 0)),
                    'image'     => ($v = trim((string) ($it['image_info']['image_url'] ?? ''))) !== '' ? $v : null,
                    'raw'       => $it,
                ]
            );
        }
    }

    private function buyerInvoices(object $setting, array $auth, array $chunk): array
    {
        $queries = array_map(fn ($sn) => ['order_sn' => (string) $sn], $chunk);

        $res = $this->client->shopPost(
            $auth['mode'],
            (int) $auth['partner_id'],
            (string) $auth['partner_key'],
            (string) $auth['access_token'],
            (int) $auth['shop_id'],
            '/api/v2/order/get_buyer_invoice_info',
            [],
            ['queries' => $queries]
        );

        ShopeeApiLog::safeCreate([
            'pack'            => 'shopee.sync.get_buyer_invoice_info',
            'method'          => 'POST',
            'api_path'        => '/api/v2/order/get_buyer_invoice_info',
            'auth_required'   => true,
            'request_params'  => ['count' => count($queries)],
            'response_status' => (int) ($res['status'] ?? 0),
            'ok'              => (bool) ($res['ok'] ?? false),
            'response_body'   => is_array($res['body'] ?? null) ? $res['body'] : null,
        ]);

        if (!($res['ok'] ?? false)) {
            return [];
        }

        $list = $res['body']['invoice_info_list'] ?? [];
        if (!is_array($list)) return [];

        $map = [];
        foreach ($list as $item) {
            $sn = (string) ($item['order_sn'] ?? '');
            if ($sn === '') continue;

            $err = trim((string) ($item['error'] ?? ''));
            if ($err !== '') {
                $map[$sn] = ['error' => $err];
                continue;
            }

            if (($item['is_requested'] ?? false) !== true) {
                $map[$sn] = ['is_requested' => false];
                continue;
            }

            $d = $item['invoice_detail'] ?? [];
            $ab = is_array($d['address_breakdown'] ?? null) ? $d['address_breakdown'] : [];

            $map[$sn] = [
                'is_requested' => true,
                'type'         => (string) ($item['invoice_type'] ?? ''),
                'name'         => (string) ($d['name'] ?? ''),
                'tin'          => (string) ($d['tax_id'] ?? ''),
                'email'        => (string) ($d['email'] ?? ''),
                'phone'        => (string) ($d['phone_number'] ?? ''),
                'address'      => [
                    'full'             => (string) ($ab['full_address'] ?? ($d['address'] ?? '')),
                    'region'           => (string) ($ab['region'] ?? ''),
                    'state'            => (string) ($ab['state'] ?? ''),
                    'city'             => (string) ($ab['city'] ?? ''),
                    'town'             => (string) ($ab['town'] ?? ''),
                    'barangay'         => (string) ($ab['barangay'] ?? ''),
                    'postcode'         => (string) ($ab['postcode'] ?? ''),
                    'detailed_address' => (string) ($ab['detailed_address'] ?? ''),
                ],
            ];
        }

        return $map;
    }
}
