<?php

namespace Extensions\tiktok\Services\TikTok;

use App\Models\Catalog\Order;
use App\Models\Catalog\OrderHistory;
use Extensions\tiktok\Models\TikTokApiLog;
use Extensions\tiktok\Models\TikTokOrderStatusMap;
use Extensions\tiktok\Models\TikTokReturn;
use Extensions\tiktok\Models\TikTokSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TikTokReturnSync
{
    public function __construct(private TikTokClient $client) {}

    public function sync(array $creds, ?int $createdFrom, ?int $createdTo, ?int $userId = null): array
    {
        $body = array_filter([
            'create_time_ge' => $createdFrom,
            'create_time_lt' => $createdTo,
        ], fn ($v) => $v !== null);

        $all = [];
        $token = null;
        $failure = null;
        for ($page = 0; $page < 20; $page++) {
            try {
                $res = $this->client->post($creds['app_key'], $creds['app_secret'], $creds['token'], '/return_refund/202309/returns/search',
                    array_filter(['page_size' => 50, 'page_token' => $token]), $body, $creds['shop_cipher'] ?: null);
            } catch (\Throwable $e) {
                $res = ['ok' => false, 'status' => 0, 'body' => ['message' => \App\Support\TransportError::plain($e, 'TikTok Shop')]];
            }
            TikTokApiLog::safeCreate([
                'pack' => 'tiktok.returns.fetch', 'method' => 'POST',
                'api_path' => '/return_refund/202309/returns/search', 'auth_required' => true,
                'request_params' => $body + ['page_token' => $token],
                'response_status' => $res['status'] ?? 0, 'ok' => (bool) ($res['ok'] ?? false),
                'response_body' => $res['body'] ?? [], 'user_id' => $userId,
            ]);
            if (!($res['ok'] ?? false) || (int) ($res['body']['code'] ?? -1) !== 0) {
                $failure = (string) ($res['body']['message'] ?? 'no response');
                break;
            }
            foreach ((array) ($res['body']['data']['return_orders'] ?? []) as $ro) {
                $all[] = $ro;
            }
            $token = $res['body']['data']['next_page_token'] ?? null;
            if (!$token) {
                break;
            }
        }

        if ($failure !== null && empty($all)) {
            return ['ok' => false, 'saved' => 0, 'updated' => 0, 'statused' => 0, 'failure' => $failure,
                'message' => 'TikTok Shop did not answer: ' . $failure];
        }

        $returnMap = TikTokOrderStatusMap::where('context', 'return')->pluck('order_status_id', 'tiktok_status')->all();
        $saved = 0;
        $updated = 0;
        $statused = 0;
        foreach ($all as $ro) {
            if (!is_array($ro) || empty($ro['return_id'])) {
                continue;
            }
            $items = [];
            foreach ((array) ($ro['return_line_items'] ?? []) as $li) {
                $items[] = [
                    'seller_sku' => (string) ($li['seller_sku'] ?? ''),
                    'sku_id' => (string) ($li['sku_id'] ?? ''),
                    'product_name' => (string) ($li['product_name'] ?? ''),
                    'sku_name' => (string) ($li['sku_name'] ?? ''),
                    'image' => (string) ($li['product_image']['url'] ?? ''),
                    'refund_amount' => isset($li['refund_amount']['refund_total']) ? (float) $li['refund_amount']['refund_total'] : null,
                    'quantity' => (int) ($li['return_quantity'] ?? 1),
                ];
            }
            $payload = [
                'order_id' => (string) ($ro['order_id'] ?? '') ?: null,
                'return_type' => (string) ($ro['return_type'] ?? '') ?: null,
                'return_status' => (string) ($ro['return_status'] ?? '') ?: null,
                'reason' => Str::limit((string) ($ro['return_reason_text'] ?? $ro['return_reason'] ?? ''), 250) ?: null,
                'refund_amount' => isset($ro['refund_amount']['refund_total']) ? (float) $ro['refund_amount']['refund_total'] : null,
                'currency' => (string) ($ro['refund_amount']['currency'] ?? '') ?: null,
                'return_created_at' => !empty($ro['create_time']) ? Carbon::createFromTimestamp((int) $ro['create_time']) : null,
                'return_updated_at' => !empty($ro['update_time']) ? Carbon::createFromTimestamp((int) $ro['update_time']) : null,
                'tracking_number' => (string) ($ro['return_tracking_number'] ?? '') ?: null,
                'items' => $items,
                'raw' => $ro,
            ];
            $existing = TikTokReturn::query()->where('return_id', (string) $ro['return_id'])->first();
            if ($existing) {
                $existing->fill($payload)->save();
                $updated++;
            } else {
                TikTokReturn::query()->create(['return_id' => (string) $ro['return_id']] + $payload);
                $saved++;
            }

            $statusId = (int) ($returnMap[(string) ($ro['return_status'] ?? '')] ?? 0);
            $catalogOrderId = $payload['order_id']
                ? (int) (DB::table('tiktok_orders')->where('order_id', $payload['order_id'])
                    ->when(app()->bound('tiktok.route-store'), fn ($q) => $q->where('tiktok_setting_id', app('tiktok.route-store')->id))
                    ->value('catalog_order_id') ?? 0)
                : 0;
            if ($statusId > 0 && $catalogOrderId > 0) {
                $catalogOrder = Order::find($catalogOrderId);
                if ($catalogOrder && (int) $catalogOrder->order_status_id !== $statusId) {
                    $catalogOrder->update(['order_status_id' => $statusId]);
                    OrderHistory::create([
                        'order_id' => $catalogOrderId, 'order_status_id' => $statusId, 'notify' => 0,
                        'comment' => 'TikTok return ' . $ro['return_id'] . ' (' . (string) ($ro['return_status'] ?? '') . ')',
                        'date_added' => now(), 'user_id' => null, 'user_name' => 'TikTok Sync',
                    ]);
                    $statused++;
                }
            }
        }

        $message = 'Returns synced. ' . $saved . ' new, ' . $updated . ' updated'
            . ($statused ? ', ' . $statused . ' sales order' . ($statused === 1 ? '' : 's') . ' moved by the return status map' : '') . '.';
        if ($failure !== null) {
            $message .= ' TikTok Shop stopped answering part way: ' . $failure;
        }

        return ['ok' => true, 'saved' => $saved, 'updated' => $updated, 'statused' => $statused, 'failure' => $failure, 'message' => $message];
    }

    public static function credsFrom(?TikTokSetting $s): ?array
    {
        if (!$s) {
            return null;
        }
        $d = $s->decrypted();
        $sandbox = $s->mode === 'sandbox';
        $creds = [
            'app_key'     => $sandbox ? ($d->sandbox_app_key ?? '') : ($d->app_key ?? ''),
            'app_secret'  => $sandbox ? ($d->sandbox_app_secret ?? '') : ($d->app_secret ?? ''),
            'token'       => $sandbox ? ($d->sandbox_access_token ?? '') : ($d->access_token ?? ''),
            'shop_cipher' => (string) ($sandbox ? ($s->sandbox_shop_cipher ?? '') : ($s->shop_cipher ?? '')),
        ];

        return ($creds['app_key'] && $creds['app_secret'] && $creds['token']) ? $creds : null;
    }
}
