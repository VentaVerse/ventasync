<?php

namespace Extensions\venta\Services\Venta;

use App\Services\ActivityLogger;
use App\Support\FulfilmentSteps;
use Extensions\venta\Models\VentaOrder;
use Extensions\venta\Models\VentaSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Courier fulfilment of a storefront order, run from the ERP (#168).
 *
 * The storefront owns the courier integration: it knows QuadX, SPX and LBC,
 * holds the credentials, and moves its own order status when a parcel is
 * booked or a booking is cancelled. This class does not know any courier.
 * It asks the storefront, records what the storefront answered on the ERP's
 * mirror row, and then re-reads the order so the row lands in the step the
 * storefront put it in. That re-read is what makes "book" move a row from
 * To Pack to To Handover in one click rather than after the next pull.
 *
 * Everything returns arrays shaped for the controller: ok, message, and
 * whatever the storefront said, never an exception for a refusal. A
 * refusal ("courier unavailable", "address not serviceable") is an answer
 * the packer needs to read, not an error.
 */
class VentaFulfilment
{
    public function __construct(
        private VentaSetting $setting,
        private VentaClient $client,
    ) {
    }

    public static function for(VentaSetting $setting): self
    {
        return new self($setting, new VentaClient($setting));
    }

    /** @return array{ok: bool, message: string, couriers: array<string, array>, all_refused: bool} */
    public function serviceability(VentaOrder $order): array
    {
        $res = $this->client->serviceability((int) $order->venta_order_id);
        $body = $res['body'] ?? [];

        if (! ($res['ok'] ?? false)) {
            return ['ok' => false, 'message' => self::errorFrom($body, 'The storefront could not check who can carry this parcel.'), 'couriers' => [], 'all_refused' => false];
        }

        $couriers = is_array($body['couriers'] ?? null) ? $body['couriers'] : [];

        // Each courier's answer carries what a booking with it can choose
        // and what it chooses by default, so the modal's second step is the
        // storefront's own: the storefront's lists when it sends them, the
        // ERP's copy of them until it does.
        foreach ($couriers as $key => $verdict) {
            // Options first in the union: they already prefer what the
            // store sent, field by field, and fill the rest; the verdict's
            // own keys (status, kind, reason, fees, from) pass through.
            $verdict = is_array($verdict) ? $verdict : [];
            $couriers[$key] = VentaCourierOptions::for((string) $key, $verdict) + $verdict;
        }

        return [
            'ok' => true,
            'message' => '',
            'couriers' => $couriers,
            'all_refused' => (bool) ($body['all_refused'] ?? false),
        ];
    }

    /**
     * Where a parcel can leave from: the store's saved pickup addresses,
     * default first. An empty list means the store's default address
     * applies and the modal offers no choice.
     *
     * @return array{ok: bool, message: string, addresses: list<array{id: int, label: string, summary: string, is_default: bool}>}
     */
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

    /** @return array{ok: bool, message: string, slots: array<int, array{value: string, label: string}>} */
    public function pickupSlots(VentaOrder $order, ?string $courier): array
    {
        $res = $this->client->pickupSlots((int) $order->venta_order_id, $courier);
        $body = $res['body'] ?? [];

        if (! ($res['ok'] ?? false)) {
            return ['ok' => false, 'message' => self::errorFrom($body, 'Pickup slots are not available right now.'), 'slots' => []];
        }

        return ['ok' => true, 'message' => '', 'slots' => is_array($body['slots'] ?? null) ? array_values($body['slots']) : []];
    }

    /** @return array{ok: bool, message: string, label: string, amount: ?float, currency: ?string, insurance: mixed, total: ?float} */
    public function estimate(VentaOrder $order, array $opts): array
    {
        $res = $this->client->estimate((int) $order->venta_order_id, $opts);
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

    /**
     * Book the parcel. On success the ERP row carries the booking at once
     * (courier, tracking number, courier status, when) and is then re-read
     * from the storefront so its order status, and with it its step, is
     * whatever the storefront moved it to.
     *
     * @return array{ok: bool, message: string, already_booked: bool, courier: ?string, tracking_number: ?string}
     */
    public function book(VentaOrder $order, array $opts): array
    {
        $res = $this->client->bookCourier((int) $order->venta_order_id, $opts);
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
            'VentaOrder',
            (int) $order->id,
            'Booked ' . ($body['courier'] ?? 'courier') . ' for Venta #' . $order->venta_order_id
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

    /**
     * The store's couriers a parcel can be handed to by hand.
     *
     * @return array{ok: bool, message: string, couriers: list<array{id: int, name: string, tracking_url: ?string}>}
     */
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

    /**
     * Record a shipment by hand: a courier off the store's list and a
     * tracking number. The ERP row carries it at once under the 'manual'
     * provider with the courier's name, and is re-read so its step is
     * whatever the store moved it to.
     *
     * @param  array{shipping_courier_id?: int, courier_name?: string, tracking_number?: string, comment?: string}  $opts
     * @return array{ok: bool, message: string, courier_name: ?string, tracking_number: ?string}
     */
    public function bookManual(VentaOrder $order, array $opts): array
    {
        $res = $this->client->bookManual((int) $order->venta_order_id, array_filter([
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
            'courier_provider'        => VentaOrder::MANUAL_PROVIDER,
            'courier_name'            => $courierName !== '' ? $courierName : null,
            'courier_tracking_number' => $tracking !== '' ? $tracking : null,
            'courier_status'          => 'manual',
            'courier_booked_at'       => now(),
        ])->save();

        ActivityLogger::log(
            'booked',
            'VentaOrder',
            (int) $order->id,
            'Recorded a shipment by hand' . ($courierName !== '' ? ' with ' . $courierName : '') . ' for Venta #' . $order->venta_order_id
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

    /** Undo a shipment recorded by hand. @return array{ok: bool, message: string} */
    public function clearManual(VentaOrder $order): array
    {
        $res = $this->client->clearManual((int) $order->venta_order_id);
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

        ActivityLogger::log('unbooked', 'VentaOrder', (int) $order->id,
            'Cleared the hand-recorded shipment for Venta #' . $order->venta_order_id, ['store' => $this->setting->store_name]);

        $this->refresh($order);

        return ['ok' => true, 'message' => (string) ($body['message'] ?? 'Shipment cleared. The order is back in To Pack.')];
    }

    /** @return array{ok: bool, message: string} */
    public function cancel(VentaOrder $order): array
    {
        $res = $this->client->cancelBooking((int) $order->venta_order_id);
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
            'VentaOrder',
            (int) $order->id,
            'Cancelled the courier booking for Venta #' . $order->venta_order_id,
            ['store' => $this->setting->store_name]
        );

        $this->refresh($order);

        return ['ok' => true, 'message' => (string) ($body['message'] ?? 'Booking cancelled. The order is back in To Pack.')];
    }

    /** @return array{ok: bool, message: string, courier: ?string, tracking_number: ?string, status: ?string, timeline: array, links: array} */
    public function tracking(VentaOrder $order): array
    {
        $res = $this->client->tracking((int) $order->venta_order_id);
        $body = $res['body'] ?? [];

        if (! ($res['ok'] ?? false)) {
            return ['ok' => false, 'message' => self::errorFrom($body, 'Tracking is not available for this order.'), 'courier' => null, 'tracking_number' => null, 'status' => null, 'timeline' => [], 'links' => []];
        }

        // The storefront refreshed its own record on the way through; keep
        // the mirror's courier status in step with it.
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

    /**
     * The waybill as a local PDF path, fetching and caching it on first
     * use. Null with a message when there is nothing to print.
     *
     * The storefront answers either with the PDF itself (couriers whose own
     * link is perishable, SPX) or with the courier's URL. Both end up as one
     * cached file here, so a reprint at handover never asks the courier
     * twice and works even when the courier's link has expired.
     *
     * @return array{ok: bool, message: string, path: ?string}
     */
    public function label(VentaOrder $order, bool $refresh = false): array
    {
        $path = $this->labelPath($order);

        if (! $refresh && is_file($path)) {
            return ['ok' => true, 'message' => '', 'path' => $path];
        }

        $res = $this->client->label((int) $order->venta_order_id);

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
                $file = Http::timeout(30)->get($url);
            } catch (\Throwable $e) {
                Log::warning('Venta waybill download failed', ['url' => $url, 'error' => $e->getMessage()]);

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

    public function hasLabel(VentaOrder $order): bool
    {
        return is_file($this->labelPath($order));
    }

    private function labelPath(VentaOrder $order): string
    {
        return storage_path('app/venta-awb/' . (int) $this->setting->id . '/' . (int) $order->venta_order_id . '.pdf');
    }

    private function forgetLabel(VentaOrder $order): void
    {
        $path = $this->labelPath($order);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * Re-read the order after a write. A failure here is logged and
     * swallowed: the booking itself succeeded, and the next scheduled pull
     * will bring the status across anyway.
     */
    private function refresh(VentaOrder $order): void
    {
        try {
            $fresh = (new VentaOrderSync($this->client, $this->setting))->pullOne((int) $order->venta_order_id);
            if ($fresh) {
                $order->setRawAttributes($fresh->getAttributes(), true);
            }
        } catch (\Throwable $e) {
            Log::warning('Venta order re-read after booking failed', [
                'order' => $order->venta_order_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Where the store put the order after a write, in the queue's own
     * words: which status, and which step that status sits in here. The
     * packer reads this on the row's reload, because "booked" and "moved
     * to To Handover" are two different facts and only the second one is
     * what they are watching for. A status the store placed somewhere
     * else is named, with where to fix it.
     */
    public function landing(VentaOrder $order): array
    {
        $status = trim((string) $order->status);
        $step = VentaStatusPlacements::stepFor((int) $this->setting->id, $status) ?? 'other';
        $label = FulfilmentSteps::label($step);
        $known = VentaStatusPlacements::known((int) $this->setting->id);

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
