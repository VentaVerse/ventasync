<?php

namespace App\Console\Commands;

use App\Integrations\IntegrationRegistry;
use App\Services\Catalog\ProductDeleter;
use Illuminate\Console\Command;

/**
 * The one-time sweep behind the catalog delete reaching the channels
 * (the owner, 2026-09-05). Products deleted before that rule left listing
 * rows behind on every channel, some still live at the marketplace.
 * This names them, and with --apply removes them the way a delete does
 * now: marketplace first, local rows after, refusals kept and reported.
 */
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
            $this->line(count($ids) . ' stranded. Run again with --apply to remove them from the marketplaces and this system.');

            return self::SUCCESS;
        }

        $removed = 0;
        $refused = 0;
        foreach ($ids as $id) {
            $errors = [];
            foreach ($registry->productRemovers() as $remover) {
                try {
                    $r = $remover->removeProduct($id);
                } catch (\Throwable $e) {
                    $r = ['ok' => false, 'removed' => [], 'kept' => [], 'errors' => [$e->getMessage()]];
                }
                foreach ($r['removed'] as $line) {
                    $this->line("#{$id}: removed from {$line}");
                }
                foreach ($r['kept'] as $line) {
                    $this->line("#{$id}: {$line}");
                }
                if (!$r['ok']) {
                    array_push($errors, ...$r['errors']);
                }
            }
            if ($errors) {
                $refused++;
                $this->error("#{$id}: kept. " . implode(' ', $errors));
            } else {
                $removed++;
            }
        }
        $this->info("{$removed} purged, {$refused} kept for a retry.");

        return $refused > 0 ? self::FAILURE : self::SUCCESS;
    }
}
