<?php

namespace App\Console\Commands;

use App\Models\ShopeeApiLog;
use App\Models\ShopeeOrder;
use App\Models\ShopeeOrderProduct;
use App\Models\ShopeeReturn;
use App\Models\ShopeeSetting;
use App\Services\Shopee\ShopeeClient;
use App\Services\ShopeeCatalogOrderSync;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ShopeeSyncOrders extends Command
{
    protected $signature = 'shopee:sync-orders
        {--fetch : Fetch new orders (default behaviour)}
        {--update : Update statuses of existing orders only}
        {--both : Fetch new orders AND update existing statuses}
        {--returns : Sync return/refund details from Shopee}';

    protected $description = 'Sync orders from Shopee (fetch new + update statuses)';

    public function handle(ShopeeClient $client): int
    {
        $setting = $this->decryptSetting(ShopeeSetting::query()->first());
        if (!$setting || !$setting->partner_id || !$setting->partner_key || !$setting->access_token || !$setting->shop_id) {
            $this->error('Missing Shopee credentials/token. Configure Shopee settings first.');
            return 1;
        }

        if ($paused = Cache::get('shopee_sync_paused')) {
            $this->warn('Shopee order sync paused due to recent API error (' . $paused . '). Will retry automatically.');
            return 1;
        }

        if ($this->option('returns')) {
            $this->info('--- Syncing Shopee returns ---');
            $this->syncReturns($client, $setting);
            $this->info('Done.');
            return 0;
        }

        $doFetch = $this->option('fetch') || $this->option('both') || !$this->option('update');
        $doUpdate = $this->option('update') || $this->option('both');

        if ($doFetch) {
            $this->info('--- Fetching new Shopee orders ---');
            $this->fetchOrders($client, $setting);
        }

        if (Cache::get('shopee_sync_paused')) {
            return 1;
        }

        if ($doUpdate) {
            $this->info('--- Updating statuses of existing orders ---');
            $this->updateStatuses($client, $setting);
        }

        $this->info('Done.');
        return 0;
    }

    private function fetchOrders(ShopeeClient $client, object $setting): void
    {
        $tz = new \DateTimeZone('Asia/Manila');
        $syncDays = ($setting->sync_last_days ?? null) > 0 ? (int) $setting->sync_last_days : 14;

        $latestUpdated = ShopeeOrder::query()
            ->where('region', $setting->region ?? 'ph')
            ->max('order_updated_at');

        if ($latestUpdated) {
            $dt = (new \DateTime($latestUpdated, $tz))->modify('-2 hours');
            $minDt = (new \DateTime('now', $tz))->modify("-{$syncDays} days");
            if ($dt < $minDt) {
                $dt = $minDt;
            }
            $timeFrom = $dt->getTimestamp();
            $this->info("  Incremental fetch since: " . $dt->format('Y-m-d H:i:s') . " (window: {$syncDays} days)");
        } else {
            $timeFrom = (new \DateTime('now', $tz))->modify("-{$syncDays} days")->getTimestamp();
            $this->info("  Initial backfill: last {$syncDays} days");
        }

        $timeTo = time();

        // Cursor-based pagination to get order_sn list
        $allOrderSns = [];
        $cursor = '';
        $maxOrders = 2000;

        while (true) {
            $extraQuery = [
                'time_range_field' => 'update_time',
                'time_from'        => $timeFrom,
                'time_to'          => $timeTo,
                'page_size'        => 100,
            ];
            if ($cursor !== '') {
                $extraQuery['cursor'] = $cursor;
            }

            $res = $client->shopGet(
                $setting->mode ?? 'live',
                (int) $setting->partner_id,
                (string) $setting->partner_key,
                (string) $setting->access_token,
                (int) $setting->shop_id,
                '/api/v2/order/get_order_list',
                $extraQuery
            );

            ShopeeApiLog::safeCreate([
                'pack'            => 'shopee.sync.get_order_list',
                'method'          => 'GET',
                'api_path'        => '/api/v2/order/get_order_list',
                'auth_required'   => true,
                'request_params'  => $extraQuery,
                'response_status' => (int) ($res['status'] ?? 0),
                'ok'              => (bool) ($res['ok'] ?? false),
                'response_body'   => $res['body'] ?? null,
            ], force: true);

            if (!($res['ok'] ?? false)) {
                $body = $res['body'] ?? [];
                $msg = is_array($body) ? ($body['message'] ?? 'API error') : 'API error';
                $this->error("  API error: {$msg}");
                Cache::put('shopee_sync_paused', $msg, now()->addMinutes(10));
                return;
            }

            $body = $res['body'] ?? [];
            $respData = $body['response'] ?? $body;
            $orderList = $respData['order_list'] ?? [];
            if (!is_array($orderList)) $orderList = [];

            foreach ($orderList as $o) {
                $sn = (string) ($o['order_sn'] ?? '');
                if ($sn !== '') {
                    $allOrderSns[] = $sn;
                }
            }

            $more = (bool) ($respData['more'] ?? false);
            $cursor = (string) ($respData['next_cursor'] ?? '');

            if (!$more || $cursor === '' || count($allOrderSns) >= $maxOrders) {
                break;
            }
        }

        // Fetch details in batches of 50
        $saved = 0;
        $skipped = 0;
        $errors = 0;

        foreach (array_chunk($allOrderSns, 50) as $chunk) {
            $detailRes = $client->shopGet(
                $setting->mode ?? 'live',
                (int) $setting->partner_id,
                (string) $setting->partner_key,
                (string) $setting->access_token,
                (int) $setting->shop_id,
                '/api/v2/order/get_order_detail',
                [
                    'order_sn_list' => implode(',', $chunk),
                    'response_optional_fields' => 'buyer_username,recipient_address,total_amount,item_list,pay_time,shipping_carrier,tracking_no,payment_method,currency',
                ]
            );

            if (!($detailRes['ok'] ?? false)) {
                $errors += count($chunk);
                continue;
            }

            $detailBody = $detailRes['body'] ?? [];
            $detailResp = $detailBody['response'] ?? $detailBody;
            $detailList = $detailResp['order_list'] ?? [];
            if (!is_array($detailList)) $detailList = [];

            foreach ($detailList as $o) {
                if (!is_array($o)) continue;

                $orderSn = (string) ($o['order_sn'] ?? '');
                if ($orderSn === '') continue;

                $existing = ShopeeOrder::query()
                    ->where('region', $setting->region ?? 'ph')
                    ->where('order_sn', $orderSn)
                    ->first();

                if ($existing) {
                    $skipped++;
                    continue;
                }

                try {
                    $newOrder = ShopeeOrder::query()->create([
                        'region'           => $setting->region ?? 'ph',
                        'order_sn'         => $orderSn,
                        'status'           => (string) ($o['order_status'] ?? ''),
                        'order_created_at' => $this->parseTimestamp($o['create_time'] ?? null),
                        'order_updated_at' => $this->parseTimestamp($o['update_time'] ?? null),
                        'raw'              => $o,
                    ]);
                    $saved++;

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
                } catch (\Throwable $e) {
                    $errors++;
                    Log::error('Shopee sync: failed to create order ' . $orderSn, ['error' => $e->getMessage()]);
                }
            }
        }

        $this->info("  Fetched: {$saved} new, {$skipped} skipped" . ($errors > 0 ? ", {$errors} errors" : ''));
    }

    private function updateStatuses(ShopeeClient $client, object $setting): void
    {
        $tz = new \DateTimeZone('Asia/Manila');
        $syncDays = ($setting->sync_last_days ?? null) > 0 ? (int) $setting->sync_last_days : 14;
        $timeFrom = (new \DateTime('now', $tz))->modify("-{$syncDays} days")->getTimestamp();
        $timeTo = time();

        $allOrderSns = [];
        $cursor = '';

        while (true) {
            $extraQuery = [
                'time_range_field' => 'update_time',
                'time_from'        => $timeFrom,
                'time_to'          => $timeTo,
                'page_size'        => 100,
            ];
            if ($cursor !== '') {
                $extraQuery['cursor'] = $cursor;
            }

            $res = $client->shopGet(
                $setting->mode ?? 'live',
                (int) $setting->partner_id,
                (string) $setting->partner_key,
                (string) $setting->access_token,
                (int) $setting->shop_id,
                '/api/v2/order/get_order_list',
                $extraQuery
            );

            if (!($res['ok'] ?? false)) {
                $body = $res['body'] ?? [];
                $msg = is_array($body) ? ($body['message'] ?? 'API error') : 'API error';
                $this->error("  API error: {$msg}");
                Cache::put('shopee_sync_paused', $msg, now()->addMinutes(10));
                return;
            }

            $body = $res['body'] ?? [];
            $respData = $body['response'] ?? $body;
            $orderList = $respData['order_list'] ?? [];
            if (!is_array($orderList)) $orderList = [];

            foreach ($orderList as $o) {
                $sn = (string) ($o['order_sn'] ?? '');
                if ($sn !== '') {
                    $allOrderSns[] = $sn;
                }
            }

            $more = (bool) ($respData['more'] ?? false);
            $cursor = (string) ($respData['next_cursor'] ?? '');

            if (!$more || $cursor === '' || count($allOrderSns) >= 500) break;
        }

        $updated = 0;
        $skipped = 0;

        foreach (array_chunk($allOrderSns, 50) as $chunk) {
            $detailRes = $client->shopGet(
                $setting->mode ?? 'live',
                (int) $setting->partner_id,
                (string) $setting->partner_key,
                (string) $setting->access_token,
                (int) $setting->shop_id,
                '/api/v2/order/get_order_detail',
                [
                    'order_sn_list' => implode(',', $chunk),
                    'response_optional_fields' => 'buyer_username,recipient_address,total_amount,item_list,pay_time,shipping_carrier,tracking_no,payment_method,currency',
                ]
            );

            if (!($detailRes['ok'] ?? false)) continue;

            $detailBody = $detailRes['body'] ?? [];
            $detailResp = $detailBody['response'] ?? $detailBody;
            $detailList = $detailResp['order_list'] ?? [];
            if (!is_array($detailList)) $detailList = [];

            foreach ($detailList as $o) {
                if (!is_array($o)) continue;

                $orderSn = (string) ($o['order_sn'] ?? '');
                if ($orderSn === '') continue;

                $existing = ShopeeOrder::query()
                    ->where('region', $setting->region ?? 'ph')
                    ->where('order_sn', $orderSn)
                    ->first();

                if (!$existing) {
                    $skipped++;
                    continue;
                }

                $existing->fill([
                    'status'           => (string) ($o['order_status'] ?? $existing->status),
                    'order_created_at' => $this->parseTimestamp($o['create_time'] ?? null) ?? $existing->order_created_at,
                    'order_updated_at' => $this->parseTimestamp($o['update_time'] ?? null) ?? $existing->order_updated_at,
                    'raw'              => $o,
                ])->save();
                $updated++;

                try {
                    $this->syncOrderProducts($existing, $o);
                } catch (\Throwable $e) {}

                try {
                    (new ShopeeCatalogOrderSync)->sync($existing);
                } catch (\Throwable $e) {
                    Log::warning('Shopee status update: catalog sync failed for order ' . $orderSn, ['error' => $e->getMessage()]);
                }
            }
        }

        $this->info("  Updated: {$updated}, skipped: {$skipped} (not yet fetched)");
    }

    private function syncReturns(ShopeeClient $client, object $setting): void
    {
        $region = $setting->region ?? 'ph';
        $saved = 0;
        $errors = 0;
        $pageNo = 0;
        $pageSize = 50;

        while (true) {
            $res = $client->shopGet(
                $setting->mode ?? 'live',
                (int) $setting->partner_id,
                (string) $setting->partner_key,
                (string) $setting->access_token,
                (int) $setting->shop_id,
                '/api/v2/returns/get_return_list',
                ['page_no' => $pageNo, 'page_size' => $pageSize]
            );

            ShopeeApiLog::safeCreate([
                'pack'            => 'shopee.sync.get_return_list',
                'method'          => 'GET',
                'api_path'        => '/api/v2/returns/get_return_list',
                'auth_required'   => true,
                'request_params'  => ['page_no' => $pageNo, 'page_size' => $pageSize],
                'response_status' => (int) ($res['status'] ?? 0),
                'ok'              => (bool) ($res['ok'] ?? false),
                'response_body'   => $res['body'] ?? null,
            ], force: true);

            if (!($res['ok'] ?? false)) {
                $body = $res['body'] ?? [];
                $msg = is_array($body) ? ($body['message'] ?? 'API error') : 'API error';
                $this->error("  API error: {$msg}");
                return;
            }

            $body = $res['body'] ?? [];
            $respData = $body['response'] ?? $body;
            $returnList = $respData['return_list'] ?? [];
            if (!is_array($returnList) || empty($returnList)) {
                break;
            }

            foreach ($returnList as $ret) {
                $returnSn = $ret['return_sn'] ?? null;
                if (!$returnSn) continue;

                $detailRes = $client->shopGet(
                    $setting->mode ?? 'live',
                    (int) $setting->partner_id,
                    (string) $setting->partner_key,
                    (string) $setting->access_token,
                    (int) $setting->shop_id,
                    '/api/v2/returns/get_return_detail',
                    ['return_sn' => $returnSn]
                );

                if (!($detailRes['ok'] ?? false)) {
                    $errors++;
                    $this->warn("  Failed to fetch detail for return_sn {$returnSn}");
                    continue;
                }

                $detailBody = $detailRes['body'] ?? [];
                $detail = $detailBody['response'] ?? $detailBody;

                $orderSn = (string) ($detail['order_sn'] ?? ($ret['order_sn'] ?? ''));

                $shopeeOrder = null;
                if ($orderSn !== '') {
                    $shopeeOrder = ShopeeOrder::query()
                        ->where('region', $region)
                        ->where('order_sn', $orderSn)
                        ->first();
                }

                try {
                    ShopeeReturn::query()->updateOrCreate(
                        [
                            'region'    => $region,
                            'return_sn' => $returnSn,
                        ],
                        [
                            'order_sn'          => $orderSn,
                            'shopee_order_id'   => $shopeeOrder?->id,
                            'status'            => (string) ($detail['status'] ?? ($ret['status'] ?? '')),
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
                    $saved++;
                } catch (\Throwable $e) {
                    $errors++;
                    Log::error('Shopee sync: failed to save return ' . $returnSn, ['error' => $e->getMessage()]);
                }
            }

            $more = (bool) ($respData['more'] ?? false);
            if (!$more) {
                break;
            }

            $pageNo++;
            if ($pageNo > 50) break;
        }

        $this->info("  Synced: {$saved} return(s)" . ($errors > 0 ? ", {$errors} error(s)" : ''));
    }

    private function syncOrderProducts(ShopeeOrder $order, array $detail): void
    {
        $itemList = $detail['item_list'] ?? [];
        if (!is_array($itemList)) return;

        foreach ($itemList as $it) {
            if (!is_array($it)) continue;

            $itemId = (string) ($it['item_id'] ?? '');
            $modelId = (string) ($it['model_id'] ?? '');

            ShopeeOrderProduct::query()->updateOrCreate(
                [
                    'shopee_order_id' => $order->id,
                    'item_id'         => $itemId !== '' ? $itemId : null,
                    'model_id'        => $modelId !== '' ? $modelId : null,
                ],
                [
                    'sku'       => ($v = trim((string) ($it['item_sku'] ?? ($it['model_sku'] ?? '')))) !== '' ? $v : null,
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

    private function parseTimestamp($value): ?string
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

    private function decryptSetting(?ShopeeSetting $setting): ?object
    {
        if (!$setting) return null;

        $s = (object) $setting->toArray();

        foreach (['partner_key', 'access_token', 'refresh_token'] as $k) {
            if (!empty($s->$k)) {
                try {
                    $s->$k = decrypt($s->$k);
                } catch (\Throwable $e) {
                    $s->$k = $s->$k;
                }
            }
        }

        return $s;
    }
}
