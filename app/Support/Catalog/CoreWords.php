<?php

namespace App\Support\Catalog;

use Illuminate\Support\Facades\DB;

/**
 * The catalog's title and description for one product, shaped exactly as a
 * listing page pre-fills its Content boxes: the name as it reads, and the
 * description with its markup stripped.
 *
 * Every channel's save compares the submitted text against these, so text
 * the operator did not change is stored as null and the listing keeps
 * following the catalog. One shaping, so no two channels can disagree about
 * what "unchanged" means (the owner, 2026-09-07).
 */
class CoreWords
{
    /** @return array{title: string, description: string} */
    public static function of(int $productId): array
    {
        $pfx = (string) config('catalog.prefix');
        $row = DB::table($pfx . 'product as p')
            ->leftJoin($pfx . 'product_description as pd', function ($j) {
                $j->on('p.product_id', '=', 'pd.product_id')
                    ->where('pd.language_id', '=', (int) config('catalog.default_language_id'));
            })
            ->where('p.product_id', $productId)
            ->first(['pd.name', 'pd.description']);

        return [
            'title' => (string) ($row->name ?? ''),
            'description' => self::plain((string) ($row->description ?? '')),
        ];
    }

    /** The description as the box shows it: entities decoded, tags gone. */
    public static function plain(string $html): string
    {
        return trim(strip_tags(html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }
}
