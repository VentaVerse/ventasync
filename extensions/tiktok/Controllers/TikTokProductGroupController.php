<?php

namespace Extensions\TikTok\Controllers;

use App\Http\Controllers\Controller;
use App\Services\ActivityLogger;
use Extensions\TikTok\Models\TikTokApiLog;
use Extensions\TikTok\Models\TikTokCategory;
use Extensions\TikTok\Models\TikTokProductGroup;
use Extensions\TikTok\Models\TikTokProductGroupProduct;
use Extensions\TikTok\Models\TikTokListing;
use Extensions\TikTok\Models\TikTokStore;
use Extensions\TikTok\Services\TikTok\TikTokClient;
use Extensions\TikTok\Services\TikTok\TikTokStockPushService;
use Extensions\TikTok\Services\TikTok\TikTokVariationPush;
use Extensions\TikTok\Services\TikTok\TikTokAttributes;
use Extensions\TikTok\Services\TikTok\TikTokProductPush;
use Extensions\TikTok\Services\TikTok\TikTokLiveListing;
use Extensions\TikTok\Services\TikTokStoreProducts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

    private function states(int $storeId): \Extensions\TikTok\Services\TikTok\TikTokListingStates
    {
        return app(\Extensions\TikTok\Services\TikTok\TikTokListingStates::class)->forStore($storeId);
    }

    private function productsRedirect(int $id)
    {
        $fallback = route('ext.tiktok.products.index', ['group' => $id]);

        return redirect(\App\Support\BackTo::safe(request()->input('_return'), $fallback));
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

    public function moveProducts(Request $request)
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) $request->input('ids', [])), fn ($v) => $v > 0)));
        $back = \App\Support\BackTo::safe($request->input('_return'), route('ext.tiktok.products.index'));
        if ($ids === []) {
            return redirect($back)->with('warning', 'No products selected.');
        }
        $target = (string) $request->input('group', '');
        if ($target === 'none') {
            $removed = TikTokProductGroupProduct::query()->onStore()->whereIn('product_id', $ids)->delete();

            return redirect($back)->with('status', $removed . ' ' . \Illuminate\Support\Str::plural('product', $removed) . ' ungrouped.');
        }
        $group = TikTokProductGroup::query()->findOrFail((int) $target);

        $already = TikTokProductGroupProduct::where('tiktok_product_group_id', $group->id)->whereIn('product_id', $ids)->pluck('product_id')->map(fn ($v) => (int) $v)->all();
        $wanted = array_values(array_diff($ids, $already));
        $claim = \App\Channels\OneGroupRule::claim('ch_tiktok_product_group_products', 'tiktok_product_group_id', 'ch_tiktok_product_groups', 'store_id', $group->id, $wanted, $wanted);
        $moved = 0;
        foreach (array_merge($claim['free'], array_keys($claim['moved'])) as $pid) {
            TikTokProductGroupProduct::create(['tiktok_product_group_id' => $group->id, 'product_id' => (int) $pid, 'sync_status' => 'pending']);
            $moved++;
        }
        if ($moved === 0) {
            return redirect($back)->with('warning', 'Those products are already in ' . $group->name . '.');
        }

        return redirect($back)->with('status', $moved . ' ' . \Illuminate\Support\Str::plural('product', $moved) . ' moved to ' . $group->name . '.');
    }

    public function massRemove(Request $request, int $id)
    {
        $group = TikTokProductGroup::findOrFail($id);
        $ids = array_filter(array_map('intval', (array) $request->input('ids', [])));

        if (empty($ids)) {
            return $this->productsRedirect($id)
                ->with('status', 'No products selected.');
        }

        $deleted = TikTokProductGroupProduct::where('tiktok_product_group_id', $group->id)
            ->whereIn('product_id', $ids)
            ->delete();
        $this->states((int) $group->store_id)->clearErrors($ids);

        return $this->productsRedirect($id)
            ->with('status', $deleted . ' product(s) removed from the product group.');
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
