<?php

namespace Extensions\TikTok\Controllers;

use Extensions\TikTok\Models\TikTokApiLog;
use Extensions\TikTok\Models\TikTokCategory;
use Extensions\TikTok\Models\TikTokProductGroup;
use Extensions\TikTok\Models\TikTokStore;
use Extensions\TikTok\Services\TikTok\TikTokClient;
use Extensions\TikTok\Services\TikTok\TikTokAttributes;
use Illuminate\Http\Request;

class TikTokProductGroupController extends \App\Http\Controllers\Channels\ProductGroupController
{
    protected function channel(): string
    {
        return 'tiktok';
    }

    protected function groupModel(): string
    {
        return \Extensions\TikTok\Models\TikTokProductGroup::class;
    }

    protected function membership(): array
    {
        return ['ch_tiktok_product_group_products', 'tiktok_product_group_id'];
    }

    private function creds(): array
    {
        $s = TikTokStore::defaultStore();
        if (!$s) {
            abort(404, 'TikTok settings not configured.');
        }
        $d = $s->decrypted();
        $sandbox = $s->mode === 'sandbox';

        return [
            'setting'      => $s,
            'sandbox'      => $sandbox,
            'app_key'      => $sandbox ? ($d->sandbox_app_key ?? '') : ($d->app_key ?? ''),
            'app_secret'   => $sandbox ? ($d->sandbox_app_secret ?? '') : ($d->app_secret ?? ''),
            'token'        => $sandbox ? ($d->sandbox_access_token ?? '') : ($d->access_token ?? ''),
            'shop_cipher'  => $sandbox ? ($s->sandbox_shop_cipher ?? '') : ($s->shop_cipher ?? ''),
            'warehouse_id' => $sandbox ? ($s->sandbox_warehouse_id ?? '') : ($s->warehouse_id ?? ''),
        ];
    }

    private function logApi(string $method, string $path, array $result): void
    {
        TikTokApiLog::safeCreate([
            'pack'            => 'product-sync',
            'method'          => $method,
            'api_path'        => $path,
            'auth_required'   => true,
            'request_params'  => [],
            'response_status' => $result['status'] ?? 0,
            'ok'              => $result['ok'] ?? false,
            'response_body'   => $result['body'] ?? [],
            'user_id'         => auth()->id(),
        ]);
    }

    public function indexCategories(Request $request)
    {
        $q = trim((string) $request->input('q', ''));
        $query = TikTokCategory::query()->orderBy('name');
        if ($q !== '') {
            $query->where(function ($w) use ($q) {
                $w->where('name', 'like', '%' . $q . '%')
                    ->orWhere('id', 'like', '%' . $q . '%');
            });
        }
        $categories = $query->paginate(50)->withQueryString();
        return view('ext-tiktok::categories.index', compact('categories', 'q'));
    }

    public function syncCategories()
    {
        $c = $this->creds();
        $client = app(TikTokClient::class);

        $result = $client->getCategories($c['app_key'], $c['app_secret'], $c['token'], $c['shop_cipher']);
        $this->logApi('GET', '/product/202309/categories', $result);

        if (!($result['ok'] ?? false)) {
            return back()->with('status', 'Failed to fetch categories: ' . json_encode($result['body']['message'] ?? 'unknown error'));
        }

        $categories = $result['body']['data']['categories'] ?? [];
        if (empty($categories)) {
            return back()->with('status', 'No categories returned from TikTok.');
        }

        $now = now();
        foreach ($categories as $cat) {
            TikTokCategory::updateOrCreate(
                ['id' => (string) $cat['id']],
                [
                    'parent_id'           => isset($cat['parent_id']) && $cat['parent_id'] !== '0' ? (string) $cat['parent_id'] : null,
                    'name'                => $cat['local_name'] ?? $cat['name'] ?? '',
                    'is_leaf'             => (bool) ($cat['is_leaf'] ?? false),
                    'permission_statuses' => $cat['permission_statuses'] ?? null,
                    'synced_at'           => $now,
                ]
            );
        }

        if (request()->expectsJson()) {
            $cats = TikTokCategory::where('is_leaf', true)->orderBy('name')->get(['id', 'name']);
            return response()->json(['ok' => true, 'count' => count($categories), 'categories' => $cats]);
        }

        return back()->with('status', count($categories) . ' categories synced.');
    }

    public function fetchAttributesAjax(Request $request)
    {
        $categoryId = trim((string) $request->input('tiktok_category_id', ''));
        if ($categoryId === '') {
            return response()->json(['ok' => false, 'html' => '']);
        }
        $service = app(TikTokAttributes::class);
        $template = $service->refreshTemplate($categoryId, app(TikTokClient::class), $this->creds());
        $rows = $service->rows($template);
        $saved = (array) $request->input('saved', []);

        return response()->json([
            'ok' => $template !== null,
            'html' => view('ext-tiktok::product-groups._attributes', [
                'rows' => $rows, 'saved' => $saved, 'template' => $template, 'canManage' => true,
            ])->render(),
        ]);
    }
}
