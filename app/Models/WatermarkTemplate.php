<?php

namespace App\Models;

use App\Services\Media\Watermarker;
use App\Support\Catalog\ProductImages;
use Illuminate\Database\Eloquent\Model;

/**
 * A named mark belonging to ONE STORE, chosen by that store's listings and
 * its product groups.
 *
 * It holds everything about the mark - which picture, where it sits, how
 * big and how strong - so choosing one is a single decision rather than
 * five fields repeated per listing. Which of a product's pictures get it is
 * the listing's choice, not the template's.
 *
 * The store owns the list, named by (integration, store_id), exactly as a
 * description template is. A mark carries a shop's words as much as its
 * logo, so it is no more portable between stores than a returns policy is,
 * and nothing here will hand one store's mark to another.
 *
 * The picture is a PATH into the shared image library, so one logo serves
 * every store that marks with it and replacing that file updates them all.
 */
class WatermarkTemplate extends Model
{
    protected $guarded = [];

    protected $casts = [
        'size_percent' => 'float',
        'offset_x_percent' => 'float',
        'offset_y_percent' => 'float',
        'opacity' => 'float',
    ];

    /** One store's templates, by name, for a picker. */
    public static function forStore(string $integration, int $storeId)
    {
        return static::ofStore($integration, $storeId)->orderBy('name')->get(['id', 'name', 'image_path']);
    }

    /** Everything one store holds, and nothing another store does. */
    public static function ofStore(string $integration, int $storeId)
    {
        return static::query()->where('integration', $integration)->where('store_id', $storeId);
    }

    /** The name of one of this store's templates, or null. */
    public static function nameOf(?int $id, string $integration, int $storeId): ?string
    {
        return ($id === null || $id <= 0)
            ? null
            : static::ofStore($integration, $storeId)->whereKey($id)->value('name');
    }

    /**
     * The template a listing actually uses: its own, else its group's,
     * else none. The same order every other setting on a listing keeps -
     * the price rule and the category both resolve this way, and a fourth
     * ordering for the same idea would be a thing to remember.
     *
     * An id belonging to another store resolves to none, so a listing can
     * never wear a mark its store does not hold.
     */
    public static function resolve(?int $listingTemplateId, ?int $groupTemplateId, string $integration, int $storeId): ?self
    {
        foreach ([$listingTemplateId, $groupTemplateId] as $id) {
            if ($id !== null && $id > 0 && ($row = static::ofStore($integration, $storeId)->find($id))) {
                return $row;
            }
        }

        return null;
    }

    /** A submitted template id, kept only when this store holds that template. */
    public static function idOrNull(mixed $id, string $integration, int $storeId): ?int
    {
        $id = (int) $id;

        return ($id > 0 && static::ofStore($integration, $storeId)->whereKey($id)->exists()) ? $id : null;
    }

    /** Where the mark lives on disk, for the stamper. */
    public function markPath(): string
    {
        return \Illuminate\Support\Facades\Storage::disk('public')->path(ltrim((string) $this->image_path, '/'));
    }

    /**
     * How see-through the mark is, as the form shows it: 0 is the mark as
     * drawn, higher lets more of the photograph through. Stored as opacity
     * because that is what the stamper multiplies by; shown as
     * transparency because that is what the seller is deciding (the owner,
     * 2026-09-11: "Is strength the transparency? Then rename it").
     */
    public function transparencyPercent(): int
    {
        return (int) round((1 - (float) ($this->opacity ?? 1)) * 100);
    }

    /** The opacity a transparency percent means, clamped to what the form offers. */
    public static function opacityFor(float $transparencyPercent): float
    {
        return round(1 - max(0.0, min((float) self::MAX_TRANSPARENCY, $transparencyPercent)) / 100, 3);
    }

    /**
     * Past this the mark is so faint it is not a mark, and at 100 it is
     * gone: a template that stamps nothing is a mistake the form should not
     * allow rather than one the push should discover.
     */
    public const MAX_TRANSPARENCY = 90;

    /** The mark as a URL, for a preview. */
    public function markUrl(): string
    {
        return ProductImages::url((string) $this->image_path);
    }

    /**
     * Stamp one picture with this template, or answer null to leave the
     * picture alone. Every reason to leave it alone is a reason not to
     * fail a push: see App\Services\Media\Watermarker.
     */
    public function stamp(string $imagePath): ?string
    {
        return app(Watermarker::class)->stamp(
            $imagePath,
            $this->markPath(),
            (string) $this->position,
            (float) $this->size_percent,
            (float) $this->opacity,
            (float) $this->offset_x_percent,
            (float) $this->offset_y_percent,
        );
    }
}
