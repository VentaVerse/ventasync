<?php

namespace Extensions\opencart\Services\OpenCart;

use Extensions\opencart\Models\OpenCartSetting;
use Extensions\opencart\Models\OpenCartSyncLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OpenCartOptionSync
{
    private OpenCartClient $client;
    private OpenCartSetting $setting;

    private array $optionMap = [];

    private array $optionValueMap = [];

    public function __construct(OpenCartClient $client, OpenCartSetting $setting)
    {
        $this->client = $client;
        $this->setting = $setting;
    }

    public function pull(): array
    {
        $log = OpenCartSyncLog::create([
            'opencart_setting_id' => $this->setting->id,
            'entity_type' => 'option',
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
            $pfx = (string) config('catalog.prefix');
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

                    $existing = DB::table($pfx . 'option AS o')
                        ->join($pfx . 'option_description AS od', function ($join) use ($langId) {
                            $join->on('o.option_id', '=', 'od.option_id')
                                ->where('od.language_id', '=', $langId);
                        })
                        ->where('od.name', $name)
                        ->select('o.option_id')
                        ->first();

                    if ($existing) {
                        $coreOptionId = (int) $existing->option_id;
                        DB::table($pfx . 'option')
                            ->where('option_id', $coreOptionId)
                            ->update([
                                'type'       => $raw['type'] ?? 'select',
                                'sort_order' => (int) ($raw['sort_order'] ?? 0),
                            ]);
                        $updated++;
                    } else {
                        $coreOptionId = DB::table($pfx . 'option')->insertGetId([
                            'type'       => $raw['type'] ?? 'select',
                            'sort_order' => (int) ($raw['sort_order'] ?? 0),
                        ], 'option_id');

                        DB::table($pfx . 'option_description')->insert([
                            'option_id'   => $coreOptionId,
                            'language_id' => $langId,
                            'name'        => $name,
                        ]);
                        $created++;
                    }

                    $this->optionMap[$ocOptionId] = $coreOptionId;

                    $values = $raw['values'] ?? [];
                    foreach ($values as $val) {
                        $ocValueId = (int) ($val['option_value_id'] ?? 0);
                        if ($ocValueId <= 0) continue;

                        $valName = trim($val['name'] ?? '');
                        if ($valName === '') continue;

                        $existingVal = DB::table($pfx . 'option_value AS ov')
                            ->join($pfx . 'option_value_description AS ovd', function ($join) use ($langId) {
                                $join->on('ov.option_value_id', '=', 'ovd.option_value_id')
                                    ->where('ovd.language_id', '=', $langId);
                            })
                            ->where('ov.option_id', $coreOptionId)
                            ->where('ovd.name', $valName)
                            ->select('ov.option_value_id')
                            ->first();

                        if ($existingVal) {
                            $coreValueId = (int) $existingVal->option_value_id;
                            DB::table($pfx . 'option_value')
                                ->where('option_value_id', $coreValueId)
                                ->update([
                                    'image'      => $val['image'] ?? '',
                                    'sort_order' => (int) ($val['sort_order'] ?? 0),
                                ]);
                        } else {
                            $coreValueId = DB::table($pfx . 'option_value')->insertGetId([
                                'option_id'  => $coreOptionId,
                                'image'      => $val['image'] ?? '',
                                'sort_order' => (int) ($val['sort_order'] ?? 0),
                            ], 'option_value_id');

                            DB::table($pfx . 'option_value_description')->insert([
                                'option_value_id' => $coreValueId,
                                'language_id'     => $langId,
                                'option_id'       => $coreOptionId,
                                'name'            => $valName,
                            ]);
                        }

                        $this->optionValueMap[$ocValueId] = $coreValueId;
                    }
                } catch (\Throwable $e) {
                    $failed++;
                    $errors[] = [
                        'option_id' => $raw['option_id'] ?? '?',
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
            Log::error('OpenCart option sync failed', ['error' => $e->getMessage()]);
        }

        return [
            'log'            => $log,
            'optionMap'      => $this->optionMap,
            'optionValueMap' => $this->optionValueMap,
        ];
    }

    public function getOptionMap(): array
    {
        return $this->optionMap;
    }

    public function getOptionValueMap(): array
    {
        return $this->optionValueMap;
    }
}
