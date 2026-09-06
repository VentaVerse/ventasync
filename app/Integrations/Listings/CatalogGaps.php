<?php

namespace App\Integrations\Listings;

use Illuminate\Support\Facades\DB;

/**
 * The catalog-level readiness gaps every channel shares (the owner, 2026-09-06:
 * Ready has to be absolutely sure). One query for a page of products, one
 * sentence per gap, the same words on every channel. A channel's readiness
 * engine asks for the checks its push actually runs and adds its own on top.
 *
 * A gap here mirrors a refusal the push code makes before sending: a
 * disabled product is never pushed anywhere; Shopee refuses an empty name
 * or description and a zero price; Lazada fills name and description from
 * the catalog and its template calls them mandatory; the parcel is what
 * Shopee refuses an item and an update without (#191).
 */
final class CatalogGaps
{
    public const ENABLED = 'enabled';
    public const NAME = 'name';
    public const DESCRIPTION = 'description';
    public const PRICE = 'price';
    public const SKU = 'sku';
    public const IMAGE = 'image';
    public const PARCEL = 'parcel';

    /**
     * @param list<int> $productIds
     * @param list<string> $checks  the self::* checks the channel's push refuses on
     * @return array<int, list<array{code: string, label: string}>> keyed by product id; a product that no longer exists carries every gap asked for
     */
    public static function forProducts(array $productIds, array $checks): array
    {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));
        if ($productIds === [] || $checks === []) {
            return array_fill_keys($productIds, []);
        }
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $rows = DB::table($pfx . 'product as p')
            ->leftJoin($pfx . 'product_description as pd', function ($j) use ($langId) {
                $j->on('p.product_id', '=', 'pd.product_id')->where('pd.language_id', '=', $langId);
            })
            ->whereIn('p.product_id', $productIds)
            ->get(['p.product_id', 'p.status', 'p.sku', 'p.model', 'p.image', 'p.price', 'p.weight', 'p.length', 'p.width', 'p.height', 'pd.name', 'pd.description'])
            ->keyBy('product_id');

        $out = [];
        foreach ($productIds as $pid) {
            $p = $rows->get($pid);
            $gaps = [];
            foreach ($checks as $check) {
                $gap = match ($check) {
                    self::ENABLED => (! $p || (int) $p->status !== 1)
                        ? ['code' => ListingState::GAP_DISABLED, 'label' => 'the catalog product enabled (it is disabled)'] : null,
                    self::NAME => (! $p || trim(html_entity_decode((string) ($p->name ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8')) === '')
                        ? ['code' => ListingState::GAP_NAME, 'label' => 'a name on the catalog product'] : null,
                    self::DESCRIPTION => (! $p || trim(strip_tags(html_entity_decode((string) ($p->description ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'))) === '')
                        ? ['code' => ListingState::GAP_DESCRIPTION, 'label' => 'a description on the catalog product'] : null,
                    self::PRICE => (! $p || (float) $p->price <= 0)
                        ? ['code' => ListingState::GAP_PRICE, 'label' => 'a price above zero on the catalog product'] : null,
                    self::SKU => (! $p || trim((string) ($p->sku ?: $p->model ?: '')) === '')
                        ? ['code' => ListingState::GAP_SKU, 'label' => 'a SKU on the catalog product'] : null,
                    self::IMAGE => (! $p || trim((string) $p->image) === '')
                        ? ['code' => ListingState::GAP_IMAGE, 'label' => 'a product image on the catalog product'] : null,
                    self::PARCEL => (! $p || (float) $p->weight <= 0 || (float) $p->length <= 0 || (float) $p->width <= 0 || (float) $p->height <= 0)
                        ? ['code' => ListingState::GAP_PARCEL, 'label' => 'a package weight and size (L×W×H) on the catalog product'] : null,
                    default => null,
                };
                if ($gap !== null) {
                    $gaps[] = $gap;
                }
            }
            $out[$pid] = $gaps;
        }

        return $out;
    }

    /** The sentence a push refuses with, from a readiness answer. */
    public static function refusal(array $readiness, string $verb = 'push'): string
    {
        $missing = $readiness['missing'] ?? [];
        if ($missing === []) {
            $missing = ['its readiness could not be checked'];
        }

        return 'Not ' . ($verb === 'update' ? 'updated' : 'pushed') . ': this listing still needs ' . implode(', ', $missing) . '. Set what is missing and try again.';
    }
}
