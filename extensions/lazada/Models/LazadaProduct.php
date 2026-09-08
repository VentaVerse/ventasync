<?php

namespace Extensions\lazada\Models;

use Illuminate\Database\Eloquent\Model;
use Extensions\lazada\Models\Concerns\BelongsToLazadaStore;

class LazadaProduct extends Model
{
    use BelongsToLazadaStore;

    // Lazada products table
    protected $table = 'lazada_products';

    protected $fillable = [
        'lazada_setting_id', 'product_id', 'item_name', 'description',
        'description_prefix_id', 'description_suffix_id',
        'primary_category_id', 'brand_id', 'brand_name_override',
        // The markups were missing here, and every create() that passed them
        // dropped them SILENTLY - the same disease 0af64cea documented on
        // the group dance's update(). Found again by #195's save-as pin.
        'markup_fixed', 'markup_percent',
        'total_price', 'lazada_item_id', 'unlinked_at', 'live_status', 'live_checked_at',
        'last_pushed_at', 'last_push_source', 'last_push_settings',
        'last_push_error', 'last_push_failed_at',
        'sale_percent', 'sale_starts_at', 'sale_ends_at', 'sale_pushed_at',
    ];

    protected $casts = [
        'unlinked_at' => 'datetime',
        'live_checked_at' => 'datetime',
        'last_pushed_at' => 'datetime',
        'last_push_failed_at' => 'datetime',
        'last_push_settings' => 'array',
        'sale_percent' => 'float',
        'sale_starts_at' => 'datetime',
        'sale_ends_at' => 'datetime',
        'sale_pushed_at' => 'datetime',
    ];

    /**
     * The mirrored bucket counts, keyed the way the listings strip names
     * them. Uploaded listings only: the tab table shows nothing else, so
     * the count must not promise more rows than the tab has.
     *
     * @param  array<string, string>  $tabMap  tab key => Lazada status word
     * @return array<string, int>
     */
    public static function mirrorCounts(array $tabMap): array
    {
        $byStatus = static::query()
            ->whereNotNull('live_status')
            ->whereNotNull('lazada_item_id')->where('lazada_item_id', '!=', '')
            ->selectRaw('live_status, COUNT(DISTINCT product_id) as n')
            ->groupBy('live_status')
            ->pluck('n', 'live_status');

        $out = [];
        foreach ($tabMap as $tabKey => $status) {
            $out[$tabKey] = (int) ($byStatus[$status] ?? 0);
        }

        return $out;
    }

    public function attributes()
    {
        return $this->hasMany(LazadaProductAttribute::class, 'lazada_product_id');
    }

    public function groups()
    {
        return $this->belongsToMany(LazadaProductGroup::class, 'lazada_product_group_products');
    }

    public function variants()
    {
        return $this->hasMany(LazadaProductVariant::class, 'lazada_product_id');
    }

    /**
     * One latest push error per product (the owner, 2026-09-05): whichever
     * surface pushed, the refusal lands here, the row every surface reads,
     * until a push succeeds. Store-scoped through the global scope.
     */
    public static function recordPushFailure(int $productId, string $reason): void
    {
        $reason = trim($reason) !== '' ? trim($reason) : 'No reason was recorded.';
        $values = ['last_push_error' => mb_substr($reason, 0, 480), 'last_push_failed_at' => now()];
        if (static::query()->where('product_id', $productId)->exists()) {
            static::query()->where('product_id', $productId)->update($values);
        } else {
            static::query()->create(['product_id' => $productId])->forceFill($values)->save();
        }
    }

    public static function clearPushFailure(int $productId): void
    {
        static::query()->where('product_id', $productId)
            ->whereNotNull('last_push_error')
            ->update(['last_push_error' => null, 'last_push_failed_at' => null]);
    }

    /**
     * What this listing holds of its own (App\Integrations\Listings\ListingContent):
     * a blank column means "follow the catalog product".
     *
     * @return array{title: ?string, description: ?string}
     */
    public function contentOverrides(): array
    {
        return ['title' => $this->item_name, 'description' => $this->description];
    }

}
