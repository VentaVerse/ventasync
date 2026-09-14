<?php

namespace Extensions\shopee\Services\Shopee;

use App\Support\MarketplaceAnswer;
use Extensions\shopee\Controllers\ShopeeProductGroupController;
use Extensions\shopee\Models\ShopeeApiLog;
use Extensions\shopee\Models\ShopeeListing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * The shared machinery of putting an item ON Shopee (#184 decomposition).
 * Three surfaces create items - the one-click push (rows and the listing
 * editor since the 2026-09-03 one-editor merge, when the push form died), the
 * index bulk, the group send - and until now each carried its own copy of
 * the image upload loop, the add_item call, and the variations-or-undo
 * tail; ShopeeProductController and ShopeeProductGroupController even held
 * duplicate private uploadImage/resolveLocalImagePath helpers. A fix to
 * one copy (the half-listed undo of #192, the plain-error mandate) had to
 * be re-made three more times or silently miss a surface.
 *
 * The controllers keep what is genuinely theirs: validation, which
 * settings price the push, provenance stamps, and the wording of their
 * flashes. Everything a copy could drift on lives here once.
 */
class ShopeeItemCreate
{
    /** Shopee takes at most this many images on an item. */
    public const MAX_IMAGES = 9;

    /**
     * The pictures that go up, capped at Shopee's nine (the owner, 2026-09-05:
     * a product with more was refused with "The number of product images
     * must be between 1 and 9").
     *
     * The listing's own arrangement when it has one, the catalog product's
     * own images when it does not - one reader for both, so the "Images
     * that go up" card and the push can never disagree. Before this, three
     * copies of the same query lived here and in the group controller, and
     * two of them forgot the cap.
     */
    public function catalogImagePaths(object $product, ?ShopeeListing $listing = null): array
    {
        $paths = array_slice(
            \App\Support\Catalog\ProductImages::paths((int) $product->product_id, $listing?->image_order),
            0,
            self::MAX_IMAGES
        );

        return \App\Services\Media\ListingWatermark::paths($paths, $listing, \App\Services\Media\ListingWatermark::groupTemplateId('shopee_product_groups', 'shopee_product_group_products', 'shopee_product_group_id', (int) $product->product_id, 'shopee_setting_id', (int) ($listing?->shopee_setting_id ?? 0) ?: null));
    }

    /** How many pictures this listing has, before the cap. */
    public function catalogImageCount(object $product, ?ShopeeListing $listing = null): int
    {
        return count(\App\Support\Catalog\ProductImages::paths((int) $product->product_id, $listing?->image_order));
    }

    /**
     * The sentence a push carries when there are more pictures than Shopee
     * takes (the owner, 2026-09-05: not an error, the push goes, but say so).
     * Null when nothing was left behind.
     */
    public function imageNote(object $product, ?ShopeeListing $listing = null): ?string
    {
        $count = $this->catalogImageCount($product, $listing);
        if ($count <= self::MAX_IMAGES) {
            return null;
        }

        return 'Shopee takes at most ' . self::MAX_IMAGES . ' images, so the first ' . self::MAX_IMAGES . ' of its ' . $count . ' went up.';
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

    /**
     * Upload every resolvable path, logging each call (the bulk and group
     * loops used to skip the log, so a failed batch upload left no
     * evidence). Stops at the first refusal.
     *
     * @return array{ids: list<string>, error: ?string}
     */
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

    public function uploadImage(ShopeeClient $client, array $auth, string $localPath): array
    {
        // Shopee refuses a picture over its size or in another format; product and variation pictures all come through here.
        $localPath = \App\Services\Media\StoreReadyImages::fitLocalFile($localPath, \App\Services\Media\StoreReadyImages::SHOPEE);
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
            ->post($url);

        $body = $response->json();

        /* Stricter than the two controller copies this replaces: Shopee
         * answers HTTP 200 with an error in the body, and the old
         * $response->ok() counted that as a success whose missing
         * image_id was then skipped without a word. */
        return [
            'status' => $response->status(),
            'ok' => $response->successful() && empty($body['error']),
            'body' => $body ?? $response->body(),
        ];
    }

    /** The uploader ShopeeVariationPush borrows for tier images. */
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

    /**
     * The add_item payload every listing-settings push sends (the row
     * push and the index bulk built this block char-for-char twice):
     * content the operator wrote on the listing wins, the catalog fills
     * the rest, SKU is always the catalog's (reconcile matches by it).
     */
    public function payloadFromListing(ShopeeListing $listing, object $product, string $itemName, string $description, array $imageIds, array $attributeValues): array
    {
        $logisticInfo = [];
        foreach (($listing->logistic_ids ?? []) as $lid) {
            $logisticInfo[] = ['logistic_id' => (int) $lid, 'enabled' => true];
        }

        /* The one reader (2026-09-07): the listing's own words win, and the
         * store's prefix and suffix templates wrap whichever won. */
        $content = \App\Integrations\Listings\ListingContent::of(
            $listing, $itemName, $description, 'shopee', (int) ($listing->shopee_setting_id ?? 0)
        );

        $payload = [
            'original_price' => $listing->itemPriceFor((float) $product->price, ShopeeListing::hasVariations((int) $product->product_id)),
            // Shopee accepts plain text only; description templates may contain HTML.
            'description' => mb_substr(\App\Support\Catalog\DescriptionText::of($content['description']), 0, 5000),
            'item_name' => mb_substr($content['title'], 0, 255),
            'seller_stock' => [['stock' => max(0, (int) $product->quantity)]],
            'item_sku' => (string) ($product->sku ?? ''),
            'weight' => (float) ($listing->weight ?? $product->weight),
            'dimension' => [
                'package_length' => (int) ($listing->package_length ?? max(1, (int) $product->length)),
                'package_width' => (int) ($listing->package_width ?? max(1, (int) $product->width)),
                'package_height' => (int) ($listing->package_height ?? max(1, (int) $product->height)),
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

    /**
     * One add_item call: sent, logged under the caller's pack, answered
     * in plain words. item_id can be null on an accepted call - Shopee
     * does that - and the caller owns what to tell the operator then.
     *
     * @return array{ok: bool, item_id: ?int, error: ?string}
     */
    public function addItem(ShopeeClient $client, array $auth, array $payload, string $logPack): array
    {
        $path = '/api/v2/product/add_item';
        $result = $client->shopPost(
            $auth['mode'],
            (int) $auth['partner_id'], (string) $auth['partner_key'],
            (string) $auth['access_token'], (int) $auth['shop_id'],
            $path, [], $payload
        );

        /* Shopee answers HTTP 200 with the refusal in the body (error beside
         * an often-empty message). Reading only the HTTP status counted such
         * a push a success, found no item_id, told the operator "Shopee
         * accepted the push but returned no item id" and wrote ok=1 to the
         * API log, so nothing looked wrong anywhere (the owner, 2026-09-06: two
         * of thirty Lazada-imported products, no error logged). The same
         * class of bug uploadImages() had. A body error is a refusal. */
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
            // Accepted with no item: say whatever Shopee did say, so the API
            // log and the page agree on the reason.
            $said = trim((string) ($body['message'] ?? '')) ?: trim((string) ($body['warning'] ?? ''));

            return ['ok' => true, 'item_id' => null, 'error' => 'Shopee accepted the push but returned no item id' . ($said !== '' ? ' (it said: ' . $said . ')' : '') . '. Run Check against Shopee to adopt the item it created.'];
        }

        return ['ok' => true, 'item_id' => (int) $itemId, 'error' => null];
    }

    /**
     * The #192 tail: variations ride the freshly created item, and a
     * refusal takes the item down again - half-listed is worse than
     * unlisted (the owner's #2062).
     *
     * @return array{ok: bool, models: ?int, undone: bool, message: ?string}
     */
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
