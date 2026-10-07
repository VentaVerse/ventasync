<?php

namespace App\Integrations\Listings;

trait KeepsItsOwnCopy
{
    abstract public static function copyColumns(): array;

    public static function bootKeepsItsOwnCopy(): void
    {
        static::creating(function ($listing) {
            CatalogCopy::fill($listing, static::copyColumns());
        });
    }

    public function catalogChange(): array
    {
        return CatalogCopy::pending([$this], $this->getTable(), static::copyColumns())[(int) $this->product_id] ?? [];
    }
}
