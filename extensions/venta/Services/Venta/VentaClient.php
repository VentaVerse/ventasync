<?php

namespace Extensions\venta\Services\Venta;

use Extensions\venta\Models\VentaApiLog;
use Extensions\venta\Models\VentaSetting;
use Illuminate\Http\Client\PendingRequest;
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
        return Http::timeout(30)
            ->retry(2, 300)
            ->withToken($this->setting->api_token)
            ->acceptJson();
    }

    private function url(string $path): string
    {
        return rtrim($this->setting->base_url, '/') . '/api/v1/' . ltrim($path, '/');
    }

    private function logCall(string $method, string $path, ?array $requestBody, array $result, int $durationMs): void
    {
        if ($this->setting && !$this->setting->api_logging) {
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

    public function getOrders(int $perPage = 20, ?string $since = null, ?int $statusId = null): array
    {
        $params = ['per_page' => $perPage];
        if ($since) $params['since'] = $since;
        if ($statusId) $params['status_id'] = $statusId;
        return $this->get('orders', $params);
    }

    public function getOrder(int $orderId): array
    {
        return $this->get("orders/{$orderId}");
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
