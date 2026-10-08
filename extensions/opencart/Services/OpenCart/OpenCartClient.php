<?php

namespace Extensions\opencart\Services\OpenCart;

use Extensions\opencart\Models\OpenCartSetting;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Log;

class OpenCartClient
{
    private OpenCartSetting $setting;

    public function __construct(OpenCartSetting $setting)
    {
        $this->setting = $setting;
    }

    private function http(): PendingRequest
    {
        return \App\Support\Net\StoreRequest::to((string) $this->setting->base_url)->timeout(30)->connectTimeout(5)
            ->retry(2, 300)
            ->withHeaders([
                'X-ERP-API-Key' => $this->setting->api_key,
                'Accept'        => 'application/json',
            ]);
    }

    private function url(string $route, array $params = []): string
    {
        $base = rtrim($this->setting->base_url, '/');

        $params['route'] = $route;

        return $base . '/index.php?' . http_build_query($params);
    }

    public function get(string $route, array $params = []): array
    {
        try {
            $resp = $this->http()->get($this->url($route, $params));

            return [
                'status' => $resp->status(),
                'ok'     => $resp->ok(),
                'body'   => $resp->json() ?? [],
            ];
        } catch (\Throwable $e) {
            Log::error('OpenCart API GET failed', [
                'route' => $route,
                'error' => $e->getMessage(),
            ]);

            return [
                'status' => 0,
                'ok'     => false,
                'body'   => ['error' => $e->getMessage()],
            ];
        }
    }

    public function post(string $route, array $data = [], array $queryParams = []): array
    {
        try {
            $resp = $this->http()->asJson()->post($this->url($route, $queryParams), $data);

            return [
                'status' => $resp->status(),
                'ok'     => $resp->ok(),
                'body'   => $resp->json() ?? [],
            ];
        } catch (\Throwable $e) {
            Log::error('OpenCart API POST failed', [
                'route' => $route,
                'error' => $e->getMessage(),
            ]);

            return [
                'status' => 0,
                'ok'     => false,
                'body'   => ['error' => $e->getMessage()],
            ];
        }
    }

    public function ping(): array
    {
        return $this->get('api/erp/ping');
    }

    public function getProducts(int $page = 1, int $limit = 100, ?string $modifiedSince = null, ?int $productId = null): array
    {
        $params = ['page' => $page, 'limit' => $limit];
        if ($modifiedSince) {
            $params['modified_since'] = $modifiedSince;
        }
        if ($productId) {
            $params['product_id'] = $productId;
        }
        return $this->get('api/erp/products', $params);
    }

    public function getOrders(int $page = 1, int $limit = 100, ?string $modifiedSince = null, ?string $modifiedBefore = null): array
    {
        $params = ['page' => $page, 'limit' => $limit];
        if ($modifiedSince) {
            $params['modified_since'] = $modifiedSince;
        }
        if ($modifiedBefore) {
            $params['modified_before'] = $modifiedBefore;
        }
        return $this->get('api/erp/orders', $params);
    }

    public function getCategories(?string $modifiedSince = null): array
    {
        $params = [];
        if ($modifiedSince) {
            $params['modified_since'] = $modifiedSince;
        }
        return $this->get('api/erp/categories', $params);
    }

    public function getManufacturers(): array
    {
        return $this->get('api/erp/manufacturers');
    }

    public function getOptions(): array
    {
        return $this->get('api/erp/options');
    }

    public function createProduct(array $data): array
    {
        return $this->post('api/erp/product_create', $data);
    }

    public function updateProduct(int $productId, array $data): array
    {
        $data['product_id'] = $productId;
        return $this->post('api/erp/product_update', $data);
    }

    public function bulkUpdateProducts(array $products): array
    {
        return $this->post('api/erp/product_bulk_update', ['products' => $products]);
    }

    public function getOrderStatuses(): array
    {
        return $this->get('api/erp/order_statuses');
    }

    public function createReview(array $data): array
    {
        return $this->post('api/erp/review_create', $data);
    }
}
