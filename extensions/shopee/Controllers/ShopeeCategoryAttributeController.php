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

        $setting = ShopeeSetting::defaultStore()?->decrypted();
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

        $setting = ShopeeSetting::defaultStore()?->decrypted();
        $auth = ShopeeSetting::activeAuth($setting);
        if (!$setting || !$auth['complete']) {
            $modeLabel = $auth['mode'] === 'sandbox' ? 'Sandbox' : 'Production';
            return redirect()->route('ext.shopee.categories.attributes.show', $categoryId)
                ->with('error', "Missing Shopee {$modeLabel} settings. Please configure Partner ID, Partner Key, Access Token, and Shop ID first.");
        }

        $path = '/api/v2/product/get_attribute_tree';

        $result = $client->shopGet(
            $auth['mode'],
            (int)$auth['partner_id'],
            (string)$auth['partner_key'],
            (string)$auth['access_token'],
            (int)$auth['shop_id'],
            $path,
            ['category_id' => $categoryId, 'language' => $auth['region'] ?: 'en']
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
            $msg = \App\Support\MarketplaceAnswer::plain('Shopee', ['ok' => false, 'body' => is_array($body) ? $body : []]);
            return redirect()->route('ext.shopee.categories.attributes.show', $categoryId)
                ->with('error', 'Fetch failed: ' . $msg);
        }

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
