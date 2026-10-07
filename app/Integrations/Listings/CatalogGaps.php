<?php

namespace App\Integrations\Listings;

use Illuminate\Support\Facades\DB;

final class CatalogGaps
{
    public const ENABLED = 'enabled';
    public const NAME = 'name';
    public const DESCRIPTION = 'description';
    public const PRICE = 'price';
    public const SKU = 'sku';

    public const ANY_SKU = 'any_sku';
    public const IMAGE = 'image';
    public const PARCEL = 'parcel';

    public static function forProducts(array $productIds, array $checks, array $withOwnPictures = []): array
    {
        $ownPictures = array_flip(array_map('intval', $withOwnPictures));
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
                        ? ['code' => ListingState::GAP_DISABLED, 'label' => self::DISABLED_LABEL] : null,
                    self::NAME => (! $p || trim(html_entity_decode((string) ($p->name ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8')) === '')
                        ? ['code' => ListingState::GAP_NAME, 'label' => 'a name on the catalog product'] : null,
                    self::DESCRIPTION => (! $p || trim(strip_tags(html_entity_decode((string) ($p->description ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'))) === '')
                        ? ['code' => ListingState::GAP_DESCRIPTION, 'label' => 'a description on the catalog product'] : null,
                    self::PRICE => (! $p || (float) $p->price <= 0)
                        ? ['code' => ListingState::GAP_PRICE, 'label' => 'a price above zero on the catalog product'] : null,
                    self::SKU, self::ANY_SKU => (! $p || trim((string) ($p->sku ?? '')) === '')
                        ? ['code' => ListingState::GAP_SKU, 'label' => 'an SKU on the catalog product'] : null,
                    self::IMAGE => (! $p || (trim((string) $p->image) === '' && ! isset($ownPictures[$pid])))
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

    public const DISABLED_LABEL = 'product in catalog is disabled';

    public static function refusal(array $readiness, string $verb = 'push'): string
    {
        return 'Not ' . ($verb === 'update' ? 'updated' : 'pushed') . ': ' . self::stillNeeds($readiness['missing'] ?? []);
    }

    public static function stillNeeds(array $missing): string
    {
        if ($missing === []) {
            $missing = ['its readiness could not be checked'];
        }

        $rest = array_values(array_filter($missing, fn ($m) => $m !== self::DISABLED_LABEL));
        if (count($rest) < count($missing)) {
            return 'the ' . self::DISABLED_LABEL
                . ($rest === [] ? '.' : ', and this listing still needs ' . implode(', ', $rest) . '. Set what is missing and try again.');
        }

        return 'this listing still needs ' . implode(', ', $missing) . '. Set what is missing and try again.';
    }
}
