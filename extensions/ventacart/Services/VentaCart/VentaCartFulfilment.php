<?php

namespace Extensions\ventacart\Services\VentaCart;

use App\Services\ActivityLogger;
use App\Support\FulfilmentSteps;
use Extensions\ventacart\Models\VentaCartOrder;
use Extensions\ventacart\Models\VentaCartSetting;
use Illuminate\Support\Facades\Log;

class VentaCartFulfilment
{
    public const BOOKING_RULES = [
        'courier'           => ['nullable', 'string', 'max:32'],
        'service'           => ['nullable', 'string', 'max:64'],
        'parcel'            => ['nullable', 'string', 'max:64'],
        'shipping_payment'  => ['nullable', 'string', 'max:32'],
        'buyer_payment'     => ['nullable', 'in:cod,prepaid'],
        'insure'            => ['nullable', 'boolean'],
        'length_cm'         => ['nullable', 'numeric', 'min:0.01'],
        'width_cm'          => ['nullable', 'numeric', 'min:0.01'],
        'height_cm'         => ['nullable', 'numeric', 'min:0.01'],
        'weight_kg'         => ['nullable', 'numeric', 'min:0.01'],
        'pickup_slot'       => ['nullable', 'string', 'max:255'],
        'pickup_address_id' => ['nullable', 'integer'],
    ];

    public function __construct(
        private VentaCartSetting $setting,
        private VentaCartClient $client,
    ) {
    }

    public static function for(VentaCartSetting $setting): self
    {
        return new self($setting, new VentaCartClient($setting));
    }

    public function serviceability(VentaCartOrder $order): array
    {
        $res = $this->client->serviceability((int) $order->ventacart_order_id);
        $body = $res['body'] ?? [];

        if (! ($res['ok'] ?? false)) {
            return ['ok' => false, 'message' => self::errorFrom($body, 'The storefront could not check who can carry this parcel.'), 'couriers' => [], 'all_refused' => false];
        }

        $couriers = is_array($body['couriers'] ?? null) ? $body['couriers'] : [];

        foreach ($couriers as $key => $verdict) {
            $verdict = is_array($verdict) ? $verdict : [];
            $couriers[$key] = VentaCartCourierOptions::for((string) $key, $verdict) + $verdict;
        }

        return [
            'ok' => true,
            'message' => '',
            'couriers' => $couriers,
            'all_refused' => (bool) ($body['all_refused'] ?? false),
        ];
    }

    public function pickupAddresses(): array
    {
        $res = $this->client->pickupAddresses();
        $body = $res['body'] ?? [];

        if (! ($res['ok'] ?? false)) {
            return ['ok' => false, 'message' => self::errorFrom($body, 'The store\'s pickup addresses are not available right now.'), 'addresses' => []];
        }

        $rows = is_array($body['data'] ?? null) ? $body['data'] : (is_array($body) && array_is_list($body) ? $body : []);
        $addresses = [];
        foreach ($rows as $row) {
            if (! is_array($row) || empty($row['id'])) {
                continue;
            }
            $addresses[] = [
                'id' => (int) $row['id'],
                'label' => (string) ($row['label'] ?? ('Address ' . $row['id'])),
                'summary' => (string) ($row['summary'] ?? implode(', ', array_filter([
                    $row['address_1'] ?? null, $row['barangay'] ?? null, $row['city'] ?? null, $row['state'] ?? null,
                ]))),
                'is_default' => (bool) ($row['is_default'] ?? false),
            ];
        }
        usort($addresses, fn ($a, $b) => $b['is_default'] <=> $a['is_default']);

        return ['ok' => true, 'message' => '', 'addresses' => $addresses];
    }

    public function pickupSlots(VentaCartOrder $order, ?string $courier): array
    {
        $res = $this->client->pickupSlots((int) $order->ventacart_order_id, $courier);
        $body = $res['body'] ?? [];

        if (! ($res['ok'] ?? false)) {
            return ['ok' => false, 'message' => self::errorFrom($body, 'Pickup slots are not available right now.'), 'slots' => []];
        }

        return ['ok' => true, 'message' => '', 'slots' => is_array($body['slots'] ?? null) ? array_values($body['slots']) : []];
    }

    public function estimate(VentaCartOrder $order, array $opts): array
    {
        $res = $this->client->estimate((int) $order->ventacart_order_id, $opts);
        $body = $res['body'] ?? [];

        if (! ($res['ok'] ?? false)) {
            return ['ok' => false, 'message' => self::errorFrom($body, 'An estimate is not available for this booking.'), 'label' => '', 'amount' => null, 'currency' => null, 'insurance' => null, 'total' => null];
        }

        return [
            'ok' => true,
            'message' => '',
            'label' => (string) ($body['label'] ?? ''),
            'amount' => isset($body['amount']) ? (float) $body['amount'] : null,
            'currency' => $body['currency'] ?? null,
            'insurance' => is_numeric($body['insurance'] ?? null) ? (float) $body['insurance'] : null,
            'total' => isset($body['total']) ? (float) $body['total'] : (isset($body['amount']) ? (float) $body['amount'] : null),
        ];
    }

    public function book(VentaCartOrder $order, array $opts): array
    {
        $res = $this->client->bookCourier((int) $order->ventacart_order_id, $opts);
        $body = $res['body'] ?? [];

        if (! ($res['ok'] ?? false) || ! ($body['success'] ?? false)) {
            return [
                'ok' => false,
                'message' => self::errorFrom($body, 'The storefront could not book this parcel.'),
                'already_booked' => false,
                'courier' => null,
                'tracking_number' => null,
            ];
        }

        $order->forceFill([
            'courier_provider'        => $body['courier'] ?? $opts['courier'] ?? null,
            'courier_tracking_number' => $body['tracking_number'] ?? null,
            'courier_status'          => $body['status'] ?? null,
            'courier_booked_at'       => now(),
        ])->save();

        ActivityLogger::log(
            'booked',
            'VentaCartOrder',
            (int) $order->id,
            'Booked ' . ($body['courier'] ?? 'courier') . ' for VentaCart #' . $order->ventacart_order_id
                . (isset($body['tracking_number']) ? ' (' . $body['tracking_number'] . ')' : ''),
            ['store' => $this->setting->store_name, 'opts' => $opts]
        );

        $this->refresh($order);
        $landing = $this->landing($order);

        return [
            'ok' => true,
            'message' => (($body['already_booked'] ?? false) ? 'This order was already booked; nothing new was created. ' : 'Booked. ') . $landing['sentence'],
            'already_booked' => (bool) ($body['already_booked'] ?? false),
            'courier' => $body['courier'] ?? null,
            'tracking_number' => $body['tracking_number'] ?? null,
            'landing' => $landing,
        ];
    }

    public function shippingCouriers(): array
    {
        $res = $this->client->shippingCouriers();
        $body = $res['body'] ?? [];

        if (! ($res['ok'] ?? false)) {
            return ['ok' => false, 'message' => self::errorFrom($body, 'The store\'s courier list is not available right now.'), 'couriers' => []];
        }

        $rows = is_array($body['data'] ?? null) ? $body['data'] : (is_array($body) && array_is_list($body) ? $body : []);
        $couriers = [];
        foreach ($rows as $row) {
            if (! is_array($row) || empty($row['name'])) {
                continue;
            }
            if (array_key_exists('is_active', $row) && ! $row['is_active']) {
                continue;
            }
            $couriers[] = [
                'id' => (int) ($row['id'] ?? 0),
                'name' => (string) $row['name'],
                'tracking_url' => $row['tracking_url'] ?? null,
            ];
        }

        return ['ok' => true, 'message' => '', 'couriers' => $couriers];
    }

    public function bookManual(VentaCartOrder $order, array $opts): array
    {
        $res = $this->client->bookManual((int) $order->ventacart_order_id, array_filter([
            'shipping_courier_id' => $opts['shipping_courier_id'] ?? null,
            'tracking_number' => $opts['tracking_number'] ?? null,
            'comment' => $opts['comment'] ?? null,
        ], fn ($v) => $v !== null && $v !== ''));
        $body = $res['body'] ?? [];

        if (! ($res['ok'] ?? false) || ! ($body['success'] ?? false)) {
            return [
                'ok' => false,
                'message' => self::errorFrom($body, 'The store could not record this shipment.'),
                'courier_name' => null,
                'tracking_number' => null,
            ];
        }

        $courierName = (string) ($body['courier']['manual_courier'] ?? $opts['courier_name'] ?? '');
        $tracking = (string) ($body['courier']['tracking_number'] ?? $opts['tracking_number'] ?? '');

        $order->forceFill([
            'courier_provider'        => VentaCartOrder::MANUAL_PROVIDER,
            'courier_name'            => $courierName !== '' ? $courierName : null,
            'courier_tracking_number' => $tracking !== '' ? $tracking : null,
            'courier_status'          => 'manual',
            'courier_booked_at'       => now(),
        ])->save();

        ActivityLogger::log(
            'booked',
            'VentaCartOrder',
            (int) $order->id,
            'Recorded a shipment by hand' . ($courierName !== '' ? ' with ' . $courierName : '') . ' for VentaCart #' . $order->ventacart_order_id
                . ($tracking !== '' ? ' (' . $tracking . ')' : ''),
            ['store' => $this->setting->store_name, 'opts' => $opts]
        );

        $this->refresh($order);
        $landing = $this->landing($order);

        return [
            'ok' => true,
            'message' => 'Recorded.' . ($courierName !== '' ? ' ' . $courierName . ' carries it. ' : ' ') . $landing['sentence'],
            'courier_name' => $courierName !== '' ? $courierName : null,
            'tracking_number' => $tracking !== '' ? $tracking : null,
            'landing' => $landing,
        ];
    }

    public function clearManual(VentaCartOrder $order): array
    {
        $res = $this->client->clearManual((int) $order->ventacart_order_id);
        $body = $res['body'] ?? [];

        if (! ($res['ok'] ?? false) || ! ($body['success'] ?? false)) {
            return ['ok' => false, 'message' => self::errorFrom($body, 'The store could not clear this shipment.')];
        }

        $order->forceFill([
            'courier_provider'        => null,
            'courier_name'            => null,
            'courier_tracking_number' => null,
            'courier_status'          => null,
            'courier_booked_at'       => null,
        ])->save();

        ActivityLogger::log('unbooked', 'VentaCartOrder', (int) $order->id,
            'Cleared the hand-recorded shipment for VentaCart #' . $order->ventacart_order_id, ['store' => $this->setting->store_name]);

        $this->refresh($order);

        return ['ok' => true, 'message' => (string) ($body['message'] ?? 'Shipment cleared. The order is back in To Pack.')];
    }

    public function cancel(VentaCartOrder $order): array
    {
        $res = $this->client->cancelBooking((int) $order->ventacart_order_id);
        $body = $res['body'] ?? [];

        if (! ($res['ok'] ?? false) || ! ($body['success'] ?? false)) {
            return ['ok' => false, 'message' => self::errorFrom($body, 'The storefront could not cancel this booking.')];
        }

        $order->forceFill([
            'courier_provider'        => null,
            'courier_name'            => null,
            'courier_tracking_number' => null,
            'courier_status'          => null,
            'courier_booked_at'       => null,
        ])->save();

        $this->forgetLabel($order);

        ActivityLogger::log(
            'unbooked',
            'VentaCartOrder',
            (int) $order->id,
            'Cancelled the courier booking for VentaCart #' . $order->ventacart_order_id,
            ['store' => $this->setting->store_name]
        );

        $this->refresh($order);

        return ['ok' => true, 'message' => (string) ($body['message'] ?? 'Booking cancelled. The order is back in To Pack.')];
    }

    public function tracking(VentaCartOrder $order): array
    {
        $res = $this->client->tracking((int) $order->ventacart_order_id);
        $body = $res['body'] ?? [];

        if (! ($res['ok'] ?? false)) {
            return ['ok' => false, 'message' => self::errorFrom($body, 'Tracking is not available for this order.'), 'courier' => null, 'tracking_number' => null, 'status' => null, 'timeline' => [], 'links' => []];
        }

        if (! empty($body['status']) && $order->courier_status !== $body['status']) {
            $order->forceFill(['courier_status' => (string) $body['status']])->save();
        }

        return [
            'ok' => true,
            'message' => '',
            'courier' => $body['courier'] ?? $order->courier_provider,
            'tracking_number' => $body['tracking_number'] ?? $order->courier_tracking_number,
            'status' => $body['status'] ?? null,
            'timeline' => is_array($body['timeline'] ?? null) ? array_values($body['timeline']) : [],
            'links' => is_array($body['links'] ?? null) ? $body['links'] : [],
        ];
    }

    public function label(VentaCartOrder $order, bool $refresh = false): array
    {
        $path = $this->labelPath($order);

        if (! $refresh && is_file($path)) {
            return ['ok' => true, 'message' => '', 'path' => $path];
        }

        $res = $this->client->label((int) $order->ventacart_order_id);

        if (! ($res['ok'] ?? false)) {
            return ['ok' => false, 'message' => self::errorFrom($res['body'] ?? [], 'The waybill is not available yet.'), 'path' => null];
        }

        $pdf = $res['pdf'] ?? null;

        if ($pdf === null) {
            $url = (string) (($res['body']['url'] ?? ''));
            if ($url === '') {
                return ['ok' => false, 'message' => 'The storefront returned no waybill.', 'path' => null];
            }

            try {
                $file = \App\Support\Net\StoreRequest::to($url)->timeout(30)->get($url);
            } catch (\Throwable $e) {
                Log::warning('VentaCart waybill download failed', ['url' => $url, 'error' => $e->getMessage()]);

                return ['ok' => false, 'message' => 'The courier\'s waybill link could not be opened.', 'path' => null];
            }

            if (! $file->successful() || $file->body() === '') {
                return ['ok' => false, 'message' => 'The courier\'s waybill link could not be opened.', 'path' => null];
            }

            $pdf = $file->body();
        }

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        file_put_contents($path, $pdf);

        return ['ok' => true, 'message' => '', 'path' => $path];
    }

    public function hasLabel(VentaCartOrder $order): bool
    {
        return is_file($this->labelPath($order));
    }

    private function labelPath(VentaCartOrder $order): string
    {
        return \App\Support\Fulfilment\Waybills::path('ventacart', (int) $this->setting->id, (string) (int) $order->ventacart_order_id);
    }

    private function forgetLabel(VentaCartOrder $order): void
    {
        $path = $this->labelPath($order);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    private function refresh(VentaCartOrder $order): void
    {
        try {
            $fresh = (new VentaCartOrderSync($this->client, $this->setting))->pullOne((int) $order->ventacart_order_id);
            if ($fresh) {
                $order->setRawAttributes($fresh->getAttributes(), true);
            }
        } catch (\Throwable $e) {
            Log::warning('VentaCart order re-read after booking failed', [
                'order' => $order->ventacart_order_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function landing(VentaCartOrder $order): array
    {
        $status = trim((string) $order->status);
        $step = VentaCartStatusPlacements::stepFor((int) $this->setting->id, $status) ?? 'other';
        $label = FulfilmentSteps::label($step);
        $known = VentaCartStatusPlacements::known((int) $this->setting->id);

        if ($step === 'to_handover') {
            $sentence = 'The store moved it to "' . $status . '", so it is in To Handover.';
        } elseif (! $known) {
            $sentence = 'The store moved it to "' . $status . '". This store has not reported where it places its statuses yet, so the step is a guess from the name: ' . $label . '. Pull orders once so the store can say.';
        } else {
            $sentence = 'The store moved it to "' . $status . '", which it places under ' . $label . ', not To Handover. Change that status\'s placement in the store\'s fulfilment settings if it belongs at handover.';
        }

        return ['status' => $status, 'step' => $step, 'label' => $label, 'sentence' => $sentence];
    }

    private static function errorFrom(mixed $body, string $fallback): string
    {
        if (is_array($body)) {
            foreach (['error', 'message'] as $k) {
                if (is_string($body[$k] ?? null) && trim($body[$k]) !== '') {
                    return trim($body[$k]);
                }
            }
        }

        return $fallback;
    }
}
