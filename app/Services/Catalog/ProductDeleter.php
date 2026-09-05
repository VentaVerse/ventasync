<?php

namespace App\Services\Catalog;

use App\Integrations\Contracts\ProductRemover;
use App\Integrations\IntegrationRegistry;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\DB;

/**
 * The one way a catalog product is deleted (the owner, 2026-09-05): channels
 * first, catalog last. Every channel that holds the product removes it,
 * marketplace and local rows alike; if any store refuses, the catalog
 * row stays and the refusal is the answer. See ProductRemover.
 */
final class ProductDeleter
{
    public function __construct(private readonly IntegrationRegistry $registry) {}

    /**
     * Where the products sit, merged across channels, keyed by product id.
     *
     * @param list<int> $productIds
     * @return array<int, list<array{channel:string, store:string, item:string, live:?bool, removable:bool}>>
     */
    public function presence(array $productIds): array
    {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));
        if ($productIds === []) {
            return [];
        }
        $out = [];
        foreach ($this->removers() as $remover) {
            foreach ($remover->productPresence($productIds) as $pid => $rows) {
                foreach ($rows as $row) {
                    $out[(int) $pid][] = $row;
                }
            }
        }

        return $out;
    }

    /**
     * The sentence a delete confirmation carries, or '' when the product
     * is on no channel: "Also deleted from Shopee (Main store) and Lazada
     * (PH). Stays on Shopify (Web) until you remove it there."
     *
     * @param list<array{channel:string, store:string, item:string, live:?bool, removable:bool}> $presence
     */
    public function note(array $presence): string
    {
        $deleted = [];
        $stays = [];
        foreach ($presence as $row) {
            $label = $row['channel'] . ' (' . $row['store'] . ')';
            if ($row['removable']) {
                $deleted[$label] = true;
            } else {
                $stays[$label] = true;
            }
        }
        $parts = [];
        if ($deleted) {
            $parts[] = 'Also deleted from ' . $this->join(array_keys($deleted)) . '.';
        }
        if ($stays) {
            $parts[] = 'Stays on ' . $this->join(array_keys($stays)) . ' until you remove it there.';
        }

        return implode(' ', $parts);
    }

    /**
     * One note per product id, '' where the product is on no channel.
     *
     * @param list<int> $productIds
     * @return array<int, string>
     */
    public function notes(array $productIds): array
    {
        $out = [];
        foreach ($this->presence($productIds) as $pid => $rows) {
            $out[$pid] = $this->note($rows);
        }

        return $out;
    }

    /**
     * Delete the products: channels first, catalog last, per product.
     *
     * @param list<int> $productIds
     * @return array{
     *   deleted: list<int>,
     *   refused: array<int, list<string>>,
     *   removed: list<string>,
     *   kept: list<string>,
     *   names: array<int, string>,
     * }
     */
    public function delete(array $productIds): array
    {
        $pfx = (string) config('catalog.prefix');
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds), fn ($v) => $v > 0)));
        $names = DB::table($pfx . 'product_description')
            ->whereIn('product_id', $productIds)
            ->where('language_id', (int) config('catalog.default_language_id'))
            ->pluck('name', 'product_id')
            ->map(fn ($n) => html_entity_decode((string) $n, ENT_QUOTES | ENT_HTML5, 'UTF-8'))
            ->all();

        $result = ['deleted' => [], 'refused' => [], 'removed' => [], 'kept' => [], 'names' => $names];

        foreach ($productIds as $id) {
            $errors = [];
            foreach ($this->removers() as $remover) {
                try {
                    $r = $remover->removeProduct($id);
                } catch (\Throwable $e) {
                    $r = ['ok' => false, 'removed' => [], 'kept' => [], 'errors' => [$this->channelName($remover) . ': ' . $e->getMessage()]];
                }
                array_push($result['removed'], ...$r['removed']);
                array_push($result['kept'], ...$r['kept']);
                if (!$r['ok']) {
                    array_push($errors, ...($r['errors'] ?: [$this->channelName($remover) . ' refused without saying why.']));
                }
            }
            if ($errors) {
                $result['refused'][$id] = $errors;
                continue;
            }

            DB::transaction(function () use ($id, $pfx) {
                DB::table($pfx . 'product_to_category')->where('product_id', $id)->delete();
                DB::table($pfx . 'product_description')->where('product_id', $id)->delete();
                DB::table($pfx . 'product')->where('product_id', $id)->delete();

                if (class_exists(\Extensions\warehousing\Models\WarehouseInventory::class)) {
                    \Extensions\warehousing\Models\WarehouseInventory::where('product_id', $id)->delete();
                }
            });

            ActivityLogger::log('deleted', 'Product', $id, $names[$id] ?? null);
            $result['deleted'][] = $id;
        }

        $result['removed'] = array_values(array_unique($result['removed']));
        $result['kept'] = array_values(array_unique($result['kept']));

        return $result;
    }

    /**
     * The flash a delete leaves behind, in words, and its tone.
     *
     * @return array{tone: string, text: string}
     */
    public function flash(array $result, int $asked): array
    {
        $deleted = count($result['deleted']);
        $refused = $result['refused'];
        $text = $asked === 1
            ? ($deleted === 1 ? 'Deleted.' : '')
            : ($deleted === $asked ? "Deleted {$deleted} products." : "Deleted {$deleted} of {$asked}.");
        if ($deleted > 0 && $result['removed']) {
            $text .= ' Also removed from ' . $this->join($result['removed']) . '.';
        }
        if ($deleted > 0 && $result['kept']) {
            $text .= ' ' . implode(' ', $result['kept']);
        }
        if ($refused) {
            $lines = [];
            foreach ($refused as $pid => $errors) {
                $name = $result['names'][$pid] ?? ('#' . $pid);
                $lines[] = $name . ': ' . implode(' ', $errors);
            }
            $text = trim($text . ' ' . ($asked === 1 ? 'Not deleted. ' : count($refused) . ' not deleted. ')
                . implode(' ', $lines) . ' Nothing of ' . (count($refused) === 1 ? 'it' : 'them') . ' was removed from the catalog. Fix that, then delete again.');
        }

        return ['tone' => $refused ? 'error' : 'status', 'text' => trim($text)];
    }

    /** @return list<ProductRemover> */
    private function removers(): array
    {
        return $this->registry->productRemovers();
    }

    private function channelName(ProductRemover $remover): string
    {
        if ($remover instanceof \App\Integrations\IntegrationProvider) {
            $cards = $remover->integrationCards();

            return (string) ((($cards[0] ?? null)?->name) ?: ucfirst($remover->integrationId()));
        }

        return 'A channel';
    }

    /** @param list<string> $items */
    private function join(array $items): string
    {
        $items = array_values($items);
        if (count($items) <= 1) {
            return (string) ($items[0] ?? '');
        }
        $last = array_pop($items);

        return implode(', ', $items) . ' and ' . $last;
    }
}
