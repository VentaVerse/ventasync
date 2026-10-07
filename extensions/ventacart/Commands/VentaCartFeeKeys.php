<?php

namespace Extensions\ventacart\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class VentaCartFeeKeys extends Command
{
    protected $signature = 'ventacart:fee-keys {--orders=25 : how many recent orders to read}';

    protected $description = 'Read-only: print the fee-shaped fields the VentaCart storefront actually sends, so the mapping can name them exactly';

    private const LOOKS_LIKE = ['fee', 'charge', 'ship', 'deliver', 'payment', 'commission', 'cost', 'discount'];

    public function handle(): int
    {
        if (! Schema::hasTable('ventacart_orders')) {
            $this->error('No ventacart_orders table.');

            return self::FAILURE;
        }

        $rows = DB::table('ventacart_orders')->whereNotNull('raw')
            ->orderByDesc('id')->limit((int) $this->option('orders'))->pluck('raw');

        if ($rows->isEmpty()) {
            $this->error('No VentaCart orders stored yet. Sync some orders first.');

            return self::SUCCESS;
        }

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

    private function flatten(array $data, string $prefix = ''): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;
            if (is_array($value)) {
                $out += $this->flatten(array_is_list($value) ? (array) ($value[0] ?? []) : $value, $path);
            } else {
                $out[$path] = $value;
            }
        }

        return $out;
    }
}
