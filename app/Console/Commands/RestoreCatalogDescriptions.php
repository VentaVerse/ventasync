<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A store listing whose own description is the catalog's words with only
 * the formatting lost. The listing pages loaded the catalog description with
 * its tags stripped, so a save kept that flattened copy as the listing's own
 * words, and every push after it sent the paragraphs run together. This
 * returns those listings to following the catalog. A description whose words
 * differ from the catalog's is an edit and is left alone.
 */
class RestoreCatalogDescriptions extends Command
{
    protected $signature = 'listings:restore-catalog-descriptions
        {--apply : Return the listings to the catalog description; without it the command only reports what it would change}';

    protected $description = "Return store listings whose own description is the catalog's words with the formatting lost to following the catalog";

    /** Each channel's listing table. */
    private const TABLES = ['shopee_listings', 'lazada_products', 'tiktok_listings', 'venta_listings', 'woocommerce_listings'];

    public function handle(): int
    {
        $pfx = (string) config('catalog.prefix');
        $lang = (int) config('catalog.default_language_id');
        $apply = (bool) $this->option('apply');
        $total = 0;

        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'description')) {
                continue;
            }

            $ids = [];
            $products = [];
            DB::table($table)->whereNotNull('description')->where('description', '!=', '')
                ->select(['id', 'product_id', 'description'])->orderBy('id')
                ->chunk(500, function ($rows) use (&$ids, &$products, $pfx, $lang) {
                    $catalog = DB::table($pfx . 'product_description')->where('language_id', $lang)
                        ->whereIn('product_id', $rows->pluck('product_id')->all())
                        ->pluck('description', 'product_id');
                    foreach ($rows as $row) {
                        $words = self::words((string) $catalog->get($row->product_id, ''));
                        if ($words !== '' && $words === self::words((string) $row->description)) {
                            $ids[] = (int) $row->id;
                            $products[(int) $row->product_id] = '#' . (int) $row->product_id;
                        }
                    }
                });

            if ($ids === []) {
                continue;
            }
            $total += count($ids);
            $list = implode(', ', $products);

            if ($apply) {
                foreach (array_chunk($ids, 500) as $chunk) {
                    DB::table($table)->whereIn('id', $chunk)->update(['description' => null]);
                }
                $this->info("{$table}: " . count($ids) . " listings follow the catalog description again: {$list}.");
            } else {
                $this->info("{$table}: " . count($ids) . " listings hold the catalog's words with the formatting lost: {$list}.");
            }
        }

        if ($total === 0) {
            $this->info('No listing holds a flattened copy of the catalog description.');

            return self::SUCCESS;
        }

        $this->info($apply
            ? 'Push these products again to send the formatted description.'
            : 'Run with --apply to return them to the catalog description.');

        return self::SUCCESS;
    }

    /** The words alone: tags, entities, spaces and line breaks removed. */
    private static function words(string $text): string
    {
        $plain = html_entity_decode(strip_tags(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return (string) preg_replace('/\s+/u', '', $plain);
    }
}
