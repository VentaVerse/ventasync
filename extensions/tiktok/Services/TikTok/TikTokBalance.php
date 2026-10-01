<?php

namespace Extensions\tiktok\Services\TikTok;

use Carbon\Carbon;
use Extensions\tiktok\Models\TikTokSetting;

/**
 * The money at TikTok Shop for one store (the owner, 2026-10-01), from the
 * shop's money movements (Get Withdrawals, /finance/202309/withdrawals):
 * each statement settles into the shop's balance as a SETTLE record, and
 * the seller's bank transfer is a WITHDRAW record that empties it.
 *
 *   Available to withdraw  every SETTLE since the newest WITHDRAW
 *                          (matched Seller Center's ₱23,534.68 exactly)
 *   Settled last 7 days    SETTLE records of the last seven days
 *   Last withdrawal        the newest WITHDRAW, and when
 *
 * Read live, never stored. The caller caches it for a few minutes.
 */
class TikTokBalance
{
    private const PATH = '/finance/202309/withdrawals';

    /** How far back to look for the last withdrawal. */
    private const LOOKBACK_DAYS = 365;

    private const MAX_PAGES = 50;

    public function __construct(private readonly TikTokClient $client)
    {
    }

    /**
     * @return array{ok: bool, error?: string, lead?: array, facts?: list<array>}
     */
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
        $weekAgo = now()->subDays(7)->getTimestamp();
        $lastWeek = array_sum(array_map($amount, array_filter($settles, fn ($r) => $at($r) >= $weekAgo)));

        return [
            'ok' => true,
            'lead' => [
                'label' => 'Available to withdraw',
                'amount' => round($available, 2),
                'note' => $last ? 'Settled since your last withdrawal' : 'No withdrawal in the last year',
            ],
            'facts' => [
                ['label' => 'Settled last 7 days', 'amount' => round($lastWeek, 2)],
                [
                    'label' => 'Last withdrawal',
                    'amount' => $last ? $amount($last) : null,
                    'sub' => $last ? Carbon::createFromTimestamp($at($last))->setTimezone(config('app.timezone'))->format('j M Y') : 'None in a year',
                ],
            ],
        ];
    }
}
