<?php

namespace Extensions\ventacart\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VentaCartListing extends Model
{
    use \App\Integrations\Listings\KeepsItsOwnCopy;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';
    public const STATUS_MISSING = 'missing';

    protected $table = 'ventacart_listings';

    protected $fillable = [
                'image_off', 'video_path', 'video_off',
'ventacart_setting_id', 'product_id', 'ventacart_category_id',
        'description_prefix_id', 'description_suffix_id', 'watermark_template_id', 'watermark_all_images',
        'markup_percent', 'markup_fixed', 'price',
        'weight', 'package_length', 'package_width', 'package_height',
        'name', 'description', 'meta_title', 'meta_description', 'image_order',
        'live_status', 'live_checked_at', 'live_price', 'live_quantity',
        'last_pushed_at', 'last_push_source', 'last_push_settings',
    ];

    protected $casts = [
        'watermark_all_images' => 'boolean',
        'markup_percent' => 'float',
        'markup_fixed' => 'float',
        'price' => 'float',
        'weight' => 'float',
        'package_length' => 'integer',
        'package_width' => 'integer',
        'package_height' => 'integer',
        'live_price' => 'float',
        'live_quantity' => 'integer',
        'live_checked_at' => 'datetime',
        'last_pushed_at' => 'datetime',
        'last_push_settings' => 'array',
        'image_order' => 'array', 'image_off' => 'array',
        'catalog_seen_at' => 'datetime',
    ];

    public function setting(): BelongsTo
    {
        return $this->belongsTo(VentaCartSetting::class, 'ventacart_setting_id');
    }

    public function priceFor(float $startPrice): float
    {
        return round(
            $startPrice
            + ($startPrice * (float) ($this->markup_percent ?? 0) / 100)
            + (float) ($this->markup_fixed ?? 0),
            2
        );
    }

    public static function startFor(?self $listing, float $corePrice, bool $hasVariations): float
    {
        return ! $hasVariations && $listing && $listing->price !== null ? (float) $listing->price : $corePrice;
    }

    public static function withVariations(array $productIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $productIds)));
        if ($ids === []) {
            return [];
        }

        return array_values(array_unique(array_map('intval', array_merge(
            \Illuminate\Support\Facades\DB::table('product_option_combinations')->whereIn('product_id', $ids)->distinct()->pluck('product_id')->all(),
            \Illuminate\Support\Facades\DB::table((string) config('catalog.prefix') . 'product_option_value')->whereIn('product_id', $ids)->distinct()->pluck('product_id')->all(),
        ))));
    }

    public static function parcelFor(?self $listing, object $catalog): array
    {
        return [
            'weight' => (float) ($listing?->weight ?? $catalog->weight ?? 0),
            'length' => (float) ($listing?->package_length ?? $catalog->length ?? 0),
            'width' => (float) ($listing?->package_width ?? $catalog->width ?? 0),
            'height' => (float) ($listing?->package_height ?? $catalog->height ?? 0),
        ];
    }

    public function hasPriceRule(): bool
    {
        return $this->markup_percent !== null || $this->markup_fixed !== null;
    }

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

    public static function categoryFor(?self $listing, ?object $group): ?int
    {
        if ($listing && $listing->ventacart_category_id !== null) {
            return (int) $listing->ventacart_category_id;
        }

        return ((int) ($group->ventacart_category_id ?? 0)) ?: null;
    }

    public function overrides(): array
    {
        return array_filter([
            'name' => $this->name,
            'description' => $this->description,
            'meta_title' => $this->meta_title,
            'meta_description' => $this->meta_description,
        ], fn ($v) => $v !== null && trim((string) $v) !== '');
    }

    public function mirror(?string $status, ?float $price = null, ?int $quantity = null): void
    {
        $this->forceFill([
            'live_status' => $status,
            'live_checked_at' => now(),
            'live_price' => $price,
            'live_quantity' => $quantity,
        ])->save();
    }
    public function contentOverrides(): array
    {
        return ['title' => $this->name, 'description' => $this->description];
    }

    public static function copyColumns(): array
    {
        return ['title' => 'name', 'description' => 'description', 'images' => 'image_order', 'plain' => false];
    }

}
