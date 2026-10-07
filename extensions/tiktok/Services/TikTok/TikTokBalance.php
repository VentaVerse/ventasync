<?php

namespace Extensions\tiktok\Services\TikTok;

use Carbon\Carbon;
use Extensions\tiktok\Models\TikTokSetting;

class TikTokBalance
{
    private const PATH = '/finance/202309/withdrawals';

    private const UNSETTLED = '/finance/202507/orders/unsettled';

    private const LOOKBACK_DAYS = 365;

    private const MAX_PAGES = 50;

    public function __construct(private readonly TikTokClient $client)
    {
    }

    public function read(TikTokSetting $store): array
    {
        $creds = TikTokReturnSync::credsFrom($store);
        $expiresAt = $store->mode === 'sandbox' ? $store->sandbox_expires_at : $store->expires_at;
        if (! $creds || ($expiresAt && $expiresAt->isPast())) {
            return ['ok' => false, 'error' => 'This store is not connected to TikTok Shop, or its sign-in has expired.'];
        }

        $records = [];
        $pageToken = '';
        $pages = 0;
        do {
            $params = [
                'types' => 'WITHDRAW,SETTLE,TRANSFER,REVERSE',
                'create_time_ge' => now()->subDays(self::LOOKBACK_DAYS)->getTimestamp(),
                'create_time_lt' => time() + 60,
                'page_size' => 100,
            ];
            if ($pageToken !== '') {
                $params['page_token'] = $pageToken;
            }
            $res = $this->client->get($creds['app_key'], $creds['app_secret'], $creds['token'], self::PATH, $params, $creds['shop_cipher'] ?: null);
            $body = is_array($res['body'] ?? null) ? $res['body'] : [];
            if (! ($res['ok'] ?? false) || (int) ($body['code'] ?? -1) !== 0) {
                return ['ok' => false, 'error' => trim((string) ($body['message'] ?? '')) ?: ('HTTP ' . ($res['status'] ?? 0))];
            }
            foreach ((array) ($body['data']['withdrawals'] ?? []) as $r) {
                if (is_array($r) && strtoupper((string) ($r['status'] ?? '')) === 'SUCCESS') {
                    $records[] = $r;
                }
            }
            $pageToken = (string) ($body['data']['next_page_token'] ?? '');
        } while ($pageToken !== '' && ++$pages < self::MAX_PAGES);

        $type = fn (array $r) => strtoupper((string) ($r['type'] ?? ''));
        $at = fn (array $r) => (int) ($r['create_time'] ?? 0);
        $amount = fn (array $r) => (float) str_replace(',', '', (string) ($r['amount'] ?? 0));

        $withdrawals = array_values(array_filter($records, fn ($r) => $type($r) === 'WITHDRAW'));
        usort($withdrawals, fn ($a, $b) => $at($b) <=> $at($a));
        $last = $withdrawals[0] ?? null;
        $since = $last ? $at($last) : 0;

        $settles = array_filter($records, fn ($r) => $type($r) === 'SETTLE');
        $available = array_sum(array_map($amount, array_filter($settles, fn ($r) => $at($r) > $since)));

        $unsettled = $this->client->get($creds['app_key'], $creds['app_secret'], $creds['token'], self::UNSETTLED,
            ['sort_field' => 'order_create_time', 'page_size' => 50], $creds['shop_cipher'] ?: null);
        $ubody = is_array($unsettled['body'] ?? null) ? $unsettled['body'] : [];
        $pendingOk = ($unsettled['ok'] ?? false) && (int) ($ubody['code'] ?? -1) === 0;
        $pending = $pendingOk ? (float) str_replace(',', '', (string) ($ubody['data']['sum_est_settlement_amount'] ?? 0)) : null;
        $pendingCount = $pendingOk ? (int) ($ubody['data']['total_count'] ?? 0) : null;

        $thisWeek = now()->startOfWeek(Carbon::MONDAY);
        $thisMonth = now()->startOfMonth();
        $settledBetween = fn (Carbon $from, Carbon $to) => round(array_sum(array_map($amount, array_filter($settles,
            fn ($r) => $at($r) >= $from->getTimestamp() && $at($r) < $to->getTimestamp()))), 2);

        return [
            'ok' => true,
            'lead' => [
                'label' => 'Available to withdraw',
                'amount' => round($available, 2),
                'note' => null,
            ],
            'facts' => [
                ['label' => 'To Settle', 'amount' => $pending,
                    'sub' => $pendingCount === null ? 'TikTok did not answer' : ($pendingCount === 1 ? '1 order' : $pendingCount . ' orders')],
                ['label' => 'Total settlement amount · Last week', 'amount' => $settledBetween($thisWeek->copy()->subWeek(), $thisWeek)],
                ['label' => 'Total settlement amount · Last month', 'amount' => $settledBetween($thisMonth->copy()->subMonthNoOverflow(), $thisMonth)],
            ],
        ];
    }
}
