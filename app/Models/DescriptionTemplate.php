<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A named block of a store's own words, put above or below a product's
 * description when it goes up (the owner, 2026-09-07). A returns policy, a
 * warranty note, a shipping line: written once, carried by every listing
 * that points at it, changed in one place.
 *
 * It belongs to a STORE, not to a channel: two Shopee stores keep their own.
 * The store is named by (integration, store_id), the pair the rest of the
 * core uses.
 */
class DescriptionTemplate extends Model
{
    protected $guarded = [];

    /** Every template of one store, newest name order, for a dropdown. */
    public static function forStore(string $integration, int $storeId)
    {
        return static::query()
            ->where('integration', $integration)
            ->where('store_id', $storeId)
            ->orderBy('name')
            ->get(['id', 'name', 'body']);
    }

    /**
     * The body of one template, or an empty string when the id is null,
     * unknown, or belongs to another store. A listing pointing at a deleted
     * template simply carries nothing, never another store's words.
     */
    public static function bodyOf(?int $id, string $integration, int $storeId): string
    {
        if ($id === null || $id <= 0) {
            return '';
        }
        $row = static::query()
            ->where('id', $id)->where('integration', $integration)->where('store_id', $storeId)
            ->first(['body']);

        return trim((string) ($row->body ?? ''));
    }
}
