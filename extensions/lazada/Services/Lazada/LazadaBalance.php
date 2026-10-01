<?php

namespace Extensions\lazada\Services\Lazada;

use Carbon\Carbon;
use Extensions\lazada\Models\LazadaSetting;

/**
 * The money at Lazada for one store (the owner, 2026-10-01).
 *
 * Lazada's Open Platform has no balance call: the account-transactions
 * query answers empty and no balance path exists (checked 1 Oct 2026). So
 * this shows what Lazada does publish, each matched to Seller Center that
 * day, and says plainly that the balance itself lives in Seller Center:
 *
 *   Latest statement     the newest daily statement's payout
 *   Ongoing statement    today's finance lines, not yet in a statement
 *                        (matched "Ongoing" ₱148.39 exactly)
 *   Paid out this month  payouts of the statements issued this month
 *   Statements unpaid    statements Lazada has issued but not paid
 *
 * Read live, never stored. The caller caches it for a few minutes.
 */
class LazadaBalance
{
    private const STATEMENTS = '/finance/payout/status/get';

    private const LINES = '/finance/transaction/details/get';

    private const PAGE_SIZE = 500;

    private const MAX_PAGES = 20;

    public function __construct(private readonly LazadaClient $client)
    {
    }

    /**
     * @return array{ok: bool, error?: string, lead?: array, facts?: list<array>, note?: string}
     */
    public function read(LazadaSetting $store): array
    {
        $setting = $store->decrypted();
        $creds = LazadaSetting::activeCredentials($setting);
        if (empty($creds['complete']) || ! $setting->region) {
            return ['ok' => false, 'error' => 'This store is not connected to Lazada.'];
        }

        $body = $this->call($setting, $creds, self::STATEMENTS, ['created_after' => now()->subMonthNoOverflow()->startOfMonth()->toDateString()]);
        if (is_string($body)) {
            return ['ok' => false, 'error' => $body];
        }
        $statements = array_values(array_filter((array) ($body['data'] ?? []), 'is_array'));
        usort($statements, fn ($a, $b) => strcmp((string) ($b['statement_number'] ?? ''), (string) ($a['statement_number'] ?? '')));
        $latest = $statements[0] ?? null;

        $monthStart = now()->startOfMonth();
        $paidThisMonth = 0.0;
        $unpaid = 0.0;
        $unpaidCount = 0;
        foreach ($statements as $s) {
            $payout = $this->amount($s['payout'] ?? 0);
            if ((string) ($s['paid'] ?? '') !== '1') {
                $unpaid += $payout;
                $unpaidCount++;
            } elseif ($this->time($s['created_at'] ?? null)?->gte($monthStart)) {
                $paidThisMonth += $payout;
            }
        }

        // The ongoing statement: every finance line after the latest statement's day.
        $latestDay = $latest ? $this->statementDay($latest) : null;
        $ongoingFrom = ($latestDay ?? now()->startOfDay()->subDay())->copy()->addDay();
        $ongoing = 0.0;
        $offset = 0;
        $pages = 0;
        do {
            $page = $this->call($setting, $creds, self::LINES, [
                'start_time' => $ongoingFrom->toDateString(),
                'end_time' => now()->addDay()->toDateString(),
                'limit' => (string) self::PAGE_SIZE,
                'offset' => (string) $offset,
            ]);
            if (is_string($page)) {
                return ['ok' => false, 'error' => $page];
            }
            $lines = $page['data'] ?? [];
            if (is_array($lines) && ! array_is_list($lines)) {
                $lines = $lines['data'] ?? [];
            }
            $lines = is_array($lines) ? $lines : [];
            foreach ($lines as $line) {
                $ongoing += is_array($line) ? $this->amount($line['amount'] ?? 0) : 0.0;
            }
            $offset += self::PAGE_SIZE;
            $pages++;
        } while (count($lines) === self::PAGE_SIZE && $pages < self::MAX_PAGES);

        return [
            'ok' => true,
            'lead' => [
                'label' => 'Latest statement',
                'amount' => $latest ? $this->amount($latest['payout'] ?? 0) : null,
                'note' => $latest
                    ? ($latestDay ? $latestDay->format('j M') : (string) ($latest['statement_number'] ?? '')) . ((string) ($latest['paid'] ?? '') === '1' ? ', paid' : ', not paid yet')
                    : 'No statement in the last two months',
            ],
            'facts' => [
                ['label' => 'Ongoing statement', 'amount' => round($ongoing, 2)],
                ['label' => 'Paid out this month', 'amount' => round($paidThisMonth, 2)],
                ['label' => 'Statements not paid', 'amount' => round($unpaid, 2), 'sub' => $unpaidCount === 1 ? '1 statement' : $unpaidCount . ' statements'],
            ],
            'note' => 'Lazada does not share the wallet balance with apps. Your total balance is in Seller Center, under Finance.',
        ];
    }

    /** @return array<string, mixed>|string */
    private function call(object $setting, array $creds, string $path, array $query): array|string
    {
        $params = $query + [
            'app_key' => (string) $creds['app_key'],
            'sign_method' => 'sha256',
            'timestamp' => (string) round(microtime(true) * 1000),
            'access_token' => (string) $creds['access_token'],
        ];
        $params['sign'] = $this->client->sign($path, $params, (string) $creds['app_secret']);
        $result = $this->client->get((string) $setting->region, $path, $params);

        $body = is_array($result['body'] ?? null) ? $result['body'] : [];
        if (empty($result['ok']) || (string) ($body['code'] ?? '0') !== '0') {
            return trim((string) ($body['message'] ?? '')) ?: ('HTTP ' . ($result['status'] ?? 0));
        }

        return $body;
    }

    /** "PH1XR16D-2026-0930" is the statement for 30 Sep 2026. */
    private function statementDay(array $statement): ?Carbon
    {
        if (preg_match('/(\d{4})-(\d{2})(\d{2})$/', (string) ($statement['statement_number'] ?? ''), $m)
            && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return Carbon::create((int) $m[1], (int) $m[2], (int) $m[3])->startOfDay();
        }

        return $this->time($statement['created_at'] ?? null)?->subDay()->startOfDay();
    }

    private function amount(mixed $value): float
    {
        $clean = str_replace([',', ' '], '', preg_replace('/[A-Za-z]/', '', (string) $value));

        return is_numeric($clean) ? (float) $clean : 0.0;
    }

    private function time(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
