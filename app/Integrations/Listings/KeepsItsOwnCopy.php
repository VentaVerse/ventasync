<?php

namespace App\Integrations\Listings;

/**
 * A channel's listing model: a new listing starts as a copy of its catalog
 * product (App\Integrations\Listings\CatalogCopy), however it is created.
 */
trait KeepsItsOwnCopy
{
    /**
     * Where this listing keeps its copy.
     *
     * @return array{title: string, description: string, images: string, plain: bool}
     */
    abstract public static function copyColumns(): array;

    public static function bootKeepsItsOwnCopy(): void
    {
        static::creating(function ($listing) {
            CatalogCopy::fill($listing, static::copyColumns());
        });
    }

    /** @return list<string>  the fields that differ, when this listing shows a Catalog change */
    public function catalogChange(): array
    {
        return CatalogCopy::pending([$this], $this->getTable(), static::copyColumns())[(int) $this->product_id] ?? [];
    }
}
