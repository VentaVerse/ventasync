<?php

namespace Extensions\OpenCart\Services\OpenCart;

use Extensions\OpenCart\Models\OpenCartStore;
use Extensions\OpenCart\Models\OpenCartSyncLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OpenCartVariationTypeSync
{
    private OpenCartClient $client;
    private OpenCartStore $store;

    private array $variationTypeMap = [];

    private array $variationValueMap = [];

    public function __construct(OpenCartClient $client, OpenCartStore $store)
    {
        $this->client = $client;
        $this->store = $store;
    }

    public function pull(): array
    {
        $log = OpenCartSyncLog::create([
            'store_id' => $this->store->id,
            'entity_type' => 'variation',
            'direction'   => 'pull',
            'status'      => 'started',
            'started_at'  => now(),
        ]);

        try {
            $result = $this->client->getOptions();

            if (!$result['ok']) {
                throw new \RuntimeException('API error: ' . json_encode($result['body']));
            }

            $options = $result['body']['data'] ?? [];
            $langId = (int) config('catalog.default_language_id');
            $created = 0;
            $updated = 0;
            $failed = 0;
            $errors = [];

            foreach ($options as $raw) {
                try {
                    $ocOptionId = (int) ($raw['option_id'] ?? 0);
                    if ($ocOptionId <= 0) continue;

                    $name = trim($raw['name'] ?? '');
                    if ($name === '') continue;

                    $existing = DB::table('variation_types AS o')
                        ->join('variation_type_descriptions AS od', function ($join) use ($langId) {
                            $join->on('o.variation_type_id', '=', 'od.variation_type_id')
                                ->where('od.language_id', '=', $langId);
                        })
                        ->where('od.name', $name)
                        ->select('o.variation_type_id')
                        ->first();

                    if ($existing) {
                        $coreVariationTypeId = (int) $existing->variation_type_id;
                        DB::table('variation_types')
                            ->where('variation_type_id', $coreVariationTypeId)
                            ->update([
                                'type'       => $raw['type'] ?? 'select',
                                'sort_order' => (int) ($raw['sort_order'] ?? 0),
                            ]);
                        $updated++;
                    } else {
                        $coreVariationTypeId = DB::table('variation_types')->insertGetId([
                            'type'       => $raw['type'] ?? 'select',
                            'sort_order' => (int) ($raw['sort_order'] ?? 0),
                        ], 'variation_type_id');

                        DB::table('variation_type_descriptions')->insert([
                            'variation_type_id'   => $coreVariationTypeId,
                            'language_id' => $langId,
                            'name'        => $name,
                        ]);
                        $created++;
                    }

                    $this->variationTypeMap[$ocOptionId] = $coreVariationTypeId;

                    $values = $raw['values'] ?? [];
                    foreach ($values as $val) {
                        $ocValueId = (int) ($val['option_value_id'] ?? 0);
                        if ($ocValueId <= 0) continue;

                        $valName = trim($val['name'] ?? '');
                        if ($valName === '') continue;

                        $existingVal = DB::table('variation_values AS ov')
                            ->join('variation_value_descriptions AS ovd', function ($join) use ($langId) {
                                $join->on('ov.variation_value_id', '=', 'ovd.variation_value_id')
                                    ->where('ovd.language_id', '=', $langId);
                            })
                            ->where('ov.variation_type_id', $coreVariationTypeId)
                            ->where('ovd.name', $valName)
                            ->select('ov.variation_value_id')
                            ->first();

                        if ($existingVal) {
                            $coreValueId = (int) $existingVal->variation_value_id;
                            DB::table('variation_values')
                                ->where('variation_value_id', $coreValueId)
                                ->update([
                                    'image'      => $val['image'] ?? '',
                                    'sort_order' => (int) ($val['sort_order'] ?? 0),
                                ]);
                        } else {
                            $coreValueId = DB::table('variation_values')->insertGetId([
                                'variation_type_id'  => $coreVariationTypeId,
                                'image'      => $val['image'] ?? '',
                                'sort_order' => (int) ($val['sort_order'] ?? 0),
                            ], 'variation_value_id');

                            DB::table('variation_value_descriptions')->insert([
                                'variation_value_id' => $coreValueId,
                                'language_id'     => $langId,
                                'variation_type_id'       => $coreVariationTypeId,
                                'name'            => $valName,
                            ]);
                        }

                        $this->variationValueMap[$ocValueId] = $coreValueId;
                    }
                } catch (\Throwable $e) {
                    $failed++;
                    $errors[] = [
                        'variation_type_id' => $raw['option_id'] ?? '?',
                        'error'     => $e->getMessage(),
                    ];
                }
            }

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
            Log::error('OpenCart variation sync failed', ['error' => $e->getMessage()]);
        }

        return [
            'log'            => $log,
            'variationTypeMap'      => $this->variationTypeMap,
            'variationValueMap' => $this->variationValueMap,
        ];
    }

    public function getVariationTypeMap(): array
    {
        return $this->variationTypeMap;
    }

    public function getVariationValueMap(): array
    {
        return $this->variationValueMap;
    }
}
