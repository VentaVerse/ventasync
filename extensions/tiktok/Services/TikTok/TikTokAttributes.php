<?php

namespace Extensions\tiktok\Services\TikTok;

use Extensions\tiktok\Models\TikTokApiLog;
use Extensions\tiktok\Models\TikTokCategoryTemplate;

/**
 * A TikTok category's attribute sheet (#156). Read from TikTok once and
 * cached; normalised into rows the group form, the listing page and the
 * readiness check all read the same way; and turned back into the
 * product_attributes a push sends. TikTok's sheet mixes product
 * properties (what a listing answers) with sales properties (the
 * variation axes, which our pushes name themselves from the catalog),
 * so only PRODUCT_PROPERTY rows are listed. TikTok's own field for
 * "required" is spelled is_requried in the 202309 API; both spellings
 * are read.
 */
class TikTokAttributes
{
    /** Read and cache the sheet when it has never been read. Quiet on failure. */
    public function ensureTemplate(string $categoryId, TikTokClient $client, array $c): ?TikTokCategoryTemplate
    {
        if ($categoryId === '') {
            return null;
        }
        $existing = TikTokCategoryTemplate::query()->where('category_id', $categoryId)->first();
        if ($existing) {
            return $existing;
        }

        return $this->refreshTemplate($categoryId, $client, $c);
    }

    /** Read the sheet from TikTok and cache it, replacing what was there. */
    public function refreshTemplate(string $categoryId, TikTokClient $client, array $c): ?TikTokCategoryTemplate
    {
        try {
            $result = $client->getCategoryAttributes($c['app_key'], $c['app_secret'], $c['token'], $categoryId, $c['shop_cipher'] ?: null);
        } catch (\Throwable $e) {
            $result = ['ok' => false, 'status' => 0, 'body' => ['message' => \App\Support\TransportError::plain($e, 'TikTok Shop')]];
        }

        TikTokApiLog::safeCreate([
            'pack' => 'tiktok.categories.attributes', 'method' => 'GET',
            'api_path' => '/product/202309/categories/' . $categoryId . '/attributes', 'auth_required' => true,
            'request_params' => ['category_id' => $categoryId],
            'response_status' => $result['status'] ?? 0,
            'ok' => (bool) ($result['ok'] ?? false),
            'response_body' => $result['body'] ?? [], 'user_id' => auth()->id(),
        ]);

        if (!($result['ok'] ?? false) || (int) ($result['body']['code'] ?? -1) !== 0) {
            return null;
        }

        return TikTokCategoryTemplate::query()->updateOrCreate(
            ['category_id' => $categoryId],
            ['attributes' => $result['body']['data']['attributes'] ?? [], 'fetched_at' => now()]
        );
    }

    /**
     * The sheet as rows: key (TikTok's attribute id), name, required,
     * options [{id, name}], customizable (free text allowed).
     * @return array<int, array{key: string, name: string, required: bool, options: array, customizable: bool}>
     */
    public function rows(?TikTokCategoryTemplate $template): array
    {
        if (!$template) {
            return [];
        }
        $list = is_array($template->attributes) ? $template->attributes : (json_decode((string) $template->attributes, true) ?: []);
        $rows = [];
        foreach ($list as $attr) {
            if (!is_array($attr)) {
                continue;
            }
            if (($attr['type'] ?? 'PRODUCT_PROPERTY') !== 'PRODUCT_PROPERTY') {
                continue;   // sales properties are the variation axes
            }
            $id = (string) ($attr['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $options = [];
            foreach ((array) ($attr['values'] ?? []) as $v) {
                if (is_array($v) && isset($v['name'])) {
                    $options[] = ['id' => (string) ($v['id'] ?? ''), 'name' => (string) $v['name']];
                }
            }
            $rows[] = [
                'key' => $id,
                'name' => (string) ($attr['name'] ?? ('Attribute ' . $id)),
                'required' => (bool) ($attr['is_requried'] ?? $attr['is_required'] ?? false),
                'options' => $options,
                'customizable' => (bool) ($attr['is_customizable'] ?? empty($options)),
            ];
        }

        // Required first, then the rest, each in TikTok's order.
        usort($rows, fn ($a, $b) => (int) $b['required'] <=> (int) $a['required']);

        return $rows;
    }

    /** The answers as product_attributes for a push. */
    public function payload(array $rows, array $saved): array
    {
        $out = [];
        foreach ($rows as $row) {
            $value = trim((string) ($saved[$row['key']] ?? ''));
            if ($value === '') {
                continue;
            }
            $match = null;
            foreach ($row['options'] as $opt) {
                if ($opt['id'] !== '' && ($opt['id'] === $value || strcasecmp($opt['name'], $value) === 0)) {
                    $match = $opt;
                    break;
                }
            }
            $out[] = [
                'id' => $row['key'],
                'values' => [$match ? ['id' => $match['id'], 'name' => $match['name']] : ['name' => $value]],
            ];
        }

        return $out;
    }

    /** Names of required attributes the answers leave blank. */
    public function missingRequired(array $rows, array $saved): array
    {
        $missing = [];
        foreach ($rows as $row) {
            if ($row['required'] && trim((string) ($saved[$row['key']] ?? '')) === '') {
                $missing[] = $row['name'];
            }
        }

        return $missing;
    }
}
