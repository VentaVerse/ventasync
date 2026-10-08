<?php

namespace Extensions\ventacart\Services\VentaCart;

use Extensions\ventacart\Models\VentaCartApiLog;
use Extensions\ventacart\Models\VentaCartSetting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;

class VentaCartClient
{
    private VentaCartSetting $setting;

    public function __construct(VentaCartSetting $setting)
    {
        $this->setting = $setting;
    }

    private function http(): PendingRequest
    {
        return \App\Support\Net\StoreRequest::to((string) $this->setting->base_url)->timeout(30)->connectTimeout(5)
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

        $reqJson = $requestBody ? json_encode($requestBody) : null;
        $resJson = json_encode($responseBody);
        if ($reqJson && strlen($reqJson) > 10000) {
            $requestBody = ['_truncated' => true, '_size' => strlen($reqJson)];
        }
        if ($resJson && strlen($resJson) > 10000) {
            $responseBody = ['_truncated' => true, '_size' => strlen($resJson)];
        }

        VentaCartApiLog::safeCreate([
            'ventacart_setting_id' => $this->setting->id ?? null,
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
            Log::error('VentaCart API GET failed', [
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
            Log::error('VentaCart API POST failed', [
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
            Log::error('VentaCart API PUT failed', [
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
            Log::error('VentaCart API DELETE failed', [
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

    public function getVariants(string $productSku): array
    {
        return $this->get("products/{$productSku}/variants");
    }

    public function updateVariant(string $variantSku, array $data): array
    {
        return $this->put("variants/{$variantSku}", $data);
    }

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

    public function serviceability(int $orderId): array
    {
        return $this->get("orders/{$orderId}/serviceability");
    }

    public function pickupSlots(int $orderId, ?string $courier = null): array
    {
        return $this->get("orders/{$orderId}/pickup-slots", array_filter(['courier' => $courier]));
    }

    public function estimate(int $orderId, array $opts): array
    {
        return $this->post("orders/{$orderId}/estimate", $opts);
    }

    // Idempotent while a booking is active.
    public function bookCourier(int $orderId, array $opts): array
    {
        return $this->post("orders/{$orderId}/book-courier", $opts);
    }

    public function cancelBooking(int $orderId): array
    {
        return $this->post("orders/{$orderId}/cancel-booking");
    }

    public function pickupAddresses(): array
    {
        return $this->get('pickup-addresses');
    }

    public function shippingCouriers(): array
    {
        return $this->get('shipping-couriers', ['active_only' => 1]);
    }

    public function bookManual(int $orderId, array $opts): array
    {
        return $this->post("orders/{$orderId}/book-manual", $opts);
    }

    public function clearManual(int $orderId): array
    {
        return $this->post("orders/{$orderId}/clear-manual");
    }

    public function tracking(int $orderId): array
    {
        return $this->get("orders/{$orderId}/tracking");
    }

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
            Log::error('VentaCart API label failed', [
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

    public function pushStock(string $sku, int $quantity): array
    {
        return $this->put("products/{$sku}", ['quantity' => $quantity]);
    }

    public function pushVariantStock(string $variantSku, int $quantity): array
    {
        return $this->put("variants/{$variantSku}", ['quantity' => $quantity]);
    }

    public function createReview(array $data): array
    {
        return $this->post('reviews', $data);
    }

    public function ping(): array
    {
        return $this->get('categories', ['active_only' => 1]);
    }
}
