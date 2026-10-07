<?php

namespace Extensions\shopee\Commands;

use Extensions\shopee\Models\ShopeeApiLog;
use Extensions\shopee\Models\ShopeeOrder;
use Extensions\shopee\Models\ShopeeSetting;
use Extensions\shopee\Services\Shopee\ShopeeClient;
use Extensions\shopee\Services\Shopee\ShopeeOrderIngest;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ShopeeSyncOrders extends Command implements \App\Integrations\Contracts\SteppableAutomation
{
    use \App\Console\Concerns\StepsOutsideArtisan;

    protected $signature = 'shopee:sync-orders
        {--both : Sync orders and returns (default behavior)}
        {--returns : Sync return/refund details only}
        {--no-returns : Skip return sync}
        {--days= : Override sync_last_days for this run (passed by the universal scheduler)}
        {--days-returns= : Override sync_last_days_returns for this run}';

    protected $description = 'Sync orders from Shopee (create new + update existing + returns)';

    public function handle(ShopeeClient $client): int
    {
        $stores = ShopeeSetting::query()->where('enabled', true)->orderBy('id')->get();
        if ($stores->isEmpty()) {
            $this->error('No enabled Shopee store. Configure Shopee settings first.');
            return 1;
        }

        $worst = 0;
        foreach ($stores as $store) {
            $label = $store->store_name ?: ('#' . $store->id);
            $this->info("=== Shopee store: {$label} ===");
            try {
                $worst = max($worst, $this->syncStore($client, $store));
            } catch (\Throwable $e) {
                $this->error("Store {$label} failed: " . $e->getMessage());
                Log::error('Shopee sync: store failed', ['store' => $store->id, 'error' => $e->getMessage()]);
                $worst = 1;
            }
        }

        $this->info('Done.');
        return $worst;
    }

    private function syncStore(ShopeeClient $client, ShopeeSetting $store): int
    {
        $setting = $store->decrypted();
        $auth = ShopeeSetting::activeAuth($setting);
        if (!$auth['complete']) {
            $modeLabel = $auth['mode'] === 'sandbox' ? 'Sandbox' : 'Production';
            $this->error("Missing Shopee {$modeLabel} credentials/token for this store.");
            return 1;
        }

        if ($paused = Cache::get('shopee_sync_paused:' . $store->id)) {
            $this->warn('This store is paused due to a recent API error (' . $paused . '). Will retry automatically.');
            return 1;
        }

        $returnsOnly = $this->option('returns');
        $skipReturns = $this->option('no-returns');

        if (!$returnsOnly) {
            $this->info('--- Syncing Shopee orders ---');
            $this->syncOrders($client, $setting, $auth);
            $store->forceFill(['last_order_sync_at' => now()])->save();
        }

        if (Cache::get('shopee_sync_paused:' . $store->id)) {
            return 1;
        }

        if (!$returnsOnly) {
            $this->info('--- Backfilling missing escrow fees ---');
            $this->backfillMissingFees($client, $setting, $auth);

        }

        if (!$skipReturns) {
            $this->info('--- Syncing Shopee returns ---');
            $this->syncReturns($client, $setting, $auth);
            $store->forceFill(['last_return_sync_at' => now()])->save();
        }

        return 0;
    }

    private function syncOrders(ShopeeClient $client, object $setting, array $auth): void
    {
        $tz = new \DateTimeZone('Asia/Manila');
        $cliDays = $this->option('days');
        $syncDays = $cliDays !== null && $cliDays !== ''
            ? max(1, (int) $cliDays)
            : (($setting->sync_last_days ?? null) > 0 ? (int) $setting->sync_last_days : 14);

        $latestUpdated = ShopeeOrder::query()
            ->where('shopee_setting_id', (int) $setting->id)
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

        $allOrderSns = [];
        $maxOrders = 5000;
        $windowSize = 15 * 86400;

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
                    $auth['mode'],
                    (int) $auth['partner_id'],
                    (string) $auth['partner_key'],
                    (string) $auth['access_token'],
                    (int) $auth['shop_id'],
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
                    $msg = \App\Support\MarketplaceAnswer::plain('Shopee', ['ok' => false, 'body' => is_array($body) ? $body : []]);
                    $this->error("  API error: {$msg}");
                    Cache::put('shopee_sync_paused:' . $setting->id, $msg, now()->addMinutes(10));
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

        $r = app(ShopeeOrderIngest::class)->orders($setting, $auth, $allOrderSns);
        $this->info("  Synced: {$r['created']} new, {$r['updated']} updated"
            . ($r['errors'] > 0 ? ", {$r['errors']} errors" : '')
            . ($r['busy'] > 0 ? ", {$r['busy']} being written by a push" : ''));
    }

    private function syncReturns(ShopeeClient $client, object $setting, array $auth): void
    {
        $saved = 0;
        $errors = 0;
        $pageNo = 0;
        $pageSize = 50;

        $cliDaysReturns = $this->option('days-returns');
        $syncDays = $cliDaysReturns !== null && $cliDaysReturns !== ''
            ? max(1, (int) $cliDaysReturns)
            : (($setting->sync_last_days_returns ?? null) > 0 ? (int) $setting->sync_last_days_returns : 14);
        $tz = new \DateTimeZone('Asia/Manila');
        $rangeStart = (new \DateTime('now', $tz))->modify("-{$syncDays} days")->getTimestamp();
        $rangeEnd = time();
        $windowSecs = 15 * 86400;
        $this->info("  Returns window: last {$syncDays} days (in <=15-day slices)");

        for ($winFrom = $rangeStart; $winFrom < $rangeEnd; $winFrom = $winTo) {
        $winTo = min($winFrom + $windowSecs, $rangeEnd);
        $pageNo = 0;
        while (true) {
            $queryParams = [
                'page_no' => $pageNo,
                'page_size' => $pageSize,
                'create_time_from' => $winFrom,
                'create_time_to' => $winTo,
            ];

            $res = $client->shopGet(
                $auth['mode'],
                (int) $auth['partner_id'],
                (string) $auth['partner_key'],
                (string) $auth['access_token'],
                (int) $auth['shop_id'],
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
                $msg = \App\Support\MarketplaceAnswer::plain('Shopee', ['ok' => false, 'body' => is_array($body) ? $body : []]);
                $this->error("  API error: {$msg}");
                return;
            }

            $body = $res['body'] ?? [];
            $respData = $body['response'] ?? $body;
            $returnList = $respData['return'] ?? $respData['return_list'] ?? [];
            if (!is_array($returnList) || empty($returnList)) {
                break;
            }

            foreach ($returnList as $ret) {
                $returnSn = $ret['return_sn'] ?? null;
                if (!$returnSn) continue;

                $error = app(ShopeeOrderIngest::class)->returnBySn($setting, $auth, (string) $returnSn, is_array($ret) ? $ret : []);
                if ($error !== null) {
                    $errors++;
                    $this->warn('  ' . $error);
                    continue;
                }
                $saved++;
            }

            $more = (bool) ($respData['more'] ?? false);
            if (!$more) {
                break;
            }

            $pageNo++;
            if ($pageNo > 50) break;
        }
        }

        $this->info("  Synced: {$saved} return(s)" . ($errors > 0 ? ", {$errors} error(s)" : ''));
    }

    private function backfillMissingFees(ShopeeClient $client, object $setting, array $auth): void
    {
        $region = $auth['region'] ?? 'ph';

        $orders = ShopeeOrder::query()
            ->where('shopee_setting_id', (int) $setting->id)
            ->whereIn('status', ['SHIPPED', 'TO_CONFIRM_RECEIVE', 'COMPLETED'])
            ->whereNull('fees')
            ->get();

        if ($orders->isEmpty()) {
            $this->info('  No orders need fee backfill.');
            return;
        }

        $filled = 0;
        $ingest = app(ShopeeOrderIngest::class);
        foreach ($orders as $order) {
            $ingest->feesIfReady($auth, $order);
            if (!empty($order->fresh()->fees)) {
                $filled++;
            }
            usleep(200000);
        }

        $this->info("  Backfilled fees for {$filled}/{$orders->count()} orders.");
    }

    public function automationUnits(\App\Models\ScheduledJob $job): array
    {
        $args = $job->commandArguments();
        $returnsOnly = ! empty($args['--returns']);
        $skipReturns = ! empty($args['--no-returns']);
        $units = [];
        foreach (ShopeeSetting::query()->where('enabled', true)->orderBy('id')->get() as $store) {
            foreach (array_values(array_filter(['orders', 'fees', 'returns'], fn ($p) => $returnsOnly ? $p === 'returns' : ($p !== 'returns' || ! $skipReturns))) as $phase) {
                $units[] = $store->id . ':' . $phase;
            }
        }

        return ['label' => 'steps', 'units' => $units];
    }

    public function automationChunk(): int
    {
        return 1;
    }

    public function automationStep(\App\Models\ScheduledJob $job, array $units): array
    {
        $this->prepareForStep($job->commandArguments());
        $client = app(ShopeeClient::class);
        $ok = 0;
        $failed = 0;
        $notes = [];
        foreach ($units as $unit) {
            [$sid, $phase] = array_pad(explode(':', (string) $unit, 2), 2, '');
            $store = ShopeeSetting::query()->find((int) $sid);
            if (! $store) {
                $failed++;
                continue;
            }
            $setting = $store->decrypted();
            $auth = ShopeeSetting::activeAuth($setting);
            if (! $auth['complete']) {
                return ['error' => 'Store ' . ($store->store_name ?: '#' . $store->id) . ' is not connected to Shopee.'];
            }
            if ($paused = Cache::get('shopee_sync_paused:' . $store->id)) {
                return ['error' => 'This store is paused after a recent Shopee error: ' . $paused . '. It retries on its own in a few minutes.'];
            }
            try {
                match ($phase) {
                    'orders' => (function () use ($client, $setting, $auth, $store) { $this->syncOrders($client, $setting, $auth); $store->forceFill(['last_order_sync_at' => now()])->save(); })(),
                    'fees' => $this->backfillMissingFees($client, $setting, $auth),
                    'returns' => (function () use ($client, $setting, $auth, $store) { $this->syncReturns($client, $setting, $auth); $store->forceFill(['last_return_sync_at' => now()])->save(); })(),
                    default => null,
                };
                if ($paused = Cache::get('shopee_sync_paused:' . $store->id)) {
                    return ['error' => 'Shopee refused during ' . $phase . ': ' . $paused];
                }
                $ok++;
            } catch (\Throwable $e) {
                $failed++;
                $notes[] = $phase . ': ' . \App\Support\TransportError::plain($e, 'Shopee');
            }
        }

        return ['ok' => $ok, 'failed' => $failed, 'note' => implode('; ', array_slice($notes, 0, 2))];
    }
}
