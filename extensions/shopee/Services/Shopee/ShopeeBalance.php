<?php

namespace Extensions\shopee\Services\Shopee;

use Carbon\Carbon;
use Extensions\shopee\Models\ShopeeSetting;

class ShopeeBalance
{
    private const WALLET = '/api/v2/payment/get_wallet_transaction_list';

    private const WINDOW_SECONDS = 14 * 86400;

    private const PAGE_SIZE = 100;

    private const MAX_PAGES = 30;

    private const QUIET_WINDOWS = 8;

    private const RELEASE_TYPES = ['ESCROW_VERIFIED_ADD', 'ESCROW_VERIFIED_MINUS'];

    public function __construct(private readonly ShopeeClient $client)
    {
    }

    public function read(ShopeeSetting $store): array
    {
        $auth = ShopeeSetting::activeAuth($store->decrypted());
        if (! $auth['complete']) {
            return ['ok' => false, 'error' => 'This store is not connected to Shopee.'];
        }

        $weekStart = now()->startOfWeek(Carbon::MONDAY);
        $monthStart = now()->startOfMonth();
        $from = $weekStart->lt($monthStart) ? $weekStart : $monthStart;

        $lines = [];
        for ($windowFrom = $from->getTimestamp(); $windowFrom < time(); $windowFrom += self::WINDOW_SECONDS) {
            $windowTo = min($windowFrom + self::WINDOW_SECONDS - 1, time());
            $read = $this->wallet($auth, $windowFrom, $windowTo, self::MAX_PAGES);
            if (is_string($read)) {
                return ['ok' => false, 'error' => $read];
            }
            array_push($lines, ...$read);
        }

        $newest = $this->newest($lines);
        for ($i = 0, $to = $from->getTimestamp() - 1; $newest === null && $i < self::QUIET_WINDOWS; $i++, $to -= self::WINDOW_SECONDS) {
            $read = $this->wallet($auth, $to - self::WINDOW_SECONDS + 1, $to, 1);
            if (is_string($read)) {
                return ['ok' => false, 'error' => $read];
            }
            $newest = $this->newest($read);
        }

        $overview = $this->client->shopGet(
            $auth['mode'], (int) $auth['partner_id'], (string) $auth['partner_key'],
            (string) $auth['access_token'], (int) $auth['shop_id'], '/api/v2/payment/get_income_overview'
        );
        $why = $this->refusal($overview);
        if ($why !== null) {
            return ['ok' => false, 'error' => $why];
        }
        $pending = $overview['body']['response']['total_income']['pending_amount'] ?? null;
        $releasedTotal = $overview['body']['response']['total_income']['released_amount'] ?? null;

        $released = fn (Carbon $since) => array_sum(array_map(
            fn ($l) => (float) ($l['amount'] ?? 0),
            array_filter($lines, fn ($l) => in_array($l['transaction_type'] ?? '', self::RELEASE_TYPES, true)
                && (int) ($l['create_time'] ?? 0) >= $since->getTimestamp())
        ));

        return [
            'ok' => true,
            'lead' => [
                'label' => 'Seller Balance',
                'amount' => $newest !== null ? (float) ($newest['current_balance'] ?? 0) : null,
                'note' => $newest !== null ? null : 'No wallet activity in the last four months',
            ],
            'facts' => [
                ['label' => 'Pending · Total', 'amount' => is_numeric($pending) ? (float) $pending : null],
                ['label' => 'Released · This Week', 'amount' => round($released($weekStart), 2)],
                ['label' => 'Released · This Month', 'amount' => round($released($monthStart), 2)],
                ['label' => 'Released · Total', 'amount' => is_numeric($releasedTotal) ? (float) $releasedTotal : null],
            ],
        ];
    }

    private function wallet(array $auth, int $from, int $to, int $maxPages): array|string
    {
        $lines = [];
        $page = 1;
        do {
            $res = $this->client->shopGet(
                $auth['mode'], (int) $auth['partner_id'], (string) $auth['partner_key'],
                (string) $auth['access_token'], (int) $auth['shop_id'], self::WALLET,
                ['page_no' => $page, 'page_size' => self::PAGE_SIZE, 'create_time_from' => $from, 'create_time_to' => $to]
            );
            $why = $this->refusal($res);
            if ($why !== null) {
                return $why;
            }
            $response = $res['body']['response'] ?? [];
            foreach ((array) ($response['transaction_list'] ?? []) as $line) {
                if (is_array($line)) {
                    $lines[] = $line;
                }
            }
            $page++;
        } while (($response['more'] ?? false) && $page <= $maxPages);

        return $lines;
    }

    private function newest(array $lines): ?array
    {
        $newest = null;
        foreach ($lines as $line) {
            if ($newest === null || (int) ($line['create_time'] ?? 0) > (int) ($newest['create_time'] ?? 0)) {
                $newest = $line;
            }
        }

        return $newest;
    }

    private function refusal(array $res): ?string
    {
        $body = is_array($res['body'] ?? null) ? $res['body'] : [];
        if (($res['ok'] ?? false) && (string) ($body['error'] ?? '') === '') {
            return null;
        }

        return trim((string) ($body['message'] ?? $body['error'] ?? '')) ?: ('HTTP ' . ($res['status'] ?? 0));
    }
}
