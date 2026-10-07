<?php

namespace Extensions\lazada\Services\Lazada;

use Carbon\Carbon;
use Extensions\lazada\Models\LazadaSetting;

class LazadaBalance
{
    private const STATEMENTS = '/finance/payout/status/get';

    private const LINES = '/finance/transaction/details/get';

    private const LEDGER = '/finance/transaction/accountTransactions/query';

    private const LEDGER_DAYS = 21;

    private const PAGE_SIZE = 500;

    private const MAX_PAGES = 20;

    public function __construct(private readonly LazadaClient $client)
    {
    }

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

        $toConfirm = \Extensions\lazada\Models\LazadaOrder::query()
            ->where('lazada_setting_id', $store->id)
            ->where('status', 'delivered')
            ->where(fn ($q) => $q->whereNull('payout_status')->orWhere('payout_status', '!=', \App\Support\PayoutStatus::PAID))
            ->count();

        $balance = $this->totalBalance($setting, $creds);
        if (is_string($balance)) {
            return ['ok' => false, 'error' => $balance];
        }

        return [
            'ok' => true,
            'lead' => [
                'label' => 'Total Balance',
                'amount' => $balance,
                'note' => null,
            ],
            'facts' => [
                ['label' => 'To be released tomorrow', 'amount' => round($ongoing, 2)],
                ['label' => 'Orders to be confirmed', 'text' => $toConfirm === 1 ? '1 order' : number_format($toConfirm) . ' orders'],
            ],
        ];
    }

    private function totalBalance(object $setting, array $creds): float|string|null
    {
        $rows = [];
        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $body = $this->post($setting, $creds, self::LEDGER, [
                'start_time' => now()->subDays(self::LEDGER_DAYS)->format('Ymd'),
                'end_time' => now()->format('Ymd'),
                'page_num' => (string) $page,
                'page_size' => '100',
            ]);
            if (is_string($body)) {
                return $body;
            }
            $batch = (array) ($body['data']['transactions'] ?? []);
            array_push($rows, ...array_filter($batch, 'is_array'));
            if (count($batch) < 100) {
                break;
            }
        }

        $at = fn (array $r) => strtotime((string) ($r['transaction_time'] ?? '')) ?: 0;
        $withdrawals = array_filter($rows, fn ($r) => ($r['type'] ?? '') === 'Withdrawal');
        if ($withdrawals === []) {
            return null;
        }
        $last = max(array_map($at, $withdrawals));
        $since = array_filter($rows, fn ($r) => ($r['type'] ?? '') !== 'Withdrawal' && $at($r) > $last);

        return round(array_sum(array_map(fn ($r) => $this->amount($r['amount'] ?? 0), $since)), 2);
    }

    private function post(object $setting, array $creds, string $path, array $query): array|string
    {
        $params = $query + [
            'app_key' => (string) $creds['app_key'],
            'sign_method' => 'sha256',
            'timestamp' => (string) round(microtime(true) * 1000),
            'access_token' => (string) $creds['access_token'],
        ];
        $params['sign'] = $this->client->sign($path, $params, (string) $creds['app_secret']);
        $result = $this->client->post((string) $setting->region, $path, $params);

        $body = is_array($result['body'] ?? null) ? $result['body'] : [];
        if (empty($result['ok']) || (string) ($body['code'] ?? '0') !== '0') {
            return trim((string) ($body['message'] ?? '')) ?: ('HTTP ' . ($result['status'] ?? 0));
        }

        return $body;
    }

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
