<?php

namespace App\Services\Catalog;

use App\Services\ActivityLogger;
use App\Services\SkuSyncService;
use App\Services\VariationForgetService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class VariationEditor
{
    public function __construct(
        private readonly VariationSnapshot $snapshot,
        private readonly VariationWriter $writer,
    ) {
    }

    public function edit(int $productId, array $changes, array $additions, array $by = []): array
    {
        $wire = $this->snapshot->wire($productId);
        if ($wire === null) {
            throw new RuntimeException('This product has no variations. Giving it its first ones changes its stock, so it is done on the Edit page.');
        }

        $twoTypes = isset($wire['combinations']);
        $rowsKey = $twoTypes ? 'combinations' : 'values';
        $problems = [];
        $report = ['changed' => [], 'added' => [], 'notes' => []];

        $index = [];
        foreach ($wire[$rowsKey] as $i => $row) {
            if (trim((string) $row['sku']) !== '') {
                $index[strtolower(trim((string) $row['sku']))] = $i;
            }
        }
        $known = array_values(array_filter(array_map(fn ($r) => (string) $r['sku'], $wire[$rowsKey])));

        foreach ($changes as $n => $change) {
            $sku = strtolower(trim((string) ($change['sku'] ?? '')));
            if (! isset($index[$sku])) {
                $problems[] = 'Change ' . ($n + 1) . ': no variation of this product has SKU "' . ($change['sku'] ?? '') . '". Its variations: ' . implode(', ', $known) . '.';
                continue;
            }
            $i = $index[$sku];
            $before = $this->face($wire[$rowsKey][$i], $twoTypes);

            if (isset($change['new_sku']) && trim((string) $change['new_sku']) !== '') {
                $wire[$rowsKey][$i]['sku'] = trim((string) $change['new_sku']);
            }
            if (array_key_exists('enabled', $change) && $change['enabled'] !== null) {
                $wire[$rowsKey][$i]['status'] = (int) (bool) $change['enabled'];
            }
            if (isset($change['image']) && $change['image'] !== '') {
                $wire[$rowsKey][$i]['image'] = (string) $change['image'];
            }

            $after = $this->face($wire[$rowsKey][$i], $twoTypes);
            if ($after !== $before) {
                $report['changed'][] = ['sku' => $before['sku'], 'before' => $before, 'after' => $after];
            }
        }

        foreach ($additions as $n => $add) {
            $where = 'New variation ' . ($n + 1);
            $values = array_values(array_map(fn ($v) => trim((string) $v), (array) ($add['values'] ?? [])));
            $price = (float) ($add['price'] ?? 0);
            if (count($values) !== ($twoTypes ? 2 : 1) || in_array('', $values, true)) {
                $problems[] = $where . ': give ' . ($twoTypes ? 'two values, one ' . $wire['option1_name'] . ' and one ' . $wire['option2_name'] : 'one ' . $wire['option_name'] . ' value') . '.';
                continue;
            }
            if ($price <= 0) {
                $problems[] = $where . ': needs a price above zero.';
                continue;
            }
            $sku = trim((string) ($add['sku'] ?? ''));
            if ($sku === '') {
                $problems[] = $where . ': needs its own SKU.';
                continue;
            }

            $row = [
                'sku' => $sku,
                'quantity' => 0,
                'absolute_price' => $price,
                'cost_amount' => (float) ($add['cost_amount'] ?? 0),
                'cost_additional' => '',
                'image' => (string) ($add['image'] ?? ''),
                'status' => 1,
            ];

            if ($twoTypes) {
                [$v1, $v2] = $values;
                foreach ($wire['combinations'] as $c) {
                    if (strcasecmp($c['opt1'], $v1) === 0 && strcasecmp($c['opt2'], $v2) === 0) {
                        $problems[] = $where . ': ' . $v1 . ' / ' . $v2 . ' already exists, as ' . $c['sku'] . '.';
                        continue 2;
                    }
                }
                if (! $this->has($wire['option1_values'], $v1)) {
                    $wire['option1_values'][] = $v1;
                }
                if (! $this->has($wire['option2_values'], $v2)) {
                    $wire['option2_values'][] = $v2;
                }
                $wire['combinations'][] = ['opt1' => $v1, 'opt2' => $v2] + $row;
            } else {
                if ($this->has(array_column($wire['values'], 'name'), $values[0])) {
                    $problems[] = $where . ': ' . $values[0] . ' already exists.';
                    continue;
                }
                $wire['values'][] = ['name' => $values[0]] + $row;
            }
            $report['added'][] = ['values' => $values, 'sku' => $sku, 'price' => $price, 'stock' => 0];
        }

        if ($problems !== []) {
            throw new RuntimeException('Nothing was changed. ' . implode(' ', $problems));
        }

        if ($report['changed'] === [] && $report['added'] === []) {
            $report['notes'][] = 'Nothing was different from what the variations already hold, so nothing was written.';

            return $report;
        }

        $this->writer->validateSkus($productId, $wire + ['sku' => (string) DB::table(config('catalog.prefix') . 'product')->where('product_id', $productId)->value('sku')]);

        $before = VariationForgetService::snapshot($productId);
        $skuChanges = ['product_sku' => null, 'option_skus' => []];

        DB::transaction(function () use ($productId, $wire, &$skuChanges) {
            $this->writer->save($productId, $wire, $skuChanges);
            DB::table(config('catalog.prefix') . 'product')->where('product_id', $productId)->update(['date_modified' => now()]);
        });

        foreach ((new SkuSyncService())->syncSkuChanges($productId, $skuChanges) as $line) {
            $report['notes'][] = 'SKU synced: ' . $line;
        }
        (new VariationForgetService())->reconcile($productId, $before);

        if ($report['added'] !== []) {
            $report['notes'][] = 'A new variation starts with no stock; add stock with a stock adjustment.';
        }

        $name = (string) DB::table(config('catalog.prefix') . 'product_description')
            ->where('product_id', $productId)->where('language_id', (int) config('catalog.default_language_id'))->value('name');
        $what = count($report['changed']) . ' changed, ' . count($report['added']) . ' added';
        $label = trim((string) ($by['actor'] ?? '') . ' edited the variations of ' . $name . ' (' . $what . ')');
        if (isset($by['reason']) && trim((string) $by['reason']) !== '') {
            $label .= '. Reason: ' . trim((string) $by['reason']);
        }
        $logged = [];
        foreach ($report['changed'] as $c) {
            $logged[$c['sku']] = [json_encode($c['before']), json_encode($c['after'])];
        }
        foreach ($report['added'] as $a) {
            $logged[$a['sku']] = ['', 'added: ' . implode(' / ', $a['values'])];
        }
        ActivityLogger::log('updated', 'Product', $productId, $label, $logged);

        return $report;
    }

    private function face(array $row, bool $twoTypes): array
    {
        return [
            'sku' => (string) $row['sku'],
            'variation' => $twoTypes ? $row['opt1'] . ' / ' . $row['opt2'] : (string) $row['name'],
            'enabled' => (bool) $row['status'],
            'image' => (string) $row['image'],
        ];
    }

    private function has(array $names, string $name): bool
    {
        foreach ($names as $existing) {
            if (strcasecmp((string) $existing, $name) === 0) {
                return true;
            }
        }

        return false;
    }
}
