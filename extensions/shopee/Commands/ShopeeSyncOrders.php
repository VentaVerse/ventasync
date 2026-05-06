<?php

namespace Extensions\shopee\Commands;

use Extensions\shopee\Models\ShopeeApiLog;
use Extensions\shopee\Models\ShopeeOrder;
use Extensions\shopee\Models\ShopeeOrderProduct;
use Extensions\shopee\Models\ShopeeReturn;
use Extensions\shopee\Models\ShopeeSetting;
use Extensions\shopee\Services\Shopee\ShopeeClient;
use Extensions\shopee\Services\ShopeeCatalogOrderSync;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ShopeeSyncOrders extends Command
{
    protected $signature = 'shopee:sync-orders
        {--both : Sync orders and returns (default behavior)}
        {--returns : Sync return/refund details only}
        {--no-returns : Skip return sync}';

    protected $description = 'Sync orders from Shopee (create new + update existing + returns)';

    public function handle(ShopeeClient $client): int
    {
        $setting = ShopeeSetting::query()->first()?->decrypted();
        if (!$setting || !$setting->partner_id || !$setting->partner_key || !$setting->access_token || !$setting->shop_id) {
            $this->error('Missing Shopee credentials/token. Configure Shopee settings first.');
            return 1;
        }

        if ($paused = Cache::get('shopee_sync_paused')) {
            $this->warn('Shopee order sync paused due to recent API error (' . $paused . '). Will retry automatically.');
            return 1;
        }

        $returnsOnly = $this->option('returns');
        $skipReturns = $this->option('no-returns');

        if (!$returnsOnly) {
            $this->info('--- Syncing Shopee orders ---');
            $this->syncOrders($client, $setting);
            ShopeeSetting::query()->update(['last_order_sync_at' => now()]);
        }

        if (Cache::get('shopee_sync_paused')) {
            return 1;
        }

        if (!$returnsOnly) {
            $this->info('--- Backfilling missing escrow fees ---');
            $this->backfillMissingFees($client, $setting);

            $this->info('--- Syncing Shopee payout statuses ---');
            $this->syncPayoutStatuses($client, $setting);
        }

        if (!$skipReturns) {
            $this->info('--- Syncing Shopee returns ---');
            $this->syncReturns($client, $setting);
            ShopeeSetting::query()->update(['last_return_sync_at' => now()]);
        }

        $this->info('Done.');
        return 0;
    }

    private function syncOrders(ShopeeClient $client, object $setting): void
    {
        $tz = new \DateTimeZone('Asia/Manila');
        $syncDays = ($setting->sync_last_days ?? null) > 0 ? (int) $setting->sync_last_days : 14;
        $region = $setting->region ?? 'ph';

        $latestUpdated = ShopeeOrder::query()
            ->where('region', $region)
            ->max('order_updated_at');

        if ($latestUpdated) {
            $dt = (new \DateTime($latestUpdated, $tz))->modify('-2 hours');
            $minDt = (new \DateTime('now', $tz))->modify("-{$syncDays} days");
            if ($dt < $minDt) {
                $dt = $minDt;
            }
            $timeFrom = $dt->getTimestamp();
            $this->info("  Incremental sync since: " . $dt->format('Y-m-d H:i:s') . " (window: {$syncDays} days)");
        } else {
            $timeFrom = (new \DateTime('now', $tz))->modify("-{$syncDays} days")->getTimestamp();
            $this->info("  Initial backfill: last {$syncDays} days");
        }

        $timeTo = time();

        // Cursor-based pagination with sliding 15-day windows
        // (Shopee API max window is 15 days per request)
        $allOrderSns = [];
        $maxOrders = 5000;
        $windowSize = 15 * 86400; // 15 days in seconds

        $windowFrom = $timeFrom;
        while ($windowFrom < $timeTo && count($allOrderSns) < $maxOrders) {
            $windowTo = min($windowFrom + $windowSize, $timeTo);
            $cursor = '';

            while (true) {
                $extraQuery = [
                    'time_range_field' => 'update_time',
                    'time_from'        => $windowFrom,
                    'time_to'          => $windowTo,
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
                ]);

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

            $windowFrom = $windowTo;
        }

        // Fetch details in batches of 50, then create or update each order
        $created = 0;
        $updated = 0;
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

            // Buyer invoice info — batched once per chunk, results applied below.
            $invoiceMap = $this->fetchBuyerInvoiceForChunk($client, $setting, $chunk);

            foreach ($detailList as $o) {
                if (!is_array($o)) continue;

                $orderSn = (string) ($o['order_sn'] ?? '');
                if ($orderSn === '') continue;

                $existing = ShopeeOrder::query()
                    ->where('region', $region)
                    ->where('order_sn', $orderSn)
                    ->first();

                if ($existing) {
                    // --- Update existing order ---
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
                    $updated++;

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

                    $this->fetchFeesIfReady($client, $setting, $existing);
                } else {
                    // --- Create new order ---
                    try {
                        $newOrder = ShopeeOrder::query()->create([
                            'region'           => $region,
                            'order_sn'         => $orderSn,
                            'status'           => (string) ($o['order_status'] ?? ''),
                            'order_created_at' => $this->parseTimestamp($o['create_time'] ?? null),
                            'order_updated_at' => $this->parseTimestamp($o['update_time'] ?? null),
                            'raw'              => $o,
                            'buyer_invoice'    => $invoiceMap[$orderSn] ?? null,
                        ]);
                        $created++;

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

                        $this->fetchFeesIfReady($client, $setting, $newOrder);
                    } catch (\Throwable $e) {
                        $errors++;
                        Log::error('Shopee sync: failed to create order ' . $orderSn, ['error' => $e->getMessage()]);
                    }
                }
            }
        }

        $this->info("  Synced: {$created} new, {$updated} updated" . ($errors > 0 ? ", {$errors} errors" : ''));
    }

    private function syncReturns(ShopeeClient $client, object $setting): void
    {
        $region = $setting->region ?? 'ph';
        $saved = 0;
        $errors = 0;
        $pageNo = 0;
        $pageSize = 50;

        $syncDays = ($setting->sync_last_days_returns ?? null) > 0 ? (int) $setting->sync_last_days_returns : 14;
        $tz = new \DateTimeZone('Asia/Manila');
        $createTimeFrom = (new \DateTime('now', $tz))->modify("-{$syncDays} days")->getTimestamp();
        $this->info("  Returns window: last {$syncDays} days");

        while (true) {
            $queryParams = [
                'page_no' => $pageNo,
                'page_size' => $pageSize,
                'create_time_from' => $createTimeFrom,
                'create_time_to' => time(),
            ];

            $res = $client->shopGet(
                $setting->mode ?? 'live',
                (int) $setting->partner_id,
                (string) $setting->partner_key,
                (string) $setting->access_token,
                (int) $setting->shop_id,
                '/api/v2/returns/get_return_list',
                $queryParams
            );

            ShopeeApiLog::safeCreate([
                'pack'            => 'shopee.sync.get_return_list',
                'method'          => 'GET',
                'api_path'        => '/api/v2/returns/get_return_list',
                'auth_required'   => true,
                'request_params'  => $queryParams,
                'response_status' => (int) ($res['status'] ?? 0),
                'ok'              => (bool) ($res['ok'] ?? false),
                'response_body'   => $res['body'] ?? null,
            ]);

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

    /**
     * Fetch escrow/fee details for completed orders.
     * Only calls the API if: order is COMPLETED and fees not already stored.
     */
    private function fetchFeesIfReady(ShopeeClient $client, object $setting, ShopeeOrder $order): void
    {
        $status = strtoupper(trim((string) $order->status));

        // Fetch escrow for any order past payment (SHIPPED, TO_CONFIRM_RECEIVE, COMPLETED)
        $eligibleStatuses = ['SHIPPED', 'TO_CONFIRM_RECEIVE', 'COMPLETED'];
        if (!in_array($status, $eligibleStatuses)) return;

        // Skip if already has fee data
        if (!empty($order->fees)) return;

        try {
            $escrowRes = $client->shopGet(
                $setting->mode ?? 'live',
                (int) $setting->partner_id,
                (string) $setting->partner_key,
                (string) $setting->access_token,
                (int) $setting->shop_id,
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
                    $orderIncome = $escrowData['order_income'] ?? null;
                    $order->fees = is_array($orderIncome) && !empty($orderIncome)
                        ? $escrowData
                        : $escrowData;
                    $order->save();
                }
            }
        } catch (\Throwable $e) {
            // Best-effort — don't break the sync
        }
    }

    /**
     * Fetch escrow fees for shipped/completed orders that don't have fee data yet.
     */
    private function backfillMissingFees(ShopeeClient $client, object $setting): void
    {
        $region = $setting->region ?? 'ph';

        $orders = ShopeeOrder::query()
            ->where('region', $region)
            ->whereIn('status', ['SHIPPED', 'TO_CONFIRM_RECEIVE', 'COMPLETED'])
            ->whereNull('fees')
            ->get();

        if ($orders->isEmpty()) {
            $this->info('  No orders need fee backfill.');
            return;
        }

        $filled = 0;
        foreach ($orders as $order) {
            $this->fetchFeesIfReady($client, $setting, $order);
            if (!empty($order->fresh()->fees)) {
                $filled++;
            }
            usleep(200000); // 200ms rate limit
        }

        $this->info("  Backfilled fees for {$filled}/{$orders->count()} orders.");
    }

    /**
     * Fetch wallet transactions from Shopee and update payout_status/paid_at
     * for COMPLETED orders that haven't been marked as Paid yet.
     */
    private function syncPayoutStatuses(ShopeeClient $client, object $setting): void
    {
        $region = $setting->region ?? 'ph';

        // Find COMPLETED orders without payout_status = 'Paid'
        $pendingOrders = ShopeeOrder::query()
            ->where('region', $region)
            ->where('status', 'COMPLETED')
            ->where(function ($q) {
                $q->whereNull('payout_status')
                  ->orWhere('payout_status', '!=', 'Paid');
            })
            ->pluck('order_sn')
            ->all();

        if (empty($pendingOrders)) {
            $this->info('  No completed orders awaiting payout check.');
            return;
        }

        // Fetch wallet transactions (14-day API window, paginated)
        $paidMap = []; // order_sn => paid_at
        $pageNo = 1;

        try {
            do {
                $res = $client->shopGet(
                    $setting->mode ?? 'live',
                    (int) $setting->partner_id,
                    (string) $setting->partner_key,
                    (string) $setting->access_token,
                    (int) $setting->shop_id,
                    '/api/v2/payment/get_wallet_transaction_list',
                    [
                        'page_no'          => $pageNo,
                        'page_size'        => 50,
                        'create_time_from' => strtotime('-14 days'),
                        'create_time_to'   => time(),
                    ]
                );

                if (!($res['ok'] ?? false)) break;

                $response = $res['body']['response'] ?? [];
                $transactions = $response['transaction_list'] ?? [];
                $hasMore = $response['more'] ?? false;

                foreach ($transactions as $trx) {
                    if (($trx['transaction_type'] ?? '') === 'ESCROW_VERIFIED_ADD'
                        && ($trx['status'] ?? '') === 'COMPLETED'
                        && !empty($trx['order_sn'])) {
                        $paidMap[$trx['order_sn']] = isset($trx['create_time'])
                            ? date('Y-m-d H:i:s', $trx['create_time'])
                            : now()->toDateTimeString();
                    }
                }

                $pageNo++;
            } while ($hasMore && $pageNo <= 20);
        } catch (\Throwable $e) {
            $this->warn('  Wallet API error: ' . $e->getMessage());
            return;
        }

        // Update orders that appear in the wallet payout list
        $paidCount = 0;
        $releasingCount = 0;
        foreach ($pendingOrders as $orderSn) {
            if (isset($paidMap[$orderSn])) {
                ShopeeOrder::query()
                    ->where('region', $region)
                    ->where('order_sn', $orderSn)
                    ->update([
                        'payout_status' => 'Paid',
                        'paid_at' => $paidMap[$orderSn],
                    ]);
                $paidCount++;
            } else {
                // Mark as Releasing (completed but not yet paid out)
                ShopeeOrder::query()
                    ->where('region', $region)
                    ->where('order_sn', $orderSn)
                    ->whereNull('payout_status')
                    ->update(['payout_status' => 'Releasing']);
                $releasingCount++;
            }
        }

        $this->info("  Payout statuses: {$paidCount} Paid, {$releasingCount} Releasing");
    }

    private function syncOrderProducts(ShopeeOrder $order, array $detail): void
    {
        $itemList = $detail['item_list'] ?? [];
        if (!is_array($itemList)) return;

        foreach ($itemList as $it) {
            if (!is_array($it)) continue;

            $itemId = (string) ($it['item_id'] ?? '');
            $modelId = (string) ($it['model_id'] ?? '');

            // Prefer model_sku (variant) over item_sku (parent) so option-value stock resolves correctly
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

    /**
     * Fetch buyer-invoice info for a chunk of order SNs in a single API call
     * and return a map of order_sn => normalized invoice payload.
     *
     * Persisted shape (so the UI doesn't have to care which marketplace it came from):
     *   - { is_requested: true,  type, name, tin, email, phone, address: {...} }  (buyer requested)
     *   - { is_requested: false }                                                  (buyer declined)
     *   - { error: "...message..." }                                               (Shopee couldn't classify)
     */
    private function fetchBuyerInvoiceForChunk(ShopeeClient $client, object $setting, array $chunk): array
    {
        $queries = array_map(fn ($sn) => ['order_sn' => (string) $sn], $chunk);

        $res = $client->shopPost(
            $setting->mode ?? 'live',
            (int) $setting->partner_id,
            (string) $setting->partner_key,
            (string) $setting->access_token,
            (int) $setting->shop_id,
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
