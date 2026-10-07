<?php

namespace Extensions\shopee\Services;

use App\Support\Fulfilment\AppFulfilment;
use App\Support\Fulfilment\Waybills;
use Extensions\shopee\Controllers\ShopeeOrderController;
use Extensions\shopee\Models\ShopeeOrder;
use Extensions\shopee\Models\ShopeeSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ShopeeAppFulfilment
{
    public const ACTIONS = [
        'shipping-options' => false,
        'ship' => true,
        'awb' => false,
        'tracking' => false,
    ];

    public static function block(ShopeeOrder $order): array
    {
        [$step, $writes, $reads] = match (strtoupper((string) $order->status)) {
            'READY_TO_SHIP' => ['to_pack', ['ship'], []],
            'PROCESSED' => ['to_handover', [], ['awb', 'tracking']],
            'SHIPPED', 'TO_CONFIRM_RECEIVE' => ['shipped', [], ['tracking']],
            'COMPLETED' => ['done', [], []],
            'IN_CANCEL', 'CANCELLED' => ['cancelled', [], []],
            default => ['other', [], []],
        };

        return AppFulfilment::block('shopee', (int) $order->id, $step, $writes, $reads);
    }

    public function handle(string $action, int $id, Request $request): Response
    {
        $order = ShopeeOrder::query()->find($id);
        $store = $order ? ShopeeSetting::query()->find($order->shopee_setting_id) : null;
        if ($order === null || $store === null) {
            return response()->json(['error' => 'Order not found'], 404);
        }
        AppFulfilment::bindStore('shopee', $store);

        $web = app(ShopeeOrderController::class);
        $sn = (string) $order->order_sn;

        return match ($action) {
            'shipping-options' => $this->shippingOptions(app()->call([$web, 'getShippingAddresses'], ['orderSn' => $sn])),
            'ship' => AppFulfilment::answer(
                AppFulfilment::webOutcome(app()->call([$web, 'shipOrder'], ['request' => $request, 'orderSn' => $sn]), 'shopee_orders_last_result'),
                $this->payload($id),
            ),
            'awb' => $this->waybill(app()->call([$web, 'awbPdf'], ['request' => $request, 'orderSn' => $sn]), $store, $sn),
            'tracking' => $this->tracking(app()->call([$web, 'getTrackingInfo'], ['orderSn' => $sn])),
        };
    }

    private function shippingOptions(JsonResponse $answer): JsonResponse
    {
        $data = (array) $answer->getData(true);
        if (! ($data['ok'] ?? false)) {
            return AppFulfilment::refused((string) ($data['message'] ?? 'Shopee did not return the shipping options.'));
        }

        $addresses = array_map(fn (array $a) => [
            'address_id' => (int) ($a['address_id'] ?? 0),
            'address' => trim(implode(', ', array_filter([$a['address'] ?? '', $a['district'] ?? '', $a['city'] ?? '', $a['state'] ?? '', $a['zipcode'] ?? '']))),
            'default' => in_array('pickup_address', (array) ($a['address_flag'] ?? []), true),
            'slots' => array_map(fn (array $s) => [
                'pickup_time_id' => (string) ($s['pickup_time_id'] ?? ''),
                'date' => $s['date'] ?? null,
                'text' => (string) ($s['time_text'] ?? ''),
                'recommended' => in_array('recommended', (array) ($s['flags'] ?? []), true),
            ], array_values(array_filter((array) ($a['time_slot_list'] ?? []), 'is_array'))),
        ], array_values(array_filter((array) ($data['pickup']['address_list'] ?? []), 'is_array')));

        $branches = array_map(fn (array $b) => [
            'branch_id' => (int) ($b['branch_id'] ?? 0),
            'address' => trim(implode(', ', array_filter([$b['address'] ?? '', $b['district'] ?? '', $b['city'] ?? '', $b['state'] ?? '', $b['zipcode'] ?? '']))),
        ], array_values(array_filter((array) ($data['dropoff']['branch_list'] ?? []), 'is_array')));

        return response()->json([
            'ok' => true,
            'pickup' => $data['pickup'] === null ? null : ['addresses' => $addresses],
            'dropoff' => $data['dropoff'] === null ? null : ['branches' => $branches],
        ]);
    }

    private function waybill(Response $answer, ShopeeSetting $store, string $sn): Response
    {
        $path = Waybills::path('shopee', (int) $store->id, $sn);
        if (is_file($path)) {
            return AppFulfilment::waybill((string) file_get_contents($path), 'AWB-' . $sn . '.pdf');
        }

        $data = $answer instanceof JsonResponse ? (array) $answer->getData(true) : [];

        return AppFulfilment::waybill(null, 'AWB-' . $sn . '.pdf', true, (string) ($data['message'] ?? 'Shopee is still making the waybill.'));
    }

    private function tracking(JsonResponse $answer): JsonResponse
    {
        $data = (array) $answer->getData(true);

        return response()->json([
            'ok' => (bool) ($data['ok'] ?? false),
            'tracking_number' => (string) ($data['tracking_number'] ?? ''),
            'carrier' => (string) ($data['shipping_carrier'] ?? ''),
            'logistics_status' => $data['logistics_status'] ?? null,
            'events' => array_map(fn (array $e) => [
                'time' => isset($e['update_time']) ? date('Y-m-d H:i', (int) $e['update_time']) : null,
                'text' => (string) ($e['description'] ?? ''),
            ], array_values(array_filter((array) ($data['tracking_info'] ?? []), 'is_array'))),
        ]);
    }

    private function payload(int $id): ?array
    {
        return app(\App\Integrations\IntegrationRegistry::class)->mobileProviderFor('shopee')?->mobileShowResponse($id);
    }
}
