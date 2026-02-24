<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class OpenCartMigrateCosts extends Command
{
    protected $signature = 'opencart:migrate-costs
        {--dry-run : Preview counts without updating}';

    protected $description = 'Migrate shipping_cost, payment_cost, extra_cost from ADV Profit SQL dumps into ERP orders';

    /**
     * Store mapping: dump file => marketplace_source value.
     */
    private array $stores = [
        'shopa_order.sql'  => 'opencart:1',
        'shopb_order.sql' => 'opencart:2',
    ];

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $basePath = base_path('opencart_extensions/adv_profit');
        $pfx = (string) config('catalog.prefix');

        foreach ($this->stores as $file => $source) {
            $path = $basePath . '/' . $file;
            if (!file_exists($path)) {
                $this->warn("Skipping {$file} — file not found.");
                continue;
            }

            $this->info("Processing {$file} ({$source})...");
            $rows = $this->parseDump($path);
            $this->info("  Parsed " . count($rows) . " orders from dump.");

            $nonZero = collect($rows)->filter(fn ($r) =>
                $r['shipping_cost'] != 0 || $r['payment_cost'] != 0 || $r['extra_cost'] != 0
            );
            $this->info("  Orders with non-zero costs: " . $nonZero->count());

            if ($nonZero->isEmpty()) {
                $this->info("  Nothing to migrate.");
                continue;
            }

            $updated = 0;
            $skipped = 0;

            foreach ($nonZero->chunk(500) as $chunk) {
                $ocOrderIds = $chunk->pluck('order_id')->toArray();

                // Find matching ERP orders
                $erpOrders = DB::table($pfx . 'order')
                    ->where('marketplace_source', $source)
                    ->whereIn('marketplace_order_id', $ocOrderIds)
                    ->pluck('order_id', 'marketplace_order_id')
                    ->toArray();

                foreach ($chunk as $row) {
                    $ocId = (string) $row['order_id'];
                    if (!isset($erpOrders[$ocId])) {
                        $skipped++;
                        continue;
                    }

                    if ($dryRun) {
                        $updated++;
                        continue;
                    }

                    DB::table($pfx . 'order')
                        ->where('order_id', $erpOrders[$ocId])
                        ->update([
                            'shipping_cost' => $row['shipping_cost'],
                            'payment_cost'  => $row['payment_cost'],
                            'extra_cost'    => $row['extra_cost'],
                        ]);
                    $updated++;
                }
            }

            $label = $dryRun ? 'Would update' : 'Updated';
            $this->info("  {$label}: {$updated} orders, skipped (no ERP match): {$skipped}");
        }

        if ($dryRun) {
            $this->warn('Dry run — no changes written. Remove --dry-run to apply.');
        } else {
            $this->info('Done.');
        }

        return 0;
    }

    /**
     * Parse a phpMyAdmin SQL dump and extract order_id + cost fields.
     */
    private function parseDump(string $path): array
    {
        $handle = fopen($path, 'r');
        if (!$handle) {
            return [];
        }

        $rows = [];
        $columnIndices = null;

        while (($line = fgets($handle)) !== false) {
            $line = rtrim($line);

            // Detect INSERT INTO line to get column names
            if (str_starts_with($line, 'INSERT INTO')) {
                $columnIndices = $this->parseColumnIndices($line);
                // The VALUES part may start on the same line
                $valuesStart = strpos($line, 'VALUES');
                if ($valuesStart !== false) {
                    $valuesPart = substr($line, $valuesStart + 6);
                    $this->parseValueRows($valuesPart, $columnIndices, $rows);
                }
                continue;
            }

            // Continuation data rows (start with '(' — part of previous INSERT)
            if ($columnIndices !== null && isset($line[0]) && $line[0] === '(') {
                $this->parseValueRows($line, $columnIndices, $rows);
            }
        }

        fclose($handle);
        return $rows;
    }

    /**
     * Extract column name => index mapping from the INSERT INTO line.
     */
    private function parseColumnIndices(string $insertLine): ?array
    {
        // Extract the column list between first ( and ) before VALUES
        if (!preg_match('/\(([^)]+)\)\s*VALUES/i', $insertLine, $m)) {
            return null;
        }

        $cols = array_map(function ($c) {
            return trim(str_replace('`', '', trim($c)));
        }, explode(',', $m[1]));

        $needed = ['order_id', 'shipping_cost', 'payment_cost', 'extra_cost'];
        $indices = [];
        foreach ($needed as $col) {
            $idx = array_search($col, $cols);
            if ($idx === false) {
                return null;
            }
            $indices[$col] = $idx;
        }

        return $indices;
    }

    /**
     * Parse value rows from a line and append to $rows.
     */
    private function parseValueRows(string $line, ?array $indices, array &$rows): void
    {
        if (!$indices) {
            return;
        }

        // Split into individual row tuples: (...), (...)
        // We use a state machine to handle quoted strings with commas/parens
        $tuples = $this->splitTuples($line);

        foreach ($tuples as $tuple) {
            $values = $this->splitValues($tuple);
            if (!$values) {
                continue;
            }

            $orderId = $values[$indices['order_id']] ?? null;
            if ($orderId === null) {
                continue;
            }

            $rows[] = [
                'order_id'      => (int) $orderId,
                'shipping_cost' => (float) ($values[$indices['shipping_cost']] ?? 0),
                'payment_cost'  => (float) ($values[$indices['payment_cost']] ?? 0),
                'extra_cost'    => (float) ($values[$indices['extra_cost']] ?? 0),
            ];
        }
    }

    /**
     * Split a VALUES line into individual tuples.
     * Handles: (val,val,...),(val,val,...);
     */
    private function splitTuples(string $line): array
    {
        $tuples = [];
        $depth = 0;
        $current = '';
        $inQuote = false;
        $escape = false;

        for ($i = 0, $len = strlen($line); $i < $len; $i++) {
            $ch = $line[$i];

            if ($escape) {
                $current .= $ch;
                $escape = false;
                continue;
            }

            if ($ch === '\\') {
                $current .= $ch;
                $escape = true;
                continue;
            }

            if ($ch === "'") {
                $inQuote = !$inQuote;
                $current .= $ch;
                continue;
            }

            if ($inQuote) {
                $current .= $ch;
                continue;
            }

            if ($ch === '(') {
                $depth++;
                if ($depth === 1) {
                    $current = '';
                    continue;
                }
            } elseif ($ch === ')') {
                $depth--;
                if ($depth === 0) {
                    $tuples[] = $current;
                    $current = '';
                    continue;
                }
            }

            if ($depth > 0) {
                $current .= $ch;
            }
        }

        return $tuples;
    }

    /**
     * Split a single tuple's values (comma-separated, respecting quotes).
     */
    private function splitValues(string $tuple): array
    {
        $values = [];
        $current = '';
        $inQuote = false;
        $escape = false;

        for ($i = 0, $len = strlen($tuple); $i < $len; $i++) {
            $ch = $tuple[$i];

            if ($escape) {
                $current .= $ch;
                $escape = false;
                continue;
            }

            if ($ch === '\\') {
                $current .= $ch;
                $escape = true;
                continue;
            }

            if ($ch === "'") {
                $inQuote = !$inQuote;
                $current .= $ch;
                continue;
            }

            if (!$inQuote && $ch === ',') {
                $values[] = $this->cleanValue(trim($current));
                $current = '';
                continue;
            }

            $current .= $ch;
        }

        $values[] = $this->cleanValue(trim($current));
        return $values;
    }

    /**
     * Remove surrounding quotes from a SQL value.
     */
    private function cleanValue(string $val): string
    {
        if (strlen($val) >= 2 && $val[0] === "'" && $val[strlen($val) - 1] === "'") {
            return substr($val, 1, -1);
        }
        return $val;
    }
}
