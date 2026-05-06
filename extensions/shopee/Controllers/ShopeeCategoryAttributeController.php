<?php

namespace Extensions\shopee\Controllers;

use App\Http\Controllers\Controller;

use Extensions\shopee\Models\ShopeeApiLog;
use Extensions\shopee\Models\ShopeeCategory;
use Extensions\shopee\Models\ShopeeCategoryTemplate;
use Extensions\shopee\Models\ShopeeSetting;
use Extensions\shopee\Services\Shopee\ShopeeClient;
use Illuminate\Http\Request;

class ShopeeCategoryAttributeController extends Controller
{
    public function show(Request $request, int $categoryId)
    {
        $category = ShopeeCategory::query()->where('category_id', $categoryId)->firstOrFail();

        $setting = ShopeeSetting::query()->first()?->decrypted();
        $region = (string)($setting->region ?? '');

        $template = ShopeeCategoryTemplate::query()
            ->where('category_id', $categoryId)
            ->first();

        $attributes = [];
        if ($template && $template->attributes) {
            $attributes = $this->extractAttributes($template->attributes);
        }

        $logs = ShopeeApiLog::query()
            ->where('api_path', '/api/v2/product/get_attribute_tree')
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        return view('ext-shopee::categories.attributes', [
            'category' => $category,
            'template' => $template,
            'attributes' => $attributes,
            'logs' => $logs,
            'region' => $region,
        ]);
    }

    public function fetch(Request $request, int $categoryId, ShopeeClient $client)
    {
        $category = ShopeeCategory::query()->where('category_id', $categoryId)->firstOrFail();

        $setting = ShopeeSetting::query()->first()?->decrypted();
        if (!$setting || !$setting->partner_id || !$setting->partner_key || !$setting->access_token || !$setting->shop_id) {
            return redirect()->route('ext.shopee.categories.attributes.show', $categoryId)
                ->with('error', 'Missing Shopee settings. Please configure Partner ID, Partner Key, Access Token, and Shop ID first.');
        }

        $path = '/api/v2/product/get_attribute_tree';

        $result = $client->shopGet(
            $setting->mode ?? 'sandbox',
            (int)$setting->partner_id,
            (string)$setting->partner_key,
            (string)$setting->access_token,
            (int)$setting->shop_id,
            $path,
            ['category_id' => $categoryId, 'language' => $setting->region ?: 'en']
        );

        $body = $result['body'] ?? null;

        ShopeeApiLog::safeCreate([
            'pack' => 'shopee.category.attributes',
            'method' => 'GET',
            'api_path' => $path,
            'auth_required' => true,
            'request_params' => ['category_id' => $categoryId],
            'response_status' => $result['status'] ?? null,
            'ok' => (bool)($result['ok'] ?? false),
            'response_body' => $body,
            'user_id' => auth()->id(),
        ]);

        if (!$result['ok']) {
            $msg = is_array($body) ? ($body['message'] ?? ($body['error'] ?? 'Failed')) : 'Failed';
            return redirect()->route('ext.shopee.categories.attributes.show', $categoryId)
                ->with('error', 'Fetch failed: ' . $msg);
        }

        // Try multiple response keys — Shopee may use attribute_list or attribute_tree
        $attrList = null;
        $response = null;
        if (is_array($body)) {
            $response = $body['response'] ?? $body;
            $attrList = $response['attribute_list']
                ?? $response['attribute_tree']
                ?? $response['attributes']
                ?? null;
        }

        ShopeeCategoryTemplate::query()->updateOrCreate(
            ['category_id' => $categoryId],
            [
                'region' => (string)($setting->region ?? ''),
                'attributes' => $attrList ?? ($response ?? $body),
                'fetched_at' => now(),
            ]
        );

        $count = is_array($attrList) ? count($attrList) : 0;
        $debugKeys = is_array($response) ? implode(', ', array_keys($response)) : 'n/a';

        return redirect()->route('ext.shopee.categories.attributes.show', $categoryId)
            ->with('status', "Attributes fetched for: {$category->name}. Found: {$count}. Response keys: {$debugKeys}");
    }

    private function extractAttributes($data): array
    {
        if (!is_array($data)) {
            return [];
        }

        // Shopee returns attribute_list directly as an array
        $list = $data;
        if (isset($data['attribute_list']) && is_array($data['attribute_list'])) {
            $list = $data['attribute_list'];
        }

        if (!is_array($list) || empty($list)) {
            return [];
        }

        return $this->normalizeAttributes($list);
    }

    private function normalizeAttributes(array $raw): array
    {
        $out = [];
        foreach ($raw as $a) {
            if (!is_array($a)) {
                continue;
            }

            $name = (string)($a['original_attribute_name'] ?? $a['display_attribute_name'] ?? $a['attribute_name'] ?? '');
            $id = $a['attribute_id'] ?? null;
            $required = (bool)($a['is_mandatory'] ?? $a['mandatory'] ?? false);
            $inputType = (string)($a['input_type'] ?? $a['input_validation_type'] ?? 'text');
            $options = $a['attribute_value_list'] ?? $a['options'] ?? $a['values'] ?? null;

            $key = is_scalar($id) ? (string)$id : ($name !== '' ? $name : '');
            if ($key === '') {
                continue;
            }

            $out[] = [
                'key' => $key,
                'name' => $name !== '' ? $name : $key,
                'required' => $required,
                'input_type' => $inputType,
                'options' => is_array($options) ? $options : [],
            ];
        }
        return $out;
    }

}
