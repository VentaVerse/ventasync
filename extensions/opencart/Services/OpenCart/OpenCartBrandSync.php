<?php

namespace Extensions\OpenCart\Services\OpenCart;

use Extensions\OpenCart\Models\OpenCartStore;
use Extensions\OpenCart\Models\OpenCartSyncLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OpenCartBrandSync
{
    private OpenCartClient $client;
    private OpenCartStore $store;

    private array $brandMap = [];

    public function __construct(OpenCartClient $client, OpenCartStore $store)
    {
        $this->client = $client;
        $this->store = $store;
    }

    public function pull(): array
    {
        $log = OpenCartSyncLog::create([
            'store_id' => $this->store->id,
            'entity_type' => 'brand',
            'direction'   => 'pull',
            'status'      => 'started',
            'started_at'  => now(),
        ]);

        try {
            $result = $this->client->getBrands();

            if (!$result['ok']) {
                throw new \RuntimeException('API error: ' . json_encode($result['body']));
            }

            $brands = $result['body']['data'] ?? [];
            $created = 0;
            $updated = 0;
            $failed = 0;
            $errors = [];

            foreach ($brands as $raw) {
                try {
                    $ocMfgId = (int) ($raw['manufacturer_id'] ?? 0);
                    if ($ocMfgId <= 0) continue;

                    $name = trim($raw['name'] ?? '');
                    if ($name === '') continue;

                    $existing = DB::table('brands')
                        ->where('name', $name)
                        ->select('brand_id')
                        ->first();

                    $data = [
                        'name'       => $name,
                        'image'      => $raw['image'] ?? '',
                        'sort_order' => (int) ($raw['sort_order'] ?? 0),
                    ];

                    if ($existing) {
                        $coreMfgId = (int) $existing->brand_id;
                        DB::table('brands')
                            ->where('brand_id', $coreMfgId)
                            ->update($data);
                        $updated++;
                    } else {
                        $coreMfgId = DB::table('brands')->insertGetId($data, 'brand_id');
                        $created++;
                    }

                    $this->brandMap[$ocMfgId] = $coreMfgId;
                } catch (\Throwable $e) {
                    $failed++;
                    $errors[] = [
                        'brand_id' => $raw['manufacturer_id'] ?? '?',
                        'error'           => $e->getMessage(),
                    ];
                }
            }

            $this->store->update(['last_brand_sync_at' => now()]);

            $log->update([
                'status'            => 'completed',
                'records_processed' => $created + $updated + $failed,
                'records_created'   => $created,
                'records_updated'   => $updated,
                'records_failed'    => $failed,
                'details'           => $errors ?: null,
                'completed_at'      => now(),
            ]);
        } catch (\Throwable $e) {
            $log->update([
                'status'        => 'failed',
                'error_message' => $e->getMessage(),
                'completed_at'  => now(),
            ]);
            Log::error('OpenCart brand sync failed', ['error' => $e->getMessage()]);
        }

        return ['log' => $log, 'map' => $this->brandMap];
    }

    public function getBrandMap(): array
    {
        return $this->brandMap;
    }
}
