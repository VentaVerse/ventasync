<?php

namespace App\Integrations\Listings;

use App\Models\WatermarkTemplate;
use App\Services\Media\StampedImages;
use App\Support\Catalog\ProductImages;

/**
 * THE ARRANGEABLE "IMAGES THAT GO UP" CARD, READ AND WRITTEN IN ONE PLACE.
 *
 * The card itself has been shared since 2026-09-08
 * (partials/channel-images-card). What was not shared was the handful of
 * lines on either side of it: what a controller must hand the card, and
 * what it may believe when the card is submitted back. Shopee grew both
 * first, and copying them into four more controllers is how five channels
 * end up arranging pictures four different ways.
 *
 * So both halves live here, and every channel calls the same two methods.
 *
 * A listing keeps its own photos, copied from the catalog when it is made
 * (CatalogCopy). An empty arrangement sends the catalog's.
 */
final class ListingImages
{
    /**
     * What the card needs, ready to spread into a view's data.
     *
     * A refused save hands back what was ARRANGED rather than what is
     * stored, the same way a refused save hands back typed words; the
     * hidden input comes back as a JSON string, which is why old() is
     * decoded here rather than trusted as an array.
     *
     * The tiles show each picture as it goes up, marked by the listing's
     * watermark template (or its product group's), so what the card shows
     * is what the buyer sees; each tile still submits the unmarked path.
     *
     * @param  ?array<int, mixed>  $stored  the listing's own arrangement, or null to follow the catalog
     * @param  ?object  $listing  the listing row, for its watermark choice
     * @param  ?int  $groupTemplateId  the template the listing's product group lends
     * @return array<string, mixed>
     */
    public static function cardData(int $productId, ?array $stored, ?object $listing = null, ?int $groupTemplateId = null): array
    {
        $submitted = null;
        $old = old('image_order');
        if ($old !== null && trim((string) $old) !== '') {
            $decoded = json_decode((string) $old, true);
            $submitted = is_array($decoded) ? $decoded : null;
        }

        $refused = session()->hasOldInput();
        $templateId = ((int) ($refused ? old('watermark_template_id') : ($listing->watermark_template_id ?? 0))) ?: null;
        $allImages = $refused ? (bool) old('watermark_all_images') : (bool) ($listing->watermark_all_images ?? false);
        $groupTemplateId = ((int) $groupTemplateId) ?: null;

        $paths = ProductImages::paths($productId, $submitted ?? $stored);
        $marked = app(StampedImages::class)->paths($paths, WatermarkTemplate::resolve($templateId, $groupTemplateId), $allImages);

        return [
            'tiles' => array_map(fn (string $path, string $shown) => ['path' => $path, 'url' => ProductImages::url($shown)], $paths, $marked),
            'catalogTiles' => ProductImages::tiles($productId),
            'browseUrl' => route('products.images.browse'),
            'watermarkOptions' => WatermarkTemplate::forPicker(),
            'watermarkTemplateId' => $templateId,
            'watermarkAllImages' => $allImages,
            'watermarkLent' => $groupTemplateId ? WatermarkTemplate::query()->whereKey($groupTemplateId)->value('name') : null,
        ];
    }

    /**
     * A listing's watermark choice as submitted, ready to store.
     *
     * @return array{watermark_template_id: ?int, watermark_all_images: bool}
     */
    public static function submittedWatermark(\Illuminate\Http\Request $request): array
    {
        return [
            'watermark_template_id' => WatermarkTemplate::idOrNull($request->input('watermark_template_id')),
            'watermark_all_images' => $request->boolean('watermark_all_images'),
        ];
    }

    /**
     * What a submitted arrangement is worth.
     *
     * Every path is checked against the library on disk before it is
     * stored: a hidden input is client input, and this one ends up in a
     * file read at push time. An empty or unparseable list is null.
     *
     * @param  ?string  $json  the hidden input's value, verbatim
     * @return ?array<int, string>
     */
    public static function submitted(int $productId, ?string $json): ?array
    {
        $decoded = json_decode((string) $json, true);
        if (! is_array($decoded)) {
            return null;
        }

        $accepted = ProductImages::acceptable($decoded);

        return $accepted !== [] ? $accepted : null;
    }
}
