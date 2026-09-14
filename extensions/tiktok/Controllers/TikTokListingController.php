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
                if ($live !== null && ($live['skus'] ?? []) !== []) {
                    \Extensions\tiktok\Services\TikTok\TikTokListingStates::rememberHeld(
                        (int) (TikTokSetting::defaultStore()?->id ?? 0), $productId, array_column($live['skus'], 'seller_sku')
                    );
                }
            }
        }

        /* The listing as the page shows it: a blank category and missing
         * attribute answers filled from the product's group, and the group's
         * price rule when the listing has none of its own. Never saved. */
        $inherit = app(\Extensions\tiktok\Services\TikTok\TikTokInheritedSettings::class);
        $inheritedGroup = $inherit->forProducts([$productId])[$productId] ?? null;
        $shown = $inherit->fill($listing ?? (new TikTokListing())->forceFill(['product_id' => $productId]), $inheritedGroup)['listing'];
        if ($inheritedGroup && $shown->markup_percent === null && $shown->markup_fixed === null) {
            $groupModel = \Extensions\tiktok\Models\TikTokProductGroup::query()->find($inheritedGroup['group_id']);
            if ($groupModel) {
                $shown->markup_percent = $groupModel->markup_percent;
                $shown->markup_fixed = $groupModel->markup_fixed;
            }
        }

        // The category's attribute sheet, read once when never seen.
        $attributes = app(TikTokAttributes::class);
        $template = ($shown->tiktok_category_id && $c)
            ? $attributes->ensureTemplate((string) $shown->tiktok_category_id, app(TikTokClient::class), $c)
            : null;
        $category = $shown->tiktok_category_id
            ? TikTokCategory::query()->where('id', $shown->tiktok_category_id)->first(['id', 'name'])
            : null;
        $readiness = app(TikTokListingReadiness::class)->forProducts([$productId])[$productId] ?? ['ready' => false, 'missing' => []];

        /* The engine's answer for this product, computed AFTER the
         * write-through above so it reads the freshest mirror (#184 D).
         * The page renders its words, never a map of its own. */
        $listingState = app(\Extensions\tiktok\Services\TikTok\TikTokListingStates::class)
            ->forProducts([$productId])[$productId] ?? null;

        /* Variation coverage (Phase F, #184): the catalogue's SKUs vs the
         * sku-id map TikTok holds. The update payload carries every
         * catalogue variation (a combo without an id is created on
         * update), so PUSH UPDATE is the add-variation verb - this line
         * says so instead of leaving unlink-and-resync as folklore. */
        $covPfx = (string) config('catalog.prefix');
        $covStoreId = (int) (TikTokSetting::defaultStore()?->id ?? 0);
        $covSkus = \App\Integrations\Listings\ListingVariations::sold('tiktok', $covStoreId, [$productId], $covPfx)[$productId] ?? [];
        // The map where it lives: the listing row, else the latest group pivot.
        $covRaw = \Extensions\tiktok\Models\TikTokListing::query()->where('product_id', $productId)->value('tiktok_sku_id')
            ?? \Extensions\tiktok\Models\TikTokProductGroupProduct::query()->onStore($covStoreId)->where('product_id', $productId)
                ->whereNotNull('tiktok_product_id')->orderByDesc('last_pushed_at')->value('tiktok_sku_id');
        $covMap = \Extensions\tiktok\Services\TikTok\TikTokVariationPush::existingIds($covRaw);
        $covKeys = array_change_key_case(array_flip(array_map('strval', array_keys($covMap))), CASE_LOWER);
        $coverage = [
            'total' => count($covSkus),
            'linked' => count(array_filter($covSkus, fn ($sku) => isset($covKeys[strtolower($sku)]))),
            'missing' => array_values(array_filter($covSkus, fn ($sku) => !isset($covKeys[strtolower($sku)]))),
        ];

        // The Product group band: this store's groups and each one's values.
        $bandGroups = \Extensions\tiktok\Models\TikTokProductGroup::query()
            ->where('tiktok_setting_id', $covStoreId)
            ->orderBy('name')
            ->get(['id', 'name', 'tiktok_category_id', 'markup_percent', 'markup_fixed', 'watermark_template_id']);
        $bandCategoryNames = TikTokCategory::query()
            ->whereIn('id', $bandGroups->pluck('tiktok_category_id')->filter()->unique()->values()->all())
            ->pluck('name', 'id');
        $bandAnswers = \Extensions\tiktok\Models\TikTokProductGroupAttribute::query()
            ->whereIn('tiktok_product_group_id', $bandGroups->pluck('id')->all())
            ->get(['tiktok_product_group_id', 'attribute_key', 'value'])
            ->groupBy('tiktok_product_group_id');
        $groupBand = [
            'current' => \App\Integrations\Listings\ListingGroup::current([
                'pivot' => 'tiktok_product_group_products', 'fk' => 'tiktok_product_group_id',
                'groups' => 'tiktok_product_groups', 'storeFk' => 'tiktok_setting_id',
                'storeId' => $covStoreId,
            ], $productId),
            'groups' => $bandGroups->map(fn ($g) => [
                'id' => (int) $g->id,
                'name' => (string) $g->name,
                'url' => route('ext.tiktok.product-groups.products', $g->id),
            ])->values()->all(),
            'values' => $bandGroups->mapWithKeys(function ($g) use ($bandCategoryNames, $bandAnswers) {
                $categoryId = trim((string) ($g->tiktok_category_id ?? ''));
                $categoryName = $categoryId !== '' ? ($bandCategoryNames[$categoryId] ?? null) : null;

                return [(int) $g->id => [
                    'category' => $categoryId !== ''
                        ? ['id' => $categoryId, 'label' => $categoryName !== null ? $categoryName . ' (' . $categoryId . ')' : $categoryId]
                        : null,
                    'markup_percent' => $g->markup_percent,
                    'markup_fixed' => $g->markup_fixed,
                    'watermark' => $g->watermark_template_id,
                    'attributes' => ($bandAnswers->get($g->id) ?? collect())
                        ->filter(fn ($a) => trim((string) $a->value) !== '')
                        ->pluck('value', 'attribute_key')
                        ->all(),
                ]];
            })->all(),
        ];

        return view('ext-tiktok::listings.edit', \App\Integrations\Listings\ListingImages::cardData($productId, $listing->image_order ?? null, $listing ?? null, \App\Services\Media\ListingWatermark::groupTemplateId('tiktok_product_groups', 'tiktok_product_group_products', 'tiktok_product_group_id', $productId, 'tiktok_setting_id', (int) ($listing->tiktok_setting_id ?? 0) ?: null)) + [
            'coverage' => $coverage,
            ...\App\Integrations\Listings\ListingVariations::cardData(
                'tiktok', $covStoreId, $productId,
                $live !== null ? collect($live['skus'] ?? [])->pluck('seller_sku')->all() : (empty($listing->tiktok_product_id ?? null) ? [] : null)
            ),
            'listingState' => $listingState,
            'product' => $product,
            'listing' => $listing,
            'truth' => $truth,
            'live' => $live,
            'liveError' => $liveError,
            'category' => $category,
            'attrTemplate' => $template,
            'attrRows' => $attributes->rows($template),
            'shown' => $shown,
            'savedAttributes' => $shown->attribute_values ?? [],
            'readinessMissing' => $readiness['missing'],
            // The gaps by CODE, so the page can link each one to where it is fixed.
            'readiness' => $readiness,
            'groupBand' => $groupBand,
            'storeId' => (int) (TikTokSetting::defaultStore()?->id ?? 0),
            'descriptionTemplates' => \App\Models\DescriptionTemplate::forStore('tiktok', (int) (TikTokSetting::defaultStore()?->id ?? 0)),
        ]);
    }

    public function update(Request $request, int $productId)
    {
        $this->productOrFail($productId);
        $coreWords = \App\Support\Catalog\CoreWords::of($productId);

        $data = $request->validate([
            'tiktok_category_id' => 'nullable|string|max:64|exists:tiktok_categories,id',
            'brand_id' => 'nullable|string|max:64',
            'brand_name' => 'nullable|string|max:191',
            'markup_percent' => 'nullable|numeric|min:-100|max:1000',
            'markup_fixed' => 'nullable|numeric|min:-1000000|max:1000000',
            'title' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:10000',
            'description_prefix_id' => 'nullable|integer',
            'watermark_template_id' => 'nullable|integer',
            'watermark_all_images' => 'nullable|boolean',
            'description_suffix_id' => 'nullable|integer',
            'attributes' => 'nullable|array',
            'attributes.*' => 'nullable|string|max:500',
            'image_order' => 'nullable|string|max:20000',
            ...\App\Integrations\Listings\ListingVariations::rules(),
            ...\App\Integrations\Listings\ListingGroup::rules(),
            // TikTok takes the percent itself and only whole percents.
        ]);

        /* The pictures this listing sends, in the order the card arranged
         * them. Only a save that CARRIED the arrangement may change it:
         * the input is invisible, so a caller that does not send it must
         * not be read as one that cleared it. */
        $images = $request->has('image_order')
            ? ['image_order' => \App\Integrations\Listings\ListingImages::submitted($productId, $data['image_order'] ?? null)]
            : [];

        /* Content fields are OVERRIDES: an emptied field returns to null,
         * which means "follows the catalog". Saving here never writes the
         * core product. */
        $blank = fn ($v) => ($v === null || trim((string) $v) === '') ? null : $v;
        /* The group dropdown: picking a group moves the product into it,
         * No group takes it out. */
        $picked = \App\Integrations\Listings\ListingGroup::submitted($request);
        if ($picked['present']) {
            \App\Integrations\Listings\ListingGroup::assign([
                'pivot' => 'tiktok_product_group_products', 'fk' => 'tiktok_product_group_id',
                'groups' => 'tiktok_product_groups', 'storeFk' => 'tiktok_setting_id',
                'storeId' => (int) (TikTokSetting::defaultStore()?->id ?? 0),
            ], $productId, $picked['id']);
        }
        $currentDescription = TikTokListing::query()->where('product_id', $productId)->value('description');
        TikTokListing::query()->updateOrCreate(
            ['product_id' => $productId],
            $images + [
                'tiktok_category_id' => $blank($data['tiktok_category_id'] ?? null),
                'brand_id' => $blank($data['brand_id'] ?? null),
                'brand_name' => $blank($data['brand_id'] ?? null) ? $blank($data['brand_name'] ?? null) : null,
                'markup_percent' => $blank($data['markup_percent'] ?? null),
                'markup_fixed' => $blank($data['markup_fixed'] ?? null),
                /* The Content boxes are pre-filled with the catalog's words
                 * (the owner, 2026-09-07); text still equal to them is stored as
                 * null, so pre-filling never freezes a listing into a copy. */
                'title' => \App\Integrations\Listings\ListingContent::overrideOrNull($data['title'] ?? null, $coreWords['title']),
                'description_prefix_id' => ((int) ($data['description_prefix_id'] ?? 0)) ?: null,
                ...\App\Integrations\Listings\ListingImages::submittedWatermark($request),
                'description_suffix_id' => ((int) ($data['description_suffix_id'] ?? 0)) ?: null,
                'description' => \App\Integrations\Listings\ListingContent::descriptionToSave($data['description'] ?? null, $coreWords['description'], $currentDescription, $request->input('description_edited')),
                'attribute_values' => array_filter(
                    array_map(fn ($v) => trim((string) $v), $data['attributes'] ?? []),
                    fn ($v) => $v !== ''
                ) ?: null,
            ]
        );

        // A value equal to the group's follows the group from now on.
        $followed = app(\Extensions\tiktok\Services\TikTok\TikTokInheritedSettings::class)->forProducts([$productId])[$productId] ?? null;
        $savedRow = TikTokListing::query()->where('product_id', $productId)->first();
        if ($followed && $savedRow) {
            $groupModel = \Extensions\tiktok\Models\TikTokProductGroup::query()->find($followed['group_id']);
            if (is_array($savedRow->attribute_values)) {
                $savedRow->attribute_values = \App\Integrations\Listings\ListingGroup::ownAnswers($savedRow->attribute_values, (array) ($followed['attributes'] ?? [])) ?: null;
            }
            \App\Integrations\Listings\ListingGroup::follow($savedRow, [
                'tiktok_category_id' => $followed['category_id'],
                'watermark_template_id' => $groupModel?->watermark_template_id,
                'markup_percent' => $groupModel?->markup_percent,
                'markup_fixed' => $groupModel?->markup_fixed,
            ], [['markup_percent', 'markup_fixed']]);
        }

        if (($sellHere = \App\Integrations\Listings\ListingVariations::submitted($request)) !== null) {
            \App\Integrations\Listings\ListingVariations::save('tiktok', (int) (TikTokSetting::defaultStore()?->id ?? 0), $productId, $sellHere);
        }

        $back = \App\Support\BackTo::safe($request->input('back'), '');

        /* Save and push (parity with Lazada and Shopee, the owner 2026-09-06):
         * the save half runs first so a refusal never loses the typing;
         * readiness is re-read after the save and named, and only a ready,
         * unlisted product goes on to the push. */
        if ($request->boolean('push_after')) {
            if (TikTokListing::query()->where('product_id', $productId)->whereNotNull('tiktok_product_id')->exists()) {
                return redirect()->route('ext.tiktok.listings.edit', $back !== '' ? [$productId, 'back' => $back] : $productId)
                    ->with('status', 'Listing saved.');
            }
            $readiness = app(TikTokListingReadiness::class)->forProducts([$productId])[$productId] ?? ['ready' => false, 'missing' => ['its readiness could not be checked']];
            if (!$readiness['ready']) {
                return redirect()->route('ext.tiktok.listings.edit', $back !== '' ? [$productId, 'back' => $back] : $productId)
                    ->with('error', 'Saved, but not pushed: this listing still needs ' . implode(', ', $readiness['missing']) . '. Set what is missing and try again.');
            }

            return app(TikTokProductController::class)->pushDirect($request, $productId, app(TikTokClient::class));
        }

        return redirect()->route('ext.tiktok.listings.edit', $back !== '' ? [$productId, 'back' => $back] : $productId)
            ->with('status', 'Listing saved.');
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
            $this->states()->recordOutcome($productId, \App\Integrations\Listings\CatalogGaps::refusal($readiness, 'update'));

            return redirect()->back()->with('error', 'Update failed: this listing still needs ' . implode(', ', $readiness['missing']) . '.');
        }
        $listing = TikTokListing::query()->where('product_id', $productId)->first();
        // The group's presets fill the blanks, the way readiness judged it.
        $inherit = app(\Extensions\tiktok\Services\TikTok\TikTokInheritedSettings::class);
        $listing = $inherit->fill($listing, $inherit->forProducts([$productId])[$productId] ?? null)['listing'];
        $attributes = app(TikTokAttributes::class);
        $template = $attributes->ensureTemplate((string) $listing->tiktok_category_id, $client, $c);
        $opts = [
            'category_id' => (string) $listing->tiktok_category_id,
            'brand_id' => $listing->brand_id,
            'attributes' => $attributes->payload($attributes->rows($template), $listing->attribute_values ?? []),
            'priceFor' => \Extensions\tiktok\Services\TikTok\TikTokInheritedSettings::priceRule($listing, $inherit->groupModel($productId)),
            'warehouse_id' => $c['warehouse_id'] ?: null,
            'title' => $listing->title, 'description' => $listing->description,
            'image_order' => $listing->image_order,
        ];
        $erp = DB::table((string) config('catalog.prefix') . 'product as p')
            ->leftJoin((string) config('catalog.prefix') . 'product_description as pd', function ($j) {
                $j->on('pd.product_id', '=', 'p.product_id')->where('pd.language_id', (int) config('catalog.default_language_id'));
            })
            ->where('p.product_id', $productId)
            ->first(['p.product_id', 'pd.name', 'pd.description', 'p.model', 'p.sku', 'p.price', 'p.quantity', 'p.image', 'p.weight', 'p.length', 'p.width', 'p.height']);

        $outcome = app(TikTokProductPush::class)->edit($erp, (string) $truth->tiktok_product_id, $truth->tiktok_sku_id ?? null, $opts, $c, $client);
        if (!$outcome['ok']) {
            $this->states()->recordOutcome($productId, (string) $outcome['message']);

            return redirect()->back()->with('error', 'Update failed: ' . $outcome['message']);
        }
        TikTokListing::recordPush($productId, (string) $truth->tiktok_product_id, $outcome['sku_ids'] ?? ($truth->tiktok_sku_id ?? null), 'listing', isset($outcome['sku_ids']));
        $this->states()->recordOutcome($productId, null);
        $warning = $outcome['warning'] ?? null;

        return redirect()->back()->with($warning ? 'warning' : 'status', 'Update pushed.' . ($warning ? ' ' . $warning : ''));
    }

    /** The listing-state engine, pinned to the store this request acts for. */
    private function states(): \Extensions\tiktok\Services\TikTok\TikTokListingStates
    {
        $engine = app(\Extensions\tiktok\Services\TikTok\TikTokListingStates::class);
        $store = TikTokSetting::defaultStore();

        return $store ? $engine->forStore($store) : $engine;
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
            $this->states()->recordOutcome($productId, ($deactivate ? 'Deactivate: ' : 'Activate: ') . $result['error']);

            return redirect()->back()->with('error', ($deactivate ? 'Deactivate' : 'Activate') . ' failed: ' . $result['error']);
        }
        $this->states()->recordOutcome($productId, null);

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
     * @return array{ok: list<string>, failed: list<string>, error: ?string} failed is the refused call's ids; later ones were not sent
     */
    private function toggleProducts(TikTokClient $client, array $c, array $tiktokProductIds, bool $deactivate): array
    {
        $path = '/product/202309/products/' . ($deactivate ? 'deactivate' : 'activate');
        $ok = [];
        foreach (array_chunk(array_values(array_unique($tiktokProductIds)), 50) as $chunk) {
            $result = $this->call($client, $c, 'POST', $path, ['product_ids' => $chunk]);
            if (!$this->callOk($result)) {
                return ['ok' => $ok, 'failed' => $chunk, 'error' => $this->callMessage($result)];
            }
            TikTokListing::query()->whereIn('tiktok_product_id', $chunk)
                ->update(['live_status' => $deactivate ? 'SELLER_DEACTIVATED' : 'ACTIVATE', 'live_checked_at' => now()]);
            array_push($ok, ...$chunk);
        }

        return ['ok' => $ok, 'failed' => [], 'error' => null];
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
        $productsByTt = [];
        foreach ($ids as $id) {
            $truth = $this->derivedTruth($id);
            if ($truth && $truth->tiktok_product_id) {
                $ttIds[] = (string) $truth->tiktok_product_id;
                $productsByTt[(string) $truth->tiktok_product_id][] = $id;
            }
        }
        $notOnTikTok = count($ids) - count($ttIds);
        if ($ttIds === []) {
            return redirect()->back()->with('error', 'None of the selected products is on TikTok Shop, so there is nothing to ' . ($deactivate ? 'deactivate' : 'activate') . '.');
        }

        $result = $this->toggleProducts($client, $c, $ttIds, $deactivate);
        $states = $this->states();
        foreach ($result['ok'] as $ttId) {
            foreach ($productsByTt[$ttId] ?? [] as $pid) {
                $states->recordOutcome($pid, null);
            }
        }
        foreach ($result['failed'] as $ttId) {
            foreach ($productsByTt[$ttId] ?? [] as $pid) {
                $states->recordOutcome($pid, ($deactivate ? 'Deactivate: ' : 'Activate: ') . $result['error']);
            }
        }

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
                'tiktok_sku_id' => $listing->tiktok_sku_id,
                'last_pushed_at' => $listing->last_pushed_at,
                'group_name' => str_starts_with((string) $listing->last_push_source, 'group:') ? substr((string) $listing->last_push_source, 6) : null,
                'source' => $listing->last_push_source,
            ];
        }

        return DB::table('tiktok_product_group_products as tp')
            ->join('tiktok_product_groups as tg', 'tg.id', '=', 'tp.tiktok_product_group_id')
            ->where('tg.tiktok_setting_id', (int) (TikTokSetting::defaultStore()?->id ?? 0))
            ->where('tp.product_id', $productId)
            ->whereNotNull('tp.tiktok_product_id')
            ->orderByDesc('tp.last_pushed_at')
            ->first(['tp.tiktok_product_id', 'tp.tiktok_sku_id', 'tp.last_pushed_at', 'tg.name as group_name']);
    }

    /** Credentials for whichever mode the shop runs in, or null. */
    private function creds(): ?array
    {
        $s = TikTokSetting::defaultStore();
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
            'warehouse_id' => $sandbox ? ($s->sandbox_warehouse_id ?? '') : ($s->warehouse_id ?? ''),
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
        // The one reader that knows where every channel hides its reason.
        return \App\Support\MarketplaceAnswer::errorText('TikTok Shop', $result);
    }
}
