<?php

namespace Extensions\tiktok\Services\TikTok;

use Extensions\tiktok\Models\TikTokApiLog;
use Extensions\tiktok\Models\TikTokCategoryTemplate;

class TikTokAttributes
{
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
                continue;
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

        usort($rows, fn ($a, $b) => (int) $b['required'] <=> (int) $a['required']);

        return $rows;
    }

    public function salesPropertyIds(?TikTokCategoryTemplate $template): ?array
    {
        if (!$template) {
            return null;
        }
        $list = is_array($template->attributes) ? $template->attributes : (json_decode((string) $template->attributes, true) ?: []);
        $ids = [];
        foreach ($list as $attr) {
            if (is_array($attr) && ($attr['type'] ?? '') === 'SALES_PROPERTY' && (string) ($attr['id'] ?? '') !== '') {
                $ids[] = (string) $attr['id'];
            }
        }

        return $ids;
    }

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
