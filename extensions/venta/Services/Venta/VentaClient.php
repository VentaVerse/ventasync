<?php

namespace Extensions\venta\Services\Venta;

use Extensions\venta\Models\VentaApiLog;
use Extensions\venta\Models\VentaSetting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class VentaClient
{
    private VentaSetting $setting;

    public function __construct(VentaSetting $setting)
    {
        $this->setting = $setting;
    }

    private function http(): PendingRequest
    {
        // Retry only what a retry can fix: a dropped connection or the
        // storefront falling over. A 4xx is an answer ("courier unavailable",
        // "not serviceable", "no booking to print") and is handed back as
        // the response it is, body intact, rather than thrown away as an
        // exception whose message is a status code. The refusal's own
        // sentence is what the packer needs to read.
        return Http::timeout(30)->connectTimeout(5)
            ->retry(2, 300, function ($exception) {
                return $exception instanceof ConnectionException
                    || ($exception instanceof RequestException && $exception->response->status() >= 500);
            }, throw: false)
            ->withToken($this->setting->api_token)
            ->acceptJson();
    }

    private function url(string $path): string
    {
        return rtrim($this->setting->base_url, '/') . '/api/v1/' . ltrim($path, '/');
    }

    private function logCall(string $method, string $path, ?array $requestBody, array $result, int $durationMs): void
    {
        if (! \App\Support\ApiLogMode::shouldLog($this->setting?->api_log_mode, (bool) ($result['ok'] ?? false))) {
            return;
        }

        $responseBody = $result['body'] ?? [];

        // Truncate large payloads to prevent DB bloat
        $reqJson = $requestBody ? json_encode($requestBody) : null;
        $resJson = json_encode($responseBody);
        if ($reqJson && strlen($reqJson) > 10000) {
            $requestBody = ['_truncated' => true, '_size' => strlen($reqJson)];
        }
        if ($resJson && strlen($resJson) > 10000) {
            $responseBody = ['_truncated' => true, '_size' => strlen($resJson)];
        }

        VentaApiLog::safeCreate([
            'venta_setting_id' => $this->setting->id ?? null,
            'method'           => $method,
            'endpoint'         => $path,
            'status_code'      => $result['status'] ?? 0,
            'response_time_ms' => $durationMs,
            'request_body'     => $requestBody,
            'response_body'    => $responseBody,
            'ok'               => $result['ok'] ?? false,
        ]);
    }

    public function get(string $path, array $params = []): array
    {
        $start = microtime(true);
        try {
            $resp = $this->http()->get($this->url($path), $params);

            $result = [
                'status' => $resp->status(),
                'ok'     => $resp->successful(),
                'body'   => $resp->json() ?? [],
            ];
        } catch (\Throwable $e) {
            Log::error('Venta API GET failed', [
                'path'  => $path,
                'store' => $this->setting->store_name,
                'error' => $e->getMessage(),
            ]);

            $result = [
                'status' => 0,
                'ok'     => false,
                'body'   => ['error' => $e->getMessage()],
            ];
        }

        $durationMs = (int) ((microtime(true) - $start) * 1000);
        $this->logCall('GET', $path . ($params ? '?' . http_build_query($params) : ''), null, $result, $durationMs);

        return $result;
    }

    public function post(string $path, array $data = []): array
    {
        $start = microtime(true);
        try {
            $resp = $this->http()->asJson()->post($this->url($path), $data);

            $result = [
                'status' => $resp->status(),
                'ok'     => $resp->successful(),
                'body'   => $resp->json() ?? [],
            ];
        } catch (\Throwable $e) {
            Log::error('Venta API POST failed', [
                'path'  => $path,
                'store' => $this->setting->store_name,
                'error' => $e->getMessage(),
            ]);

            $result = [
                'status' => 0,
                'ok'     => false,
                'body'   => ['error' => $e->getMessage()],
            ];
        }

        $durationMs = (int) ((microtime(true) - $start) * 1000);
        $this->logCall('POST', $path, $data, $result, $durationMs);

        return $result;
    }

    public function put(string $path, array $data = []): array
    {
        $start = microtime(true);
        try {
            $resp = $this->http()->asJson()->put($this->url($path), $data);

            $result = [
                'status' => $resp->status(),
                'ok'     => $resp->successful(),
                'body'   => $resp->json() ?? [],
            ];
        } catch (\Throwable $e) {
            Log::error('Venta API PUT failed', [
                'path'  => $path,
                'store' => $this->setting->store_name,
                'error' => $e->getMessage(),
            ]);

            $result = [
                'status' => 0,
                'ok'     => false,
                'body'   => ['error' => $e->getMessage()],
            ];
        }

        $durationMs = (int) ((microtime(true) - $start) * 1000);
        $this->logCall('PUT', $path, $data, $result, $durationMs);

        return $result;
    }

    public function delete(string $path): array
    {
        $start = microtime(true);
        try {
            $resp = $this->http()->delete($this->url($path));

            $result = [
                'status' => $resp->status(),
                'ok'     => $resp->successful(),
                'body'   => $resp->json() ?? [],
            ];
        } catch (\Throwable $e) {
            Log::error('Venta API DELETE failed', [
                'path'  => $path,
                'store' => $this->setting->store_name,
                'error' => $e->getMessage(),
            ]);

            $result = [
                'status' => 0,
                'ok'     => false,
                'body'   => ['error' => $e->getMessage()],
            ];
        }

        $durationMs = (int) ((microtime(true) - $start) * 1000);
        $this->logCall('DELETE', $path, null, $result, $durationMs);

        return $result;
    }

    // ── Convenience: Catalog ─────────────────────────────────

    public function getCategories(bool $activeOnly = false): array
    {
        $params = $activeOnly ? ['active_only' => 1] : [];
        return $this->get('categories', $params);
    }

    public function getBrands(bool $activeOnly = false): array
    {
        $params = $activeOnly ? ['active_only' => 1] : [];
        return $this->get('brands', $params);
    }

    // ── Convenience: Products ────────────────────────────────

    public function getProducts(int $perPage = 50, ?string $updatedSince = null, ?string $sku = null): array
    {
        $params = ['per_page' => $perPage];
        if ($updatedSince) $params['updated_since'] = $updatedSince;
        if ($sku) $params['sku'] = $sku;
        return $this->get('products', $params);
    }

    public function getProduct(string $sku): array
    {
        return $this->get("products/{$sku}");
    }

    public function createProduct(array $data): array
    {
        return $this->post('products', $data);
    }

    public function updateProduct(string $sku, array $data): array
    {
        return $this->put("products/{$sku}", $data);
    }

    // ── Convenience: Variants ────────────────────────────────

    public function getVariants(string $productSku): array
    {
        return $this->get("products/{$productSku}/variants");
    }

    public function updateVariant(string $variantSku, array $data): array
    {
        return $this->put("variants/{$variantSku}", $data);
    }

    // ── Convenience: Orders ──────────────────────────────────

    public function getOrders(int $perPage = 20, ?string $since = null, ?int $statusId = null, int $page = 1): array
    {
        $params = ['per_page' => $perPage, 'page' => max(1, $page)];
        if ($since) $params['since'] = $since;
        if ($statusId) $params['status_id'] = $statusId;
        return $this->get('orders', $params);
    }

    public function getOrder(int $orderId): array
    {
        return $this->get("orders/{$orderId}");
    }

    // ── Courier fulfilment (#168) ───────────────────────────────────────
    //
    // The storefront owns the courier integration and runs the booking
    // through the same code its own queue uses; these are the ERP-facing
    // endpoints its courier-fulfillment extension publishes. The order id
    // throughout is the STOREFRONT's order id (venta_orders.venta_order_id),
    // never the ERP row id.

    /** Every configured courier's answer to "can you carry this parcel". */
    public function serviceability(int $orderId): array
    {
        return $this->get("orders/{$orderId}/serviceability");
    }

    /** Pickup slots a courier offers for this order; empty for couriers without slot booking. */
    public function pickupSlots(int $orderId, ?string $courier = null): array
    {
        return $this->get("orders/{$orderId}/pickup-slots", array_filter(['courier' => $courier]));
    }

    /** A fee quote for a booking about to be made. Creates nothing. */
    public function estimate(int $orderId, array $opts): array
    {
        return $this->post("orders/{$orderId}/estimate", $opts);
    }

    /** Book the parcel with a courier. Idempotent while a booking is active. */
    public function bookCourier(int $orderId, array $opts): array
    {
        return $this->post("orders/{$orderId}/book-courier", $opts);
    }

    public function cancelBooking(int $orderId): array
    {
        return $this->post("orders/{$orderId}/cancel-booking");
    }

    /** Where a parcel can leave from: the store's saved pickup addresses. */
    public function pickupAddresses(): array
    {
        return $this->get('pickup-addresses');
    }

    /** The store's own list of couriers a parcel can be handed to by hand. */
    public function shippingCouriers(): array
    {
        return $this->get('shipping-couriers', ['active_only' => 1]);
    }

    /**
     * Record a shipment with a courier the store does not integrate with:
     * a name off the store's list and a tracking number, no API call to
     * any courier, no waybill. The store moves the order the way its own
     * "Record shipment" form does.
     */
    public function bookManual(int $orderId, array $opts): array
    {
        return $this->post("orders/{$orderId}/book-manual", $opts);
    }

    public function clearManual(int $orderId): array
    {
        return $this->post("orders/{$orderId}/clear-manual");
    }

    /** Live tracking; the storefront refreshes its own record on the way through. */
    public function tracking(int $orderId): array
    {
        return $this->get("orders/{$orderId}/tracking");
    }

    /**
     * The shipping label. The storefront answers one of two ways: the PDF
     * itself when it has cached the courier's perishable link, or JSON
     * carrying the courier's own URL. Both are returned as they are, with
     * the PDF bytes under 'pdf' so a caller can tell which it got, and the
     * bytes are never written to the API log.
     *
     * @return array{ok: bool, status: int, body: array, pdf: ?string}
     */
    public function label(int $orderId, string $format = 'zebra'): array
    {
        $path = "orders/{$orderId}/label";
        $start = microtime(true);

        try {
            $resp = $this->http()
                ->withHeaders(['Accept' => 'application/pdf, application/json'])
                ->get($this->url($path), ['format' => $format]);

            $isPdf = str_contains((string) $resp->header('Content-Type'), 'pdf');

            $result = [
                'status' => $resp->status(),
                'ok'     => $resp->successful(),
                'body'   => $isPdf ? ['pdf' => true, 'bytes' => strlen($resp->body())] : ($resp->json() ?? []),
                'pdf'    => $isPdf && $resp->successful() ? $resp->body() : null,
            ];
        } catch (\Throwable $e) {
            Log::error('Venta API label failed', [
                'path'  => $path,
                'store' => $this->setting->store_name,
                'error' => $e->getMessage(),
            ]);

            $result = ['status' => 0, 'ok' => false, 'body' => ['error' => $e->getMessage()], 'pdf' => null];
        }

        $durationMs = (int) ((microtime(true) - $start) * 1000);
        $this->logCall('GET', $path . '?format=' . $format, null, $result, $durationMs);

        return $result;
    }

    public function updateOrderStatus(int $orderId, int $statusId, ?string $comment = null): array
    {
        $data = ['status_id' => $statusId];
        if ($comment) $data['comment'] = $comment;
        return $this->put("orders/{$orderId}/status", $data);
    }

    // ── Convenience: Stock ───────────────────────────────────

    public function pushStock(string $sku, int $quantity): array
    {
        return $this->put("products/{$sku}", ['quantity' => $quantity]);
    }

    public function pushVariantStock(string $variantSku, int $quantity): array
    {
        return $this->put("variants/{$variantSku}", ['quantity' => $quantity]);
    }

    // ── Convenience: Reviews ────────────────────────────────

    public function createReview(array $data): array
    {
        return $this->post('reviews', $data);
    }

    // ── Connectivity ─────────────────────────────────────────

    public function ping(): array
    {
        return $this->get('categories', ['active_only' => 1]);
    }
}
