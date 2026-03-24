<?php

namespace Extensions\venta\Services\Venta;

use Extensions\venta\Models\VentaSetting;
use Extensions\venta\Models\VentaSyncLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class VentaCategorySync
{
    private VentaClient $client;
    private VentaSetting $setting;

    public function __construct(VentaClient $client, VentaSetting $setting)
    {
        $this->client = $client;
        $this->setting = $setting;
    }

    /**
     * Pull categories from Venta and log the sync.
     * Categories are primarily used for product group matching — they are stored
     * in the venta_order_status_map pattern but can be expanded later.
     */
    public function pull(): VentaSyncLog
    {
        $log = VentaSyncLog::create([
            'venta_setting_id' => $this->setting->id,
            'entity_type'      => 'category',
            'direction'        => 'pull',
            'status'           => 'started',
            'started_at'       => now(),
        ]);

        try {
            $result = $this->client->getCategories(false);

            if (!$result['ok']) {
                throw new \RuntimeException('API error: ' . json_encode($result['body']));
            }

            $categories = $result['body'];
            if (!is_array($categories)) {
                $categories = [];
            }

            $log->update([
                'status'            => 'completed',
                'records_processed' => count($categories),
                'details'           => ['categories' => array_map(fn ($c) => [
                    'id'   => $c['id'] ?? null,
                    'name' => $c['name'] ?? null,
                ], $categories)],
                'completed_at'      => now(),
            ]);

            $this->setting->update(['last_category_sync_at' => now()]);
        } catch (\Throwable $e) {
            $log->update([
                'status'        => 'failed',
                'error_message' => $e->getMessage(),
                'completed_at'  => now(),
            ]);
            Log::error('Venta category sync failed', [
                'store' => $this->setting->store_name,
                'error' => $e->getMessage(),
            ]);
        }

        return $log;
    }
}
