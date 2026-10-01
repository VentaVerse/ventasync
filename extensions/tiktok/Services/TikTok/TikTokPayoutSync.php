<?php

namespace Extensions\tiktok\Services\TikTok;

use Extensions\tiktok\Models\TikTokApiLog;
use Extensions\tiktok\Models\TikTokOrder;
use Extensions\tiktok\Models\TikTokSetting;

/**
 * Whether TikTok Shop has paid out each order, and when.
 *
 * TikTok settles orders into one statement a day, and pays each statement
 * out as a whole: a statement is PROCESSING until the money is sent, then
 * PAID with its payment time, or FAILED when the transfer did not go
 * through. An order's payout is therefore its statement's. This reads the
 * statements over a window (Get Statements, /finance/202309/statements),
 * the orders inside each one (Get Transactions by Statement,
 * /finance/202501/statements/{id}/statement_transactions), and writes the
 * verdict onto each waiting order:
 *
 *   PAID        Paid, paid_at = the statement's payment time
 *   PROCESSING  Releasing
 *   FAILED      Payment failed
 *
 * An order in no statement yet is not settled and is left as it is; the
 * payouts report reads it from the order's own status meanwhile. Paid
 * orders are never read again.
 */
class TikTokPayoutSync
{
    /** Statements are paged 100 at a time; a run reads at most this many. */
    public const MAX_STATEMENTS = 400;

    public const PAID = 'Paid';

    public const RELEASING = 'Releasing';

    public const FAILED = 'Payment failed';

    /** The order statuses that can have been settled into a statement. */
    private const SETTLED_STATUSES = ['COMPLETED', 'DELIVERED'];

    public function __construct(private readonly TikTokClient $client)
    {
    }

    /**
     * @param  array{app_key: string, app_secret: string, token: string, shop_cipher: string}  $creds
     * @param  int|null  $sinceTs  read statements generated on or after this time; null reads from
     *                             the oldest order still waiting, which is what a backfill wants
     * @param  (callable(string): void)|null  $say  progress lines, for a console
     * @return array{ok: bool, message: string, waiting: int, statements: int, paid: int, releasing: int, failed: int}
     */
    public function sync(TikTokSetting $store, array $creds, ?int $sinceTs = null, ?callable $say = null): array
    {
        $say ??= static function (string $line): void {};
        $out = ['ok' => true, 'message' => '', 'waiting' => 0, 'statements' => 0, 'paid' => 0, 'releasing' => 0, 'failed' => 0];

        $waiting = TikTokOrder::query()
            ->where('tiktok_setting_id', $store->id)
            ->whereIn('status', self::SETTLED_STATUSES)
            ->where(fn ($q) => $q->whereNull('payout_status')->orWhere('payout_status', '!=', self::PAID))
            ->get(['id', 'order_id', 'order_created_at', 'payout_status']);

        $out['waiting'] = $waiting->count();
        if ($waiting->isEmpty()) {
            $out['message'] = 'No delivered or completed orders are waiting for a payout.';

            return $out;
        }

        // A day before the oldest waiting order, so one created late on a UTC
        // day is covered; a caller's window can only narrow that.
        $oldest = (int) $waiting->min(fn ($o) => $o->order_created_at?->getTimestamp() ?? time()) - 86400;
        $from = $sinceTs === null ? $oldest : max($sinceTs, $oldest);

        $byOrderId = $waiting->keyBy(fn ($o) => (string) $o->order_id);
        $verdict = [];
        $pageToken = '';

        do {
            $params = [
                'statement_time_ge' => $from,
                'sort_field' => 'statement_time',
                'sort_order' => 'ASC',
                'page_size' => 100,
            ];
            if ($pageToken !== '') {
                $params['page_token'] = $pageToken;
            }

            $res = $this->call($creds, '/finance/202309/statements', $params);
            if ($res['error'] !== null) {
                return ['ok' => false, 'message' => 'TikTok Shop did not list the statements: ' . $res['error']] + $out;
            }

            foreach ((array) ($res['data']['statements'] ?? []) as $statement) {
                if (! is_array($statement) || empty($statement['id'])) {
                    continue;
                }
                $out['statements']++;

                $state = strtoupper((string) ($statement['payment_status'] ?? ''));
                $paidAt = (int) ($statement['payment_time'] ?? 0) ?: (int) ($statement['statement_time'] ?? 0);

                $orders = $this->ordersIn($creds, (string) $statement['id']);
                if ($orders === null) {
                    return ['ok' => false, 'message' => 'TikTok Shop did not list the orders in statement ' . $statement['id'] . '.'] + $out;
                }

                foreach ($orders as $orderId) {
                    if (! $byOrderId->has($orderId)) {
                        continue;
                    }
                    // A later statement can carry an adjustment for the same
                    // order; the settlement itself is the first one it is in.
                    $verdict[$orderId] ??= ['state' => $state, 'paid_at' => $paidAt];
                }

                if (count($verdict) >= $byOrderId->count() || $out['statements'] >= self::MAX_STATEMENTS) {
                    break 2;
                }
            }

            $pageToken = (string) ($res['data']['next_page_token'] ?? '');
        } while ($pageToken !== '');

        foreach ($verdict as $orderId => $v) {
            $order = $byOrderId->get($orderId);
            $values = match ($v['state']) {
                'PAID' => ['payout_status' => self::PAID, 'paid_at' => $v['paid_at'] > 0 ? date('Y-m-d H:i:s', $v['paid_at']) : now()],
                'FAILED' => ['payout_status' => self::FAILED, 'paid_at' => null],
                default => ['payout_status' => self::RELEASING, 'paid_at' => null],
            };

            TikTokOrder::query()->whereKey($order->id)->where('tiktok_setting_id', $store->id)->update($values);

            match ($values['payout_status']) {
                self::PAID => $out['paid']++,
                self::FAILED => $out['failed']++,
                default => $out['releasing']++,
            };
        }

        $out['message'] = "Payouts: {$out['paid']} paid, {$out['releasing']} releasing"
            . ($out['failed'] ? ", {$out['failed']} payment failed" : '')
            . ", from {$out['statements']} statements; "
            . ($out['waiting'] - count($verdict)) . ' not settled yet.';
        $say($out['message']);

        return $out;
    }

    /**
     * The order ids settled in one statement, every page of it, or null when
     * TikTok Shop refused.
     *
     * @return list<string>|null
     */
    private function ordersIn(array $creds, string $statementId): ?array
    {
        $ids = [];
        $pageToken = '';
        $pages = 0;

        do {
            $params = ['sort_field' => 'order_create_time', 'page_size' => 100];
            if ($pageToken !== '') {
                $params['page_token'] = $pageToken;
            }

            $res = $this->call($creds, '/finance/202501/statements/' . rawurlencode($statementId) . '/statement_transactions', $params);
            if ($res['error'] !== null) {
                return null;
            }

            foreach ((array) ($res['data']['transactions'] ?? []) as $trx) {
                if (is_array($trx) && strtoupper((string) ($trx['type'] ?? '')) === 'ORDER' && ! empty($trx['order_id'])) {
                    $ids[] = (string) $trx['order_id'];
                }
            }

            $pageToken = (string) ($res['data']['next_page_token'] ?? '');
        } while ($pageToken !== '' && ++$pages < 50);

        return array_values(array_unique($ids));
    }

    /**
     * One GET, logged like every other TikTok call. The verdict is the body's
     * own code, never the HTTP status.
     *
     * @return array{data: array<string, mixed>, error: ?string}
     */
    private function call(array $creds, string $path, array $params): array
    {
        $result = $this->client->get($creds['app_key'], $creds['app_secret'], $creds['token'], $path, $params, $creds['shop_cipher'] ?: null);
        $body = is_array($result['body'] ?? null) ? $result['body'] : [];
        $code = (int) ($body['code'] ?? -1);

        TikTokApiLog::safeCreate([
            'pack' => 'payout-sync',
            'method' => 'GET',
            'api_path' => $path,
            'auth_required' => true,
            'request_params' => $params,
            'response_status' => $result['status'] ?? 0,
            'ok' => ($result['ok'] ?? false) && $code === 0,
            'response_body' => $body,
            'user_id' => null,
        ]);

        if (($result['ok'] ?? false) && $code === 0) {
            return ['data' => is_array($body['data'] ?? null) ? $body['data'] : [], 'error' => null];
        }

        return ['data' => [], 'error' => trim((string) ($body['message'] ?? '')) ?: ('HTTP ' . ($result['status'] ?? 0))];
    }
}
