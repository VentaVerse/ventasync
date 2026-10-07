<?php

namespace Extensions\shopee\Services\Shopee;

use App\Support\MarketplaceAnswer;
use Extensions\shopee\Controllers\ShopeeProductGroupController;
use Extensions\shopee\Models\ShopeeApiLog;
use Extensions\shopee\Models\ShopeeListing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class ShopeeItemCreate
{
    public function catalogImagePaths(object $product, ?ShopeeListing $listing = null): array
    {
        $paths = \App\Integrations\Listings\ListingImages::sending(
            \App\Support\Catalog\ProductImages::paths((int) $product->product_id, $listing?->image_order),
            $listing
        );

        return \App\Services\Media\ListingWatermark::paths($paths, $listing, \App\Services\Media\ListingWatermark::groupTemplateId('shopee_product_groups', 'shopee_product_group_products', 'shopee_product_group_id', (int) $product->product_id, 'shopee_setting_id', (int) ($listing?->shopee_setting_id ?? 0) ?: null), 'shopee', (int) ($listing?->shopee_setting_id ?? 0));
    }

    public function catalogImageCount(object $product, ?ShopeeListing $listing = null): int
    {
        return count(\App\Support\Catalog\ProductImages::paths((int) $product->product_id, $listing?->image_order));
    }

    public function resolveLocalImagePath(string $path): ?string
    {
        $try = [
            storage_path('app/public/' . $path),
            public_path('image/' . $path),
            public_path($path),
        ];
        foreach ($try as $p) {
            if (file_exists($p)) {
                return $p;
            }
        }

        return null;
    }

    public function uploadImages(ShopeeClient $client, array $auth, array $paths): array
    {
        $ids = [];
        foreach ($paths as $imgPath) {
            $localPath = $this->resolveLocalImagePath((string) $imgPath);
            if (!$localPath || !file_exists($localPath)) {
                continue;
            }

            $uploadResult = $this->uploadImage($client, $auth, $localPath);

            ShopeeApiLog::safeCreate([
                'pack' => 'shopee.products.upload_image',
                'method' => 'POST',
                'api_path' => '/api/v2/media_space/upload_image',
                'auth_required' => true,
                'request_params' => ['image' => $imgPath],
                'response_status' => $uploadResult['status'] ?? null,
                'ok' => (bool) ($uploadResult['ok'] ?? false),
                'response_body' => $uploadResult['body'] ?? null,
                'user_id' => auth()->id(),
            ]);

            if (!($uploadResult['ok'] ?? false)) {
                $msg = MarketplaceAnswer::plain('Shopee', $uploadResult);

                return ['ids' => $ids, 'error' => 'Image upload failed for "' . basename((string) $imgPath) . '": ' . $msg];
            }

            $imageId = $uploadResult['body']['response']['image_info']['image_id'] ?? null;
            if ($imageId) {
                $ids[] = (string) $imageId;
            }
        }

        return ['ids' => $ids, 'error' => null];
    }

    public function uploadImage(ShopeeClient $client, array $auth, string $localPath, string $scene = 'normal'): array
    {
        $localPath = $scene === 'desc'
            ? \App\Services\Media\StoreReadyImages::fitLocalFile($localPath, \App\Services\Media\StoreReadyImages::SHOPEE)
            : \App\Services\Media\ImageCache::pushLocalFile($localPath);
        $path = '/api/v2/media_space/upload_image';
        $timestamp = time();
        $sign = $client->signShop(
            (int) $auth['partner_id'], (string) $auth['partner_key'],
            $path, $timestamp, (string) $auth['access_token'], (int) $auth['shop_id']
        );

        $query = http_build_query([
            'partner_id' => (int) $auth['partner_id'], 'timestamp' => $timestamp,
            'sign' => $sign, 'access_token' => (string) $auth['access_token'],
            'shop_id' => (int) $auth['shop_id'],
        ]);

        $url = $client->baseUrl($auth['mode']) . $path . '?' . $query;

        $contents = file_get_contents($localPath);
        if ($contents === false) {
            return ['status' => 0, 'ok' => false, 'body' => ['error' => 'file_read_failed', 'message' => 'Could not read image file: ' . basename($localPath)]];
        }

        $response = Http::timeout(30)
            ->attach('image', $contents, basename($localPath))
            ->post($url, $scene === 'desc' ? ['scene' => 'desc'] : []);

        $body = $response->json();

        return [
            'status' => $response->status(),
            'ok' => $response->successful() && empty($body['error']),
            'body' => $body ?? $response->body(),
        ];
    }

    public function tierImageUploader(ShopeeClient $client, array $auth): \Closure
    {
        return function (string $path) use ($client, $auth): ?string {
            $localPath = $this->resolveLocalImagePath($path);
            if (!$localPath) {
                return null;
            }
            $result = $this->uploadImage($client, $auth, $localPath);

            return ($result['ok'] ?? false)
                ? ($result['body']['response']['image_info']['image_id'] ?? null)
                : null;
        };
    }

    public function payloadFromListing(ShopeeListing $listing, object $product, string $itemName, string $description, array $imageIds, array $attributeValues): array
    {
        $logisticInfo = [];
        foreach (($listing->logistic_ids ?? []) as $lid) {
            $logisticInfo[] = ['logistic_id' => (int) $lid, 'enabled' => true];
        }

        $content = \App\Integrations\Listings\ListingContent::of(
            $listing, $itemName, $description, 'shopee', (int) ($listing->shopee_setting_id ?? 0)
        );

        $payload = [
            'original_price' => $listing->itemPriceFor((float) $product->price, ShopeeListing::hasVariations((int) $product->product_id)),
            'description' => mb_substr(\App\Support\Catalog\DescriptionText::of($content['description']), 0, 5000),
            'item_name' => mb_substr($content['title'], 0, 255),
            'seller_stock' => [['stock' => max(0, (int) $product->quantity)]],
            'item_sku' => (string) ($product->sku ?? ''),
            'weight' => \App\Integrations\Listings\ParcelPrecision::of($listing->weight, $product->weight, \App\Integrations\Listings\ParcelPrecision::SHOPEE['weight']),
            'dimension' => [
                'package_length' => \App\Integrations\Listings\ParcelPrecision::of($listing->package_length, $product->length, \App\Integrations\Listings\ParcelPrecision::SHOPEE['dimension']),
                'package_width' => \App\Integrations\Listings\ParcelPrecision::of($listing->package_width, $product->width, \App\Integrations\Listings\ParcelPrecision::SHOPEE['dimension']),
                'package_height' => \App\Integrations\Listings\ParcelPrecision::of($listing->package_height, $product->height, \App\Integrations\Listings\ParcelPrecision::SHOPEE['dimension']),
            ],
            'category_id' => (int) $listing->shopee_category_id,
            'image' => ['image_id_list' => $imageIds],
            'logistic_info' => $logisticInfo,
            'brand' => app(ShopeeProductGroupController::class)
                ->buildBrandPayload((int) $listing->shopee_category_id, (int) ($listing->shopee_brand_id ?? 0)),
        ];

        $attributeList = app(ShopeeProductGroupController::class)
            ->attributeListFromSaved($attributeValues, (int) $listing->shopee_category_id);
        if (!empty($attributeList)) {
            $payload['attribute_list'] = $attributeList;
        }

        return $payload;
    }

    public function addItem(ShopeeClient $client, array $auth, array $payload, string $logPack): array
    {
        $path = '/api/v2/product/add_item';
        $result = $client->shopPost(
            $auth['mode'],
            (int) $auth['partner_id'], (string) $auth['partner_key'],
            (string) $auth['access_token'], (int) $auth['shop_id'],
            $path, [], $payload
        );

        $body = is_array($result['body'] ?? null) ? $result['body'] : [];
        $bodyError = trim((string) ($body['error'] ?? ''));
        $refused = ! ($result['ok'] ?? false) || $bodyError !== '';

        ShopeeApiLog::safeCreate([
            'pack' => $logPack, 'method' => 'POST', 'api_path' => $path,
            'auth_required' => true, 'request_params' => $payload,
            'response_status' => $result['status'] ?? null, 'ok' => ! $refused,
            'response_body' => $result['body'] ?? null, 'user_id' => auth()->id(),
        ]);

        if ($refused) {
            return ['ok' => false, 'item_id' => null, 'error' => MarketplaceAnswer::plain('Shopee', ['ok' => false, 'status' => $result['status'] ?? 200, 'body' => $body])];
        }

        $itemId = $body['response']['item_id'] ?? null;
        if ($itemId === null) {
            $said = trim((string) ($body['message'] ?? '')) ?: trim((string) ($body['warning'] ?? ''));

            return ['ok' => true, 'item_id' => null, 'error' => 'Shopee accepted the push but returned no item id' . ($said !== '' ? ' (it said: ' . $said . ')' : '') . '. Run Check against Shopee to adopt the item it created.'];
        }

        return ['ok' => true, 'item_id' => (int) $itemId, 'error' => null];
    }

    public function variationsRideOrUndo(ShopeeClient $client, array $auth, int $itemId, int $productId, callable $priceFor): array
    {
        $variations = app(ShopeeVariationPush::class)->pushForProduct(
            $client, $auth, $itemId, $productId, $priceFor,
            $this->tierImageUploader($client, $auth)
        );

        if ($variations !== null && !$variations['ok']) {
            $undone = app(ShopeeVariationPush::class)->undoCreate($client, $auth, $productId, $itemId);

            return ['ok' => false, 'models' => null, 'undone' => $undone, 'message' => (string) $variations['message']];
        }

        return ['ok' => true, 'models' => $variations !== null ? (int) $variations['models'] : null, 'undone' => false, 'message' => null];
    }
}
