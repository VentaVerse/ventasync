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

        $setting = LazadaSetting::defaultStore()?->decrypted();
        $creds = LazadaSetting::activeCredentials($setting);
        $region = (string)($setting->region ?? '');

        $template = null;
        $attributes = [];
        if ($region !== '') {
            $template = LazadaCategoryTemplate::query()
                ->where('region', $region)
                ->where('primary_category_id', $categoryId)
                ->first();

            if ($template && $template->template_body) {
                $attributes = app(\Extensions\lazada\Services\Lazada\LazadaAttributes::class)->extractAttributes($template->template_body, questionsOnly: false, withRaw: true);
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

        $setting = LazadaSetting::defaultStore()?->decrypted();
        $creds = LazadaSetting::activeCredentials($setting);
        if (!$setting || !$setting->region || !$creds['app_key'] || !$creds['app_secret']) {
            return redirect()->route('ext.lazada.categories.attributes.show', $categoryId)
                ->with('error', 'Missing Lazada settings. Please configure Region, App Key, and App Secret first.');
        }

        $apiPath = '/category/attributes/get';
        $timestamp = (string)round(microtime(true) * 1000);
        $params = [
            'app_key' => (string)$creds['app_key'],
            'sign_method' => 'sha256',
            'timestamp' => $timestamp,
            'primary_category_id' => (string)$categoryId,
        ];
        $params['sign'] = $client->sign($apiPath, $params, (string)$creds['app_secret']);
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


}