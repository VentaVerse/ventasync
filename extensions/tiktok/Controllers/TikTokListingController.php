<?php

namespace Extensions\tiktok\Controllers;

use App\Http\Controllers\Controller;
use Extensions\tiktok\Models\TikTokApiLog;
use Extensions\tiktok\Models\TikTokListing;
use Extensions\tiktok\Models\TikTokSetting;
use Extensions\tiktok\Models\TikTokCategory;
use Extensions\tiktok\Services\TikTok\TikTokAttributes;
use Extensions\tiktok\Services\TikTok\TikTokClient;
use Extensions\tiktok\Services\TikTok\TikTokListingReadiness;
use Extensions\tiktok\Services\TikTok\TikTokLiveListing;
use Extensions\tiktok\Services\TikTok\TikTokProductPush;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The per-product TikTok surface (#146). TikTok's listing truth stays
 * DERIVED across the group pivots; this page reads that truth for ONE
 * product and manages the facts that belong to the product rather than
 * to any group - today, the discount rule.
 *
 * TikTok models a sale as a promotion ACTIVITY: a named object with a
 * server-side window that products join. DIRECT_DISCOUNT takes the
 * percent itself and applies it to the live price at the marketplace,
 * so unlike Shopee no promo price is computed here. Like the Shopee
 * half, VentaSync keeps ONE activity per window and every product
 * pushed for an identical window joins it.
 */
class TikTokListingController extends Controller
{
    public function edit(int $productId, TikTokLiveListing $liveListing)
    {
        $product = $this->productOrFail($productId);
        $listing = TikTokListing::query()->where('product_id', $productId)->first();
        $truth = $this->derivedTruth($productId);

        // The live half, fetched from TikTok on this load; only a listed
        // product has one, an unpushed one shows readiness instead.
        $live = null;
        $liveError = null;
        $c = $this->creds();
        if ($truth) {
            if (!$c) {
                $liveError = 'TikTok settings are incomplete, so the live state could not be fetched.';
            } else {
                $answer = $liveListing->fetch((string) $truth->tiktok_product_id, $c);
                $live = $answer['live'];
                $liveError = $answer['error'];
                // The freshest answer there is: write it to the mirror so the
                // lists agree with this page at once (the owner, 2026-08-28).
                if ($live !== null && (string) ($live['status'] ?? '') !== '') {
                    TikTokListing::query()->where('tiktok_product_id', (string) $truth->tiktok_product_id)
                        ->update(['live_status' => (string) $live['status'], 'live_checked_at' => now()]);
                }
            }
        }

        // The category's attribute sheet, read once when never seen.
        $attributes = app(TikTokAttributes::class);
        $template = ($listing && $listing->tiktok_category_id && $c)
            ? $attributes->ensureTemplate((string) $listing->tiktok_category_id, app(TikTokClient::class), $c)
            : null;
        $category = ($listing && $listing->tiktok_category_id)
            ? TikTokCategory::query()->where('id', $listing->tiktok_category_id)->first(['id', 'name'])
            : null;
        $readiness = app(TikTokListingReadiness::class)->forProducts([$productId])[$productId] ?? ['ready' => false, 'missing' => []];

        /* The engine's answer for this product, computed AFTER the
         * write-through above so it reads the freshest mirror (#184 D).
         * The page renders its words, never a map of its own. */
        $listingState = app(\Extensions\tiktok\Services\TikTok\TikTokListingStates::class)
            ->forProducts([$productId])[$productId] ?? null;

        return view('ext-tiktok::listings.edit', [
            'listingState' => $listingState,
            'product' => $product,
            'listing' => $listing,
            'truth' => $truth,
            'live' => $live,
            'liveError' => $liveError,
            'category' => $category,
            'attrTemplate' => $template,
            'attrRows' => $attributes->rows($template),
            'savedAttributes' => $listing->attribute_values ?? [],
            'readinessMissing' => $readiness['missing'],
        ]);
    }

    public function update(Request $request, int $productId)
    {
        $this->productOrFail($productId);

        $data = $request->validate([
            'tiktok_category_id' => 'nullable|string|max:64|exists:tiktok_categories,id',
            'brand_id' => 'nullable|string|max:64',
            'brand_name' => 'nullable|string|max:191',
            'markup_percent' => 'nullable|numeric|min:-100|max:1000',
            'markup_fixed' => 'nullable|numeric|min:-1000000|max:1000000',
            'title' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:10000',
            'attributes' => 'nullable|array',
            'attributes.*' => 'nullable|string|max:500',
            // TikTok takes the percent itself and only whole percents.
            'sale_percent' => 'nullable|integer|min:1|max:99',
            'sale_starts_at' => 'nullable|date|required_with:sale_percent',
            'sale_ends_at' => 'nullable|date|required_with:sale_percent|after:sale_starts_at',
        ]);

        /* Content fields are OVERRIDES: an emptied field returns to null,
         * which means "follows the catalog". Saving here never writes the
         * core product. */
        $blank = fn ($v) => ($v === null || trim((string) $v) === '') ? null : $v;
        $hasRule = ($data['sale_percent'] ?? null) !== null;
        TikTokListing::query()->updateOrCreate(
            ['product_id' => $productId],
            [
                'tiktok_category_id' => $blank($data['tiktok_category_id'] ?? null),
                'brand_id' => $blank($data['brand_id'] ?? null),
                'brand_name' => $blank($data['brand_id'] ?? null) ? $blank($data['brand_name'] ?? null) : null,
                'markup_percent' => $blank($data['markup_percent'] ?? null),
                'markup_fixed' => $blank($data['markup_fixed'] ?? null),
                'title' => $blank($data['title'] ?? null),
                'description' => $blank($data['description'] ?? null),
                'attribute_values' => array_filter(
                    array_map(fn ($v) => trim((string) $v), $data['attributes'] ?? []),
                    fn ($v) => $v !== ''
                ) ?: null,
                'sale_percent' => $hasRule ? $data['sale_percent'] : null,
                'sale_starts_at' => $hasRule ? ($data['sale_starts_at'] ?? null) : null,
                'sale_ends_at' => $hasRule ? ($data['sale_ends_at'] ?? null) : null,
            ]
        );

        return redirect()->route('ext.tiktok.listings.edit', $productId)
            ->with('status', 'Listing saved. These settings drive the next push and price sync.');
    }

    /**
     * Push the listing's content and settings onto the LIVE TikTok
     * product: the same builder as every push, with the ids injected so
     * an update edits SKUs rather than duplicating them.
     */
    public function pushListingUpdate(int $productId, TikTokClient $client)
    {
        $truth = $this->derivedTruth($productId);
        $c = $this->creds();
        if (!$truth || !$c) {
            return redirect()->back()->with('error', 'Update failed: ' . (!$c ? 'missing TikTok settings.' : 'this product is not on TikTok Shop.'));
        }
        $readiness = app(TikTokListingReadiness::class)->forProducts([$productId])[$productId] ?? ['ready' => false, 'missing' => ['a TikTok category']];
        if (!$readiness['ready']) {
            return redirect()->back()->with('error', 'Update failed: this listing still needs ' . implode(', ', $readiness['missing']) . '.');
        }
        $listing = TikTokListing::query()->where('product_id', $productId)->first();
        $attributes = app(TikTokAttributes::class);
        $template = $attributes->ensureTemplate((string) $listing->tiktok_category_id, $client, $c);
        $opts = [
            'category_id' => (string) $listing->tiktok_category_id,
            'brand_id' => $listing->brand_id,
            'attributes' => $attributes->payload($attributes->rows($template), $listing->attribute_values ?? []),
            'priceFor' => fn (float $base) => $listing->priceFor($base),
            'warehouse_id' => $c['warehouse_id'] ?: null,
            'title' => $listing->title, 'description' => $listing->description,
        ];
        $erp = DB::table((string) config('catalog.prefix') . 'product as p')
            ->leftJoin((string) config('catalog.prefix') . 'product_description as pd', function ($j) {
                $j->on('pd.product_id', '=', 'p.product_id')->where('pd.language_id', (int) config('catalog.default_language_id'));
            })
            ->where('p.product_id', $productId)
            ->first(['p.product_id', 'pd.name', 'pd.description', 'p.model', 'p.sku', 'p.price', 'p.quantity', 'p.image', 'p.weight', 'p.length', 'p.width', 'p.height']);

        $outcome = app(TikTokProductPush::class)->edit($erp, (string) $truth->tiktok_product_id, $truth->tiktok_sku_id ?? null, $opts, $c, $client);
        if (!$outcome['ok']) {
            return redirect()->back()->with('error', 'Update failed: ' . $outcome['message']);
        }
        TikTokListing::recordPush($productId, (string) $truth->tiktok_product_id, $outcome['sku_ids'] ?? ($truth->tiktok_sku_id ?? null), 'listing');

        return redirect()->back()->with('status', 'Update sent to TikTok Shop. Title, description, package, category, brand and attributes went; price and stock have their own pushes.');
    }

    /** The seller center's Activate / Deactivate, from here. */
    public function toggleListing(Request $request, int $productId, TikTokClient $client)
    {
        $request->validate(['action' => 'required|in:activate,deactivate']);
        $deactivate = $request->input('action') === 'deactivate';
        $truth = $this->derivedTruth($productId);
        $c = $this->creds();
        if (!$truth || !$c) {
            return redirect()->back()->with('error', ($deactivate ? 'Deactivate' : 'Activate') . ' failed: ' . (!$c ? 'missing TikTok settings.' : 'this product is not on TikTok Shop.'));
        }
        $result = $this->toggleProducts($client, $c, [(string) $truth->tiktok_product_id], $deactivate);
        if ($result['error'] !== null) {
            return redirect()->back()->with('error', ($deactivate ? 'Deactivate' : 'Activate') . ' failed: ' . $result['error']);
        }

        return redirect()->back()->with('status', $deactivate
            ? 'Deactivated on TikTok Shop. Buyers stop seeing it; it can be activated again at any time.'
            : 'Activated on TikTok Shop. Buyers see it again once TikTok applies it.');
    }

    /**
     * Activate or deactivate several products. TikTok's endpoints take a
     * product_ids list, so a selection of fifty is one call. On success
     * the mirror is written through, so the next page view reads the new
     * status without waiting for a refresh.
     *
     * @param  list<string>  $tiktokProductIds
     * @return array{ok: list<string>, error: ?string}
     */
    private function toggleProducts(TikTokClient $client, array $c, array $tiktokProductIds, bool $deactivate): array
    {
        $path = '/product/202309/products/' . ($deactivate ? 'deactivate' : 'activate');
        $ok = [];
        foreach (array_chunk(array_values(array_unique($tiktokProductIds)), 50) as $chunk) {
            $result = $this->call($client, $c, 'POST', $path, ['product_ids' => $chunk]);
            if (!$this->callOk($result)) {
                return ['ok' => $ok, 'error' => $this->callMessage($result)];
            }
            TikTokListing::query()->whereIn('tiktok_product_id', $chunk)
                ->update(['live_status' => $deactivate ? 'SELLER_DEACTIVATED' : 'ACTIVATE', 'live_checked_at' => now()]);
            array_push($ok, ...$chunk);
        }

        return ['ok' => $ok, 'error' => null];
    }

    /** The selection bar's Deactivate / Activate (the owner, 2026-08-28): the same call as the row's, for many rows. */
    public function bulkToggle(Request $request, TikTokClient $client)
    {
        $request->validate([
            'action' => 'required|in:activate,deactivate',
            'product_ids' => 'required|array|min:1',
            'product_ids.*' => 'integer',
        ]);
        $deactivate = $request->input('action') === 'deactivate';
        $verb = $deactivate ? 'Deactivated' : 'Activated';

        $c = $this->creds();
        if (!$c) {
            return redirect()->back()->with('error', 'Missing TikTok settings.');
        }

        $ids = array_values(array_unique(array_map('intval', (array) $request->input('product_ids'))));
        $ttIds = [];
        foreach ($ids as $id) {
            $truth = $this->derivedTruth($id);
            if ($truth && $truth->tiktok_product_id) {
                $ttIds[] = (string) $truth->tiktok_product_id;
            }
        }
        $notOnTikTok = count($ids) - count($ttIds);
        if ($ttIds === []) {
            return redirect()->back()->with('error', 'None of the selected products is on TikTok Shop, so there is nothing to ' . ($deactivate ? 'deactivate' : 'activate') . '.');
        }

        $result = $this->toggleProducts($client, $c, $ttIds, $deactivate);

        $parts = [];
        if ($result['ok'] !== []) {
            $parts[] = $verb . ' ' . count($result['ok']) . ' ' . (count($result['ok']) === 1 ? 'item' : 'items') . ' on TikTok Shop.';
        }
        $refused = count($ttIds) - count($result['ok']);
        if ($refused > 0) {
            $parts[] = $refused . ' refused: ' . ($result['error'] ?? 'no response') . '.';
        }
        if ($notOnTikTok > 0) {
            $parts[] = $notOnTikTok . ' skipped, not on TikTok Shop.';
        }
        $tone = $result['ok'] === [] ? 'error' : 'status';

        return redirect()->back()->with($tone, implode(' ', $parts));
    }

    public function pushDiscount(Request $request, int $productId, TikTokClient $client)
    {
        $request->validate(['action' => 'required|in:push,end']);
        $ending = $request->input('action') === 'end';

        $truth = $this->derivedTruth($productId);
        if (!$truth) {
            return redirect()->back()->with('error', 'This product is not on TikTok Shop, so there is no price to discount.');
        }
        $tiktokProductId = (string) $truth->tiktok_product_id;

        $listing = TikTokListing::query()->where('product_id', $productId)->first();

        $c = $this->creds();
        if (!$c) {
            return redirect()->back()->with('error', 'Missing TikTok settings.');
        }

        if ($ending) {
            if (!$listing || !$listing->tiktok_activity_id) {
                return redirect()->back()->with('error', 'No TikTok discount is recorded for this listing.');
            }

            $path = '/promotion/202309/activities/' . $listing->tiktok_activity_id . '/products';
            $result = $this->call($client, $c, 'DELETE', $path, ['product_ids' => [$tiktokProductId]]);
            if (!$this->callOk($result)) {
                return redirect()->back()->with('error', 'End discount failed: ' . $this->callMessage($result));
            }

            $listing->forceFill([
                'sale_percent' => null, 'sale_starts_at' => null,
                'sale_ends_at' => null, 'tiktok_activity_id' => null,
            ])->save();

            return redirect()->back()->with('status', 'Discount ended. Buyers pay the full price again.');
        }

        if (!$listing || $listing->sale_percent === null || !$listing->sale_starts_at || !$listing->sale_ends_at) {
            return redirect()->back()->with('error', 'Set the discount first: a percent off, a start and an end. Save, then push it.');
        }
        if (!$listing->sale_starts_at->isFuture()) {
            return redirect()->back()->with('error', 'The start time has passed. Move the start into the future and save first.');
        }

        /* One activity per window, shared: a product already holding an
         * activity for this exact window donates its object; otherwise
         * a new one is created and named after the window. */
        $activityId = (string) (TikTokListing::query()
            ->where('sale_starts_at', $listing->sale_starts_at)
            ->where('sale_ends_at', $listing->sale_ends_at)
            ->whereNotNull('tiktok_activity_id')
            ->value('tiktok_activity_id') ?? '');

        if ($activityId === '') {
            $result = $this->call($client, $c, 'POST', '/promotion/202309/activities', [
                'title' => mb_substr('VentaSync ' . $listing->sale_starts_at->format('M j') . ' to ' . $listing->sale_ends_at->format('M j'), 0, 40),
                'activity_type' => 'DIRECT_DISCOUNT',
                'begin_time' => $listing->sale_starts_at->timestamp,
                'end_time' => $listing->sale_ends_at->timestamp,
                'product_level' => 'PRODUCT',
            ]);
            $activityId = (string) ($result['body']['data']['activity_id'] ?? '');
            if (!$this->callOk($result) || $activityId === '') {
                return redirect()->back()->with('error', 'Discount push failed creating the promotion: ' . $this->callMessage($result));
            }
        }

        $result = $this->call($client, $c, 'PUT', '/promotion/202309/activities/' . $activityId . '/products', [
            'products' => [[
                'id' => $tiktokProductId,
                'discount' => (string) (int) $listing->sale_percent,
                'quantity_limit' => -1,
                'quantity_per_user' => -1,
            ]],
        ]);
        if (!$this->callOk($result)) {
            return redirect()->back()->with('error', 'Discount push failed adding the product: ' . $this->callMessage($result));
        }

        $listing->forceFill(['tiktok_activity_id' => $activityId])->save();

        return redirect()->back()->with('status',
            'Discount pushed: ' . (int) $listing->sale_percent . '% off from '
            . $listing->sale_starts_at->format('M j') . ' to ' . $listing->sale_ends_at->format('M j')
            . '. TikTok cuts the live price once the window opens.');
    }

    // ── Helpers ──────────────────────────────────────────────────

    /** The catalog product this page is about, or 404. */
    private function productOrFail(int $productId): object
    {
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $product = DB::table($pfx . 'product as p')
            ->leftJoin($pfx . 'product_description as pd', function ($join) use ($langId) {
                $join->on('pd.product_id', '=', 'p.product_id')->where('pd.language_id', $langId);
            })
            ->where('p.product_id', $productId)
            ->first(['p.product_id', 'p.model', 'p.sku', 'p.price', 'p.image', 'pd.name']);

        abort_unless((bool) $product, 404);

        return $product;
    }

    /**
     * The product's listing truth, derived across EVERY group's pivots:
     * any pivot holding a TikTok id answers, latest push first, and the
     * provenance names which group's push made it true.
     */
    private function derivedTruth(int $productId): ?object
    {
        $listing = TikTokListing::query()->where('product_id', $productId)->first();
        if ($listing && $listing->tiktok_product_id) {
            return (object) [
                'tiktok_product_id' => $listing->tiktok_product_id,
                'last_pushed_at' => $listing->last_pushed_at,
                'group_name' => str_starts_with((string) $listing->last_push_source, 'group:') ? substr((string) $listing->last_push_source, 6) : null,
                'source' => $listing->last_push_source,
            ];
        }

        return DB::table('tiktok_product_group_products as tp')
            ->join('tiktok_product_groups as tg', 'tg.id', '=', 'tp.tiktok_product_group_id')
            ->where('tp.product_id', $productId)
            ->whereNotNull('tp.tiktok_product_id')
            ->orderByDesc('tp.last_pushed_at')
            ->first(['tp.tiktok_product_id', 'tp.last_pushed_at', 'tg.name as group_name']);
    }

    /** Credentials for whichever mode the shop runs in, or null. */
    private function creds(): ?array
    {
        $s = TikTokSetting::query()->first();
        if (!$s) {
            return null;
        }
        $d = $s->decrypted();
        $sandbox = $s->mode === 'sandbox';

        return [
            'app_key' => $sandbox ? ($d->sandbox_app_key ?? '') : ($d->app_key ?? ''),
            'app_secret' => $sandbox ? ($d->sandbox_app_secret ?? '') : ($d->app_secret ?? ''),
            'token' => $sandbox ? ($d->sandbox_access_token ?? '') : ($d->access_token ?? ''),
            'shop_cipher' => $sandbox ? ($s->sandbox_shop_cipher ?? '') : ($s->shop_cipher ?? ''),
        ];
    }

    /** One signed promotion call, logged. */
    private function call(TikTokClient $client, array $c, string $method, string $path, array $body): array
    {
        try {
            $result = match ($method) {
                'POST' => $client->post($c['app_key'], $c['app_secret'], $c['token'], $path, [], $body, $c['shop_cipher']),
                'PUT' => $client->put($c['app_key'], $c['app_secret'], $c['token'], $path, [], $body, $c['shop_cipher']),
                'DELETE' => $client->delete($c['app_key'], $c['app_secret'], $c['token'], $path, [], $body, $c['shop_cipher']),
            };
        } catch (\Throwable $e) {
            $result = ['ok' => false, 'status' => 0, 'body' => ['message' => \App\Support\TransportError::plain($e, 'TikTok Shop')]];
        }

        TikTokApiLog::safeCreate([
            'pack' => 'tiktok.listings.discount', 'method' => $method,
            'api_path' => $path, 'auth_required' => true,
            'request_params' => $body,
            'response_status' => $result['status'] ?? 0,
            'ok' => (bool) ($result['ok'] ?? false),
            'response_body' => $result['body'] ?? [], 'user_id' => auth()->id(),
        ]);

        return $result;
    }

    private function callOk(array $result): bool
    {
        return ($result['ok'] ?? false) && (int) (($result['body']['code'] ?? -1)) === 0;
    }

    private function callMessage(array $result): string
    {
        return is_array($result['body'] ?? null)
            ? (string) ($result['body']['message'] ?? 'no response')
            : 'no response';
    }
}
