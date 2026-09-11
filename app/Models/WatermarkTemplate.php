<?php

namespace App\Models;

use App\Services\Media\Watermarker;
use App\Support\Catalog\ProductImages;
use Illuminate\Database\Eloquent\Model;

/**
 * A named mark, defined once in the catalog and chosen by any listing or
 * any product group (the owner, 2026-09-10).
 *
 * It holds everything about the mark - which picture, where it sits, how
 * big, how strong, and whether the cover image gets it too - so choosing
 * one is a single decision rather than five fields repeated per store.
 *
 * The picture is a PATH into the image library, so replacing that file
 * updates every template pointing at it.
 */
class WatermarkTemplate extends Model
{
    protected $guarded = [];

    protected $casts = [
        'size_percent' => 'float',
        'offset_x_percent' => 'float',
        'offset_y_percent' => 'float',
        'opacity' => 'float',
        'stamp_main' => 'boolean',
    ];

    /** Every template, by name, for a picker. */
    public static function forPicker()
    {
        return static::query()->orderBy('name')->get(['id', 'name', 'image_path', 'stamp_main']);
    }

    /**
     * The template a listing actually uses: its own, else its group's,
     * else none. The same order every other setting on a listing keeps -
     * the price rule and the category both resolve this way, and a fourth
     * ordering for the same idea would be a thing to remember.
     */
    public static function resolve(?int $listingTemplateId, ?int $groupTemplateId): ?self
    {
        foreach ([$listingTemplateId, $groupTemplateId] as $id) {
            if ($id !== null && $id > 0 && ($row = static::query()->find($id))) {
                return $row;
            }
        }

        return null;
    }

    /** Where the mark lives on disk, for the stamper. */
    public function markPath(): string
    {
        return public_path('storage/' . ltrim((string) $this->image_path, '/'));
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
