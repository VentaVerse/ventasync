<?php

namespace App\Console\Commands;

use App\Integrations\IntegrationRegistry;
use App\Services\Catalog\ProductDeleter;
use Illuminate\Console\Command;

class PurgeStrandedListings extends Command
{
    protected $signature = 'catalog:purge-stranded-listings {--apply : Remove them; without this the command only lists}';

    protected $description = 'Remove channel listings whose catalog product was deleted before deletes reached the channels';

    public function handle(IntegrationRegistry $registry, ProductDeleter $deleter): int
    {
        $ids = [];
        foreach ($registry->productRemovers() as $remover) {
            $ids = array_merge($ids, $remover->strandedProductIds());
        }
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);

        if ($ids === []) {
            $this->info('No stranded listings: every channel row belongs to a product that exists.');

            return self::SUCCESS;
        }

        $presence = $deleter->presence($ids);
        foreach ($ids as $id) {
            $where = $presence[$id] ?? [];
            $held = $where
                ? implode('; ', array_map(fn ($p) => $p['channel'] . ' (' . $p['store'] . ')' . ($p['item'] !== '' ? ' item ' . $p['item'] : '') . ($p['live'] === true ? ', live' : ($p['live'] === false ? ', gone' : '')), $where))
                : 'local rows only';
            $this->line("Deleted product #{$id}: still held on {$held}");
        }

        if (!$this->option('apply')) {
            $this->line(count($ids) . ' stranded. Run again with --apply to forget them here; the marketplace items stay where they are.');

            return self::SUCCESS;
        }

        $forgotten = 0;
        foreach ($ids as $id) {
            $stays = [];
            foreach ($registry->productRemovers() as $remover) {
                array_push($stays, ...$remover->removeProduct($id));
            }
            $stays = array_values(array_unique($stays));
            $this->line("#{$id}: forgotten" . ($stays ? '; still on ' . implode(', ', $stays) : ''));
            $forgotten++;
        }
        $this->info("{$forgotten} forgotten.");

        return $refused > 0 ? self::FAILURE : self::SUCCESS;
    }
}
