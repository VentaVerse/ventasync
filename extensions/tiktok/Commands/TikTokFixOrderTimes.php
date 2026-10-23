<?php

namespace Extensions\TikTok\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class TikTokFixOrderTimes extends Command
{
    protected $signature = 'tiktok:fix-order-times {--apply : Write the corrected times}';

    protected $description = 'Correct TikTok Shop order and return times that were saved in UTC instead of the store timezone';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $orders = $returns = $sales = 0;

        DB::table('ch_tiktok_orders')->select(['id', 'order_id', 'sales_order_id', 'order_created_at', 'order_updated_at', 'raw'])
            ->orderBy('id')->chunkById(500, function ($rows) use ($apply, &$orders, &$sales) {
                foreach ($rows as $row) {
                    $raw = json_decode((string) $row->raw, true) ?: [];
                    $created = $this->at($raw['create_time'] ?? null);
                    $updated = $this->at($raw['update_time'] ?? null);
                    $change = array_filter([
                        'order_created_at' => $created !== null && $created !== $row->order_created_at ? $created : null,
                        'order_updated_at' => $updated !== null && $updated !== $row->order_updated_at ? $updated : null,
                    ]);
                    if (! $change) {
                        continue;
                    }
                    $orders++;

                    $sale = DB::table('orders')
                        ->when($row->sales_order_id, fn ($q) => $q->where('order_id', $row->sales_order_id),
                            fn ($q) => $q->where('channel', 'tiktok')->where('channel_order_id', (string) $row->order_id));
                    $saleChange = array_filter([
                        'date_added'    => isset($change['order_created_at']) ? [$row->order_created_at, $change['order_created_at']] : null,
                        'date_modified' => isset($change['order_updated_at']) ? [$row->order_updated_at, $change['order_updated_at']] : null,
                    ]);

                    if (! $apply) {
                        $sales += (clone $sale)->where(function ($q) use ($saleChange) {
                            foreach ($saleChange as $column => [$old]) {
                                $q->orWhere($column, $old);
                            }
                        })->count();

                        continue;
                    }

                    DB::transaction(function () use ($row, $change, $sale, $saleChange, &$sales) {
                        DB::table('ch_tiktok_orders')->where('id', $row->id)->update($change);
                        foreach ($saleChange as $column => [$old, $new]) {
                            $sales += (clone $sale)->where($column, $old)->update([$column => $new]);
                        }
                    });
                }
            });

        DB::table('ch_tiktok_returns')->select(['id', 'return_created_at', 'return_updated_at', 'raw'])
            ->orderBy('id')->chunkById(500, function ($rows) use ($apply, &$returns) {
                foreach ($rows as $row) {
                    $raw = json_decode((string) $row->raw, true) ?: [];
                    $created = $this->at($raw['create_time'] ?? null);
                    $updated = $this->at($raw['update_time'] ?? null);
                    $change = array_filter([
                        'return_created_at' => $created !== null && $created !== $row->return_created_at ? $created : null,
                        'return_updated_at' => $updated !== null && $updated !== $row->return_updated_at ? $updated : null,
                    ]);
                    if (! $change) {
                        continue;
                    }
                    $returns++;
                    if ($apply) {
                        DB::table('ch_tiktok_returns')->where('id', $row->id)->update($change);
                    }
                }
            });

        $verb = $apply ? 'Corrected' : 'Would correct';
        $this->info("{$verb} {$orders} TikTok orders, {$sales} sales order dates and {$returns} returns.");
        if (! $apply && ($orders || $returns)) {
            $this->line('Run again with --apply to write them.');
        }

        return self::SUCCESS;
    }

    private function at(mixed $timestamp): ?string
    {
        return is_numeric($timestamp) && (int) $timestamp > 0
            ? Carbon::createFromTimestamp((int) $timestamp, config('app.timezone'))->format('Y-m-d H:i:s')
            : null;
    }
}
