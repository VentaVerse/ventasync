<?php

namespace Extensions\venta\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One product's record on one Venta store (#171): its price rule, its
 * content on that store, the status mirror, and the provenance of the
 * last push from the listing page. See the venta_listings migration for
 * the contract and what is deliberately not stored here.
 */
class VentaListing extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';
    public const STATUS_MISSING = 'missing';

    protected $table = 'venta_listings';

    protected $fillable = [
        'venta_setting_id', 'product_id',
        'description_prefix_id', 'description_suffix_id', 'watermark_template_id', 'watermark_all_images',
        'markup_percent', 'markup_fixed',
        'name', 'description', 'meta_title', 'meta_description', 'image_order',
        'live_status', 'live_checked_at', 'live_price', 'live_quantity',
        'last_pushed_at', 'last_push_source', 'last_push_settings',
    ];

    protected $casts = [
        'watermark_all_images' => 'boolean',
        'markup_percent' => 'float',
        'markup_fixed' => 'float',
        'live_price' => 'float',
        'live_quantity' => 'integer',
        'live_checked_at' => 'datetime',
        'last_pushed_at' => 'datetime',
        'last_push_settings' => 'array',
        'image_order' => 'array',
    ];

    public function setting(): BelongsTo
    {
        return $this->belongsTo(VentaSetting::class, 'venta_setting_id');
    }

    /** The price this listing sells at, from the core price it never stores. */
    public function priceFor(float $corePrice): float
    {
        return round(
            $corePrice
            + ($corePrice * (float) ($this->markup_percent ?? 0) / 100)
            + (float) ($this->markup_fixed ?? 0),
            2
        );
    }

    public function hasPriceRule(): bool
    {
        return $this->markup_percent !== null || $this->markup_fixed !== null;
    }

    /**
     * The price every push sends: the listing's own rule, else its group's
     * markup, else the catalog price.
     *
     * @return \Closure(float): float
     */
    public static function ruleFor(?self $listing, ?object $group): \Closure
    {
        return function (float $base) use ($listing, $group): float {
            if ($listing && $listing->hasPriceRule()) {
                return $listing->priceFor($base);
            }
            if ($group && ($group->markup_percent !== null || $group->markup_fixed !== null)) {
                return round($base + ($base * (float) ($group->markup_percent ?? 0) / 100) + (float) ($group->markup_fixed ?? 0), 2);
            }

            return round($base, 2);
        };
    }

    /**
     * The content this listing sends in place of the catalogue's. Only the
     * fields the operator set; an unset one is not in the array at all, so
     * the catalogue's value goes.
     *
     * @return array<string, string>
     */
    public function overrides(): array
    {
        return array_filter([
            'name' => $this->name,
            'description' => $this->description,
            'meta_title' => $this->meta_title,
            'meta_description' => $this->meta_description,
        ], fn ($v) => $v !== null && trim((string) $v) !== '');
    }

    /** The mirror, written through by a push, a toggle, or the page's own read. */
    public function mirror(?string $status, ?float $price = null, ?int $quantity = null): void
    {
        $this->forceFill([
            'live_status' => $status,
            'live_checked_at' => now(),
            'live_price' => $price,
            'live_quantity' => $quantity,
        ])->save();
    }
    /**
     * The title and description half of overrides(), in the shape every
     * channel answers in (App\Integrations\Listings\ListingContent).
     *
     * @return array{title: ?string, description: ?string}
     */
    public function contentOverrides(): array
    {
        return ['title' => $this->name, 'description' => $this->description];
    }

}
