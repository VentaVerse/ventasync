<?php

namespace Extensions\TikTok\Services;

use App\Support\Fulfillment\AppFulfillment;
use Extensions\TikTok\Controllers\TikTokOrderController;
use Extensions\TikTok\Models\TikTokOrder;
use Extensions\TikTok\Models\TikTokStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class TikTokAppFulfillment
{
    public const ACTIONS = [
        'ship' => true,
        'awb' => false,
        'tracking' => false,
    ];

    public static function block(TikTokOrder $order): array
    {
        [$step, $writes, $reads] = match (strtoupper((string) $order->status)) {
            'AWAITING_SHIPMENT' => ['to_pack', ['ship'], []],
            'AWAITING_COLLECTION' => ['to_handover', [], ['awb', 'tracking']],
            'IN_TRANSIT' => ['shipped', [], ['tracking']],
            'DELIVERED', 'COMPLETED' => ['done', [], []],
            'CANCELLED' => ['canceled', [], []],
            default => ['other', [], []],
        };

        return AppFulfillment::block('tiktok', (int) $order->id, $step, $writes, $reads);
    }

    public function handle(string $action, int $id, Request $request): Response
    {
        $order = TikTokOrder::query()->find($id);
        $store = $order ? TikTokStore::query()->find($order->store_id) : null;
        if ($order === null || $store === null) {
            return response()->json(['error' => 'Order not found'], 404);
        }
        AppFulfillment::bindStore('tiktok', $store);

        $web = app(TikTokOrderController::class);

        return match ($action) {
            'ship' => AppFulfillment::answer(
                AppFulfillment::webOutcome(app()->call([$web, 'shipOrder'], ['request' => $request, 'id' => $id]), 'tiktok_orders_last_result'),
                app(\App\Channels\ChannelRegistry::class)->mobileProviderFor('tiktok')?->mobileShowResponse($id),
            ),
            'awb' => $this->waybill(app()->call([$web, 'awbPdf'], ['request' => $request, 'id' => $id]), (string) $order->order_id),
            'tracking' => $this->tracking(app()->call([$web, 'tracking'], ['id' => $id])),
        };
    }

    private function waybill(Response $answer, string $orderId): Response
    {
        $body = (string) $answer->getContent();
        if ($answer->isSuccessful() && str_starts_with($body, '%PDF')) {
            return AppFulfillment::waybill($body, 'AWB-' . $orderId . '.pdf');
        }

        return AppFulfillment::waybill(null, '', false, AppFulfillment::webOutcome($answer, 'tiktok_orders_last_result')['message']);
    }

    private function tracking(JsonResponse $answer): JsonResponse
    {
        $data = (array) $answer->getData(true);

        return response()->json([
            'ok' => (bool) ($data['ok'] ?? false),
            'tracking_number' => (string) ($data['tracking_number'] ?? ''),
            'carrier' => (string) ($data['shipping_carrier'] ?? ''),
            'events' => array_map(fn (array $e) => [
                'time' => isset($e['update_time_millis']) ? date('Y-m-d H:i', (int) ((int) $e['update_time_millis'] / 1000)) : null,
                'text' => (string) ($e['description'] ?? ''),
            ], array_values(array_filter((array) ($data['body']['data']['tracking'] ?? []), 'is_array'))),
            'message' => (string) ($data['message'] ?? ''),
        ]);
    }
}
