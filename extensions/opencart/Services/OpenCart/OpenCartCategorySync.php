<?php

namespace Extensions\opencart\Services\OpenCart;

use Extensions\opencart\Models\OpenCartSetting;
use Extensions\opencart\Models\OpenCartSyncLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OpenCartCategorySync
{
    private OpenCartClient $client;
    private OpenCartSetting $setting;

    private array $categoryMap = [];

    public function __construct(OpenCartClient $client, OpenCartSetting $setting)
    {
        $this->client = $client;
        $this->setting = $setting;
    }

    public function pull(?string $modifiedSince = null, bool $full = false): array
    {
        $log = OpenCartSyncLog::create([
            'opencart_setting_id' => $this->setting->id,
            'entity_type' => 'category',
            'direction'   => 'pull',
            'status'      => 'started',
            'started_at'  => now(),
        ]);

        try {
            $since = $full ? null : ($modifiedSince ?? $this->setting->last_category_sync_at?->toIso8601String());
            $result = $this->client->getCategories($since);

            if (!$result['ok']) {
                throw new \RuntimeException('API error: ' . json_encode($result['body']));
            }

            $categories = $result['body']['data'] ?? [];
            $pfx = (string) config('catalog.prefix');
            $langId = (int) config('catalog.default_language_id');
            $created = 0;
            $updated = 0;
            $failed = 0;
            $errors = [];

            usort($categories, function ($a, $b) {
                $aDepth = count($a['path'] ?? []);
                $bDepth = count($b['path'] ?? []);
                return $aDepth <=> $bDepth;
            });

            foreach ($categories as $raw) {
                try {
                    $ocCatId = (int) ($raw['category_id'] ?? 0);
                    if ($ocCatId <= 0) continue;

                    $name = trim($raw['name'] ?? '');
                    if ($name === '') continue;

                    $ocParentId = (int) ($raw['parent_id'] ?? 0);
                    $coreParentId = 0;
                    if ($ocParentId > 0 && isset($this->categoryMap[$ocParentId])) {
                        $coreParentId = $this->categoryMap[$ocParentId];
                    }

                    $existing = DB::table($pfx . 'category AS c')
                        ->join($pfx . 'category_description AS cd', function ($join) use ($langId) {
                            $join->on('c.category_id', '=', 'cd.category_id')
                                ->where('cd.language_id', '=', $langId);
                        })
                        ->where('cd.name', $name)
                        ->where('c.parent_id', $coreParentId)
                        ->select('c.category_id')
                        ->first();

                    $data = [
                        'parent_id'     => $coreParentId,
                        'top'           => (int) ($raw['top'] ?? 0),
                        'column'        => (int) ($raw['column'] ?? 0),
                        'sort_order'    => (int) ($raw['sort_order'] ?? 0),
                        'status'        => (int) ($raw['status'] ?? 0),
                        'image'         => $raw['image'] ?? '',
                        'date_modified' => $raw['date_modified'] ?? now()->toDateTimeString(),
                    ];

                    if ($existing) {
                        $coreCatId = (int) $existing->category_id;
                        DB::table($pfx . 'category')
                            ->where('category_id', $coreCatId)
                            ->update($data);
                        $updated++;
                    } else {
                        $data['date_added'] = $raw['date_added'] ?? now()->toDateTimeString();
                        $coreCatId = DB::table($pfx . 'category')->insertGetId($data, 'category_id');
                        $created++;
                    }

                    DB::table($pfx . 'category_description')->updateOrInsert(
                        ['category_id' => $coreCatId, 'language_id' => $langId],
                        [
                            'name'             => $name,
                            'description'      => $raw['description'] ?? '',
                            'meta_title'       => $raw['meta_title'] ?? '',
                            'meta_description' => $raw['meta_description'] ?? '',
                            'meta_keyword'     => $raw['meta_keyword'] ?? '',
                            'seo_keyword'      => $raw['seo_keyword'] ?? '',
                            'seo_h1'           => $raw['seo_h1'] ?? '',
                            'seo_h2'           => $raw['seo_h2'] ?? '',
                            'seo_h3'           => $raw['seo_h3'] ?? '',
                        ]
                    );

                    DB::table($pfx . 'category_path')
                        ->where('category_id', $coreCatId)
                        ->delete();

                    if (isset($raw['path']) && is_array($raw['path'])) {
                        foreach ($raw['path'] as $p) {
                            $ocPathId = (int) ($p['path_id'] ?? 0);
                            if ($ocPathId === $ocCatId) {
                                $corePathId = $coreCatId;
                            } elseif (isset($this->categoryMap[$ocPathId])) {
                                $corePathId = $this->categoryMap[$ocPathId];
                            } else {
                                $corePathId = $coreCatId;
                            }

                            DB::table($pfx . 'category_path')->insert([
                                'category_id' => $coreCatId,
                                'path_id'     => $corePathId,
                                'level'       => (int) ($p['level'] ?? 0),
                            ]);
                        }
                    } else {
                        DB::table($pfx . 'category_path')->insert([
                            'category_id' => $coreCatId,
                            'path_id'     => $coreCatId,
                            'level'       => 0,
                        ]);
                    }

                    $this->categoryMap[$ocCatId] = $coreCatId;
                } catch (\Throwable $e) {
                    $failed++;
                    $errors[] = [
                        'category_id' => $raw['category_id'] ?? '?',
                        'error'       => $e->getMessage(),
                    ];
                }
            }

            $this->setting->update(['last_category_sync_at' => now()]);

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
            Log::error('OpenCart category sync failed', ['error' => $e->getMessage()]);
        }

        return ['log' => $log, 'map' => $this->categoryMap];
    }

    public function getCategoryMap(): array
    {
        return $this->categoryMap;
    }
}
