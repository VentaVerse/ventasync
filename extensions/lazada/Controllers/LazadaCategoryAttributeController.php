<?php

namespace Extensions\lazada\Controllers;

use App\Http\Controllers\Controller;

use Extensions\lazada\Models\LazadaApiLog;
use Extensions\lazada\Models\LazadaCategory;
use Extensions\lazada\Models\LazadaCategoryTemplate;
use Extensions\lazada\Models\LazadaSetting;
use Extensions\lazada\Services\Lazada\LazadaClient;
use Illuminate\Http\Request;

class LazadaCategoryAttributeController extends Controller
{
    public function show(Request $request, int $categoryId)
    {
        $category = LazadaCategory::query()->where('category_id', $categoryId)->firstOrFail();

        $setting = LazadaSetting::query()->first()?->decrypted();
        $region = (string)($setting->region ?? '');

        $template = null;
        $attributes = [];
        if ($region !== '') {
            $template = LazadaCategoryTemplate::query()
                ->where('region', $region)
                ->where('primary_category_id', $categoryId)
                ->first();

            if ($template && $template->template_body) {
                $attributes = $this->extractAttributes($template->template_body);
            }
        }

        $logs = LazadaApiLog::query()
            ->where('api_path', '/category/attributes/get')
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        return view('ext-lazada::categories.attributes', [
            'category' => $category,
            'template' => $template,
            'attributes' => $attributes,
            'logs' => $logs,
            'region' => $region,
        ]);
    }

    public function fetch(Request $request, int $categoryId, LazadaClient $client)
    {
        $category = LazadaCategory::query()->where('category_id', $categoryId)->firstOrFail();

        $setting = LazadaSetting::query()->first()?->decrypted();
        if (!$setting || !$setting->region || !$setting->app_key || !$setting->app_secret) {
            return redirect()->route('ext.lazada.categories.attributes.show', $categoryId)
                ->with('error', 'Missing Lazada settings. Please configure Region, App Key, and App Secret first.');
        }

        $apiPath = '/category/attributes/get';
        $timestamp = (string)round(microtime(true) * 1000);
        $params = [
            'app_key' => (string)$setting->app_key,
            'sign_method' => 'sha256',
            'timestamp' => $timestamp,
            'primary_category_id' => (string)$categoryId,
        ];
        $params['sign'] = $client->sign($apiPath, $params, (string)$setting->app_secret);
        $result = $client->get((string)$setting->region, $apiPath, $params);

        LazadaCategoryTemplate::query()->updateOrCreate(
            [
                'region' => (string)$setting->region,
                'primary_category_id' => $categoryId,
            ],
            [
                'template_body' => $result['body'] ?? null,
                'fetched_at' => now(),
            ]
        );

        LazadaApiLog::safeCreate([
            'pack' => 'lazada.category.attributes',
            'method' => 'GET',
            'api_path' => $apiPath,
            'auth_required' => false,
            'request_params' => $params,
            'response_status' => $result['status'] ?? null,
            'ok' => (bool)($result['ok'] ?? false),
            'response_body' => $result['body'] ?? null,
            'user_id' => auth()->id(),
        ]);

        if (!$result['ok']) {
            $body = $result['body'] ?? null;
            $msg = is_array($body) ? ($body['message'] ?? ($body['code'] ?? 'Failed')) : 'Failed';
            return redirect()->route('ext.lazada.categories.attributes.show', $categoryId)
                ->with('error', 'Fetch failed: ' . $msg);
        }

        return redirect()->route('ext.lazada.categories.attributes.show', $categoryId)
            ->with('status', 'Category attributes fetched successfully for: ' . $category->name);
    }

    private function extractAttributes($body): array
    {
        if (!is_array($body)) {
            return [];
        }

        $data = $body['data'] ?? $body;
        if (is_array($data) && isset($data['attributes']) && is_array($data['attributes'])) {
            return $this->normalizeAttributes($data['attributes']);
        }
        if (is_array($data) && array_is_list($data)) {
            return $this->normalizeAttributes($data);
        }
        return [];
    }

    private function normalizeAttributes(array $raw): array
    {
        $out = [];
        foreach ($raw as $a) {
            if (!is_array($a)) {
                continue;
            }

            $name = (string)($a['name'] ?? $a['attribute_name'] ?? '');
            $id = $a['id'] ?? $a['attribute_id'] ?? null;
            $required = (bool)($a['is_mandatory'] ?? $a['isMandatory'] ?? $a['mandatory'] ?? $a['is_required'] ?? $a['required'] ?? false);
            $inputType = (string)($a['input_type'] ?? $a['type'] ?? $a['inputType'] ?? 'text');
            $options = $a['options'] ?? $a['option_values'] ?? $a['values'] ?? null;

            $key = $name !== '' ? $name : (is_scalar($id) ? (string)$id : '');
            if ($key === '') {
                continue;
            }

            $out[] = [
                'key' => $key,
                'name' => $name !== '' ? $name : $key,
                'required' => $required,
                'input_type' => $inputType,
                'options' => is_array($options) ? $options : [],
                'raw' => $a,
            ];
        }
        return $out;
    }

}