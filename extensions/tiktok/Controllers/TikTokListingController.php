<?php

namespace Extensions\tiktok\Controllers;

use App\Http\Controllers\Controller;
use Extensions\tiktok\Models\TikTokApiLog;
use Extensions\tiktok\Models\TikTokListing;
use Extensions\tiktok\Models\TikTokSetting;
use Extensions\tiktok\Services\TikTok\TikTokClient;
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
    public function edit(int $productId)
    {
        $product = $this->productOrFail($productId);

        return view('ext-tiktok::listings.edit', [
            'product' => $product,
            'listing' => TikTokListing::query()->where('product_id', $productId)->first(),
            'truth' => $this->derivedTruth($productId),
        ]);
    }

    public function update(Request $request, int $productId)
    {
        $this->productOrFail($productId);

        $data = $request->validate([
            // TikTok takes the percent itself and only whole percents.
            'sale_percent' => 'nullable|integer|min:1|max:99',
            'sale_starts_at' => 'nullable|date|required_with:sale_percent',
            'sale_ends_at' => 'nullable|date|required_with:sale_percent|after:sale_starts_at',
        ]);

        $hasRule = ($data['sale_percent'] ?? null) !== null;
        TikTokListing::query()->updateOrCreate(
            ['product_id' => $productId],
            [
                'sale_percent' => $hasRule ? $data['sale_percent'] : null,
                'sale_starts_at' => $hasRule ? ($data['sale_starts_at'] ?? null) : null,
                'sale_ends_at' => $hasRule ? ($data['sale_ends_at'] ?? null) : null,
            ]
        );

        return redirect()->route('ext.tiktok.listings.edit', $productId)
            ->with('status', $hasRule ? 'Discount saved. Push it to TikTok Shop when ready.' : 'Discount cleared on this record.');
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
            $result = ['ok' => false, 'status' => 0, 'body' => ['message' => $e->getMessage()]];
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
