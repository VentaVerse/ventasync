<?php

namespace Extensions\Lazada\Services;

use App\Support\Fulfillment\AppFulfillment;
use Extensions\Lazada\Controllers\LazadaOrderController;
use Extensions\Lazada\Models\LazadaOrder;
use Extensions\Lazada\Models\LazadaStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class LazadaAppFulfillment
{
    public const ACTIONS = [
        'pack' => true,
        'rts' => true,
        'repack' => true,
        'awb' => false,
        'tracking' => false,
    ];

    public static function block(LazadaOrder $order): array
    {
        [$step, $writes, $reads] = match (strtolower((string) $order->status)) {
            'pending', 'repacked' => ['to_pack', ['pack'], []],
            'packed' => ['to_arrange', ['rts', 'repack'], ['awb']],
            'ready_to_ship' => ['to_handover', [], ['awb', 'tracking']],
            'shipped' => ['shipped', [], ['tracking']],
            'delivered' => ['done', [], []],
            'canceled', 'cancelled' => ['canceled', [], []],
            default => ['other', [], []],
        };

        return AppFulfillment::block('lazada', (int) $order->id, $step, $writes, $reads);
    }

    public function handle(string $action, int $id, Request $request): Response
    {
        $order = LazadaOrder::query()->find($id);
        $store = $order ? LazadaStore::query()->find($order->store_id) : null;
        if ($order === null || $store === null) {
            return response()->json(['error' => 'Order not found'], 404);
        }
        AppFulfillment::bindStore('lazada', $store);

        $web = app(LazadaOrderController::class);
        $args = ['request' => $request, 'orderId' => (string) $order->order_id];

        return match ($action) {
            'pack' => $this->outcome(app()->call([$web, 'pack'], $args), $id),
            'rts' => $this->outcome(app()->call([$web, 'rts'], $args), $id),
            'repack' => $this->outcome(app()->call([$web, 'recreatePackage'], $args), $id),
            'awb' => $this->waybill(app()->call([$web, 'awbPdf'], $args), (string) $order->order_id),
            'tracking' => $this->tracking(app()->call([$web, 'logisticsTrace'], $args)),
        };
    }

    private function outcome(Response $answer, int $id): JsonResponse
    {
        return AppFulfillment::answer(
            AppFulfillment::webOutcome($answer, 'lazada_orders_last_result'),
            app(\App\Channels\ChannelRegistry::class)->mobileProviderFor('lazada')?->mobileShowResponse($id),
        );
    }

    private function waybill(Response $answer, string $orderId): Response
    {
        $body = (string) $answer->getContent();
        if ($answer->isSuccessful() && str_starts_with($body, '%PDF')) {
            return AppFulfillment::waybill($body, 'awb_' . $orderId . '.pdf');
        }

        $message = preg_match('#<p>(.*?)</p>#s', $body, $m) ? html_entity_decode(strip_tags($m[1])) : '';
        if ($message === '') {
            $message = AppFulfillment::webOutcome($answer, 'lazada_orders_last_result')['message'];
        }

        return AppFulfillment::waybill(null, '', false, $message);
    }

    private function tracking(JsonResponse $answer): JsonResponse
    {
        $data = (array) $answer->getData(true);
        $module = (array) ($data['body']['result']['module'][0] ?? $data['body']['data']['module'][0] ?? []);
        $package = (array) ($module['package_detail_info_list'][0] ?? []);

        return response()->json([
            'ok' => (bool) ($data['ok'] ?? false),
            'tracking_number' => (string) ($package['tracking_number'] ?? ''),
            'carrier' => (string) ($package['logistic_provider_name'] ?? $package['shipment_provider'] ?? ''),
            'events' => array_map(fn (array $e) => [
                'time' => isset($e['event_time']) ? date('Y-m-d H:i', (int) ((int) $e['event_time'] / 1000)) : null,
                'text' => trim((string) ($e['title'] ?? '') . ' ' . (string) ($e['detail'] ?? '')),
            ], array_values(array_filter((array) ($package['logistic_detail_info_list'] ?? []), 'is_array'))),
            'message' => (string) ($data['message'] ?? ''),
        ], ($data['ok'] ?? false) ? 200 : 422);
    }
}
