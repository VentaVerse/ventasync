<?php

namespace Extensions\opencart\Services\OpenCart;

use Extensions\opencart\Models\OpenCartSetting;
use Extensions\opencart\Models\OpenCartSyncLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OpenCartManufacturerSync
{
    private OpenCartClient $client;
    private OpenCartSetting $setting;

    private array $manufacturerMap = [];

    public function __construct(OpenCartClient $client, OpenCartSetting $setting)
    {
        $this->client = $client;
        $this->setting = $setting;
    }

    public function pull(): array
    {
        $log = OpenCartSyncLog::create([
            'opencart_setting_id' => $this->setting->id,
            'entity_type' => 'manufacturer',
            'direction'   => 'pull',
            'status'      => 'started',
            'started_at'  => now(),
        ]);

        try {
            $result = $this->client->getManufacturers();

            if (!$result['ok']) {
                throw new \RuntimeException('API error: ' . json_encode($result['body']));
            }

            $manufacturers = $result['body']['data'] ?? [];
            $pfx = (string) config('catalog.prefix');
            $created = 0;
            $updated = 0;
            $failed = 0;
            $errors = [];

            foreach ($manufacturers as $raw) {
                try {
                    $ocMfgId = (int) ($raw['manufacturer_id'] ?? 0);
                    if ($ocMfgId <= 0) continue;

                    $name = trim($raw['name'] ?? '');
                    if ($name === '') continue;

                    $existing = DB::table($pfx . 'manufacturer')
                        ->where('name', $name)
                        ->select('manufacturer_id')
                        ->first();

                    $data = [
                        'name'       => $name,
                        'image'      => $raw['image'] ?? '',
                        'sort_order' => (int) ($raw['sort_order'] ?? 0),
                    ];

                    if ($existing) {
                        $coreMfgId = (int) $existing->manufacturer_id;
                        DB::table($pfx . 'manufacturer')
                            ->where('manufacturer_id', $coreMfgId)
                            ->update($data);
                        $updated++;
                    } else {
                        $coreMfgId = DB::table($pfx . 'manufacturer')->insertGetId($data, 'manufacturer_id');
                        $created++;
                    }

                    $this->manufacturerMap[$ocMfgId] = $coreMfgId;
                } catch (\Throwable $e) {
                    $failed++;
                    $errors[] = [
                        'manufacturer_id' => $raw['manufacturer_id'] ?? '?',
                        'error'           => $e->getMessage(),
                    ];
                }
            }

            $this->setting->update(['last_manufacturer_sync_at' => now()]);

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
            Log::error('OpenCart manufacturer sync failed', ['error' => $e->getMessage()]);
        }

        return ['log' => $log, 'map' => $this->manufacturerMap];
    }

    public function getManufacturerMap(): array
    {
        return $this->manufacturerMap;
    }
}
