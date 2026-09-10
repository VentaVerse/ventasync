<?php

namespace Extensions\venta\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WHAT DOES THE STOREFRONT ACTUALLY CALL ITS FEES? Read only.
 *
 * The ERP maps a Venta order's payment and delivery fee onto the core
 * order so every report can read them, and it has to know the storefront's
 * own field names to do it. Rather than ask the owner to paste a payload and
 * guess from one example, this reads the payloads already stored on his
 * orders and prints every key that looks like money for a fee.
 *
 * It calls nothing and writes nothing: venta_orders.raw is the storefront's
 * own answer, kept from the last sync.
 *
 * A key printed here that is not in VentaExtension::feeBucketsForOrder is a
 * fee the reports are not counting.
 */
class VentaFeeKeys extends Command
{
    protected $signature = 'venta:fee-keys {--orders=25 : how many recent orders to read}';

    protected $description = 'Read-only: print the fee-shaped fields the Venta storefront actually sends, so the mapping can name them exactly';

    /** What a fee tends to be called, in any storefront. */
    private const LOOKS_LIKE = ['fee', 'charge', 'ship', 'deliver', 'payment', 'commission', 'cost', 'discount'];

    public function handle(): int
    {
        if (! Schema::hasTable('venta_orders')) {
            $this->error('No venta_orders table.');

            return self::FAILURE;
        }

        $rows = DB::table('venta_orders')->whereNotNull('raw')
            ->orderByDesc('id')->limit((int) $this->option('orders'))->pluck('raw');

        if ($rows->isEmpty()) {
            $this->error('No Venta orders stored yet. Sync some orders first.');

            return self::SUCCESS;
        }

        /* Every path, with an example of what was there. A key that appears
           on one order in twenty is still the key, so the count is printed
           beside it rather than the rarest being dropped. */
        $found = [];
        foreach ($rows as $raw) {
            $payload = is_string($raw) ? json_decode($raw, true) : (array) $raw;
            if (! is_array($payload)) {
                continue;
            }
            foreach ($this->flatten($payload) as $path => $value) {
                $leaf = strtolower((string) (explode('.', $path)[count(explode('.', $path)) - 1]));
                foreach (self::LOOKS_LIKE as $needle) {
                    if (str_contains($leaf, $needle)) {
                        $found[$path] ??= ['n' => 0, 'sample' => $value];
                        $found[$path]['n']++;
                        if (($found[$path]['sample'] === null || $found[$path]['sample'] === '' || (float) $found[$path]['sample'] == 0.0) && $value !== null) {
                            // Prefer an example that is not zero: a column of
                            // zeroes says nothing about what the field means.
                            $found[$path]['sample'] = $value;
                        }
                        break;
                    }
                }
            }
        }

        if ($found === []) {
            $this->line('Read ' . $rows->count() . ' orders and found no fee-shaped field at all.');
            $this->line('Either the storefront is not sending them yet, or these orders were synced');
            $this->line('before it started. A Sync orders run refreshes what is stored here.');

            return self::SUCCESS;
        }

        ksort($found);
        $this->line('Read ' . $rows->count() . ' recent orders. Fee-shaped fields the storefront sends:');
        $this->line('');
        foreach ($found as $path => $seen) {
            $sample = is_scalar($seen['sample']) ? (string) $seen['sample'] : json_encode($seen['sample']);
            $this->line(sprintf('  %-46s seen on %2d   e.g. %s', $path, $seen['n'], \Illuminate\Support\Str::limit((string) $sample, 40)));
        }
        $this->line('');
        $this->line('Paste this back and the mapping is pinned to the real names.');

        return self::SUCCESS;
    }

    /** @return array<string, mixed> path => value, scalars only */
    private function flatten(array $data, string $prefix = ''): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;
            if (is_array($value)) {
                // Lists of line items would drown the answer; their shape is
                // the same for every row, so only the first is walked.
                $out += $this->flatten(array_is_list($value) ? (array) ($value[0] ?? []) : $value, $path);
            } else {
                $out[$path] = $value;
            }
        }

        return $out;
    }
}
