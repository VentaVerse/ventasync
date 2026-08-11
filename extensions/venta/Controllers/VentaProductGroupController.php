<?php

namespace Extensions\venta\Controllers;

use App\Http\Controllers\Controller;
use App\Services\ActivityLogger;
use Extensions\venta\Models\VentaBrand;
use Extensions\venta\Models\VentaCategory;
use Extensions\venta\Models\VentaProductGroup;
use Extensions\venta\Models\VentaProductGroupProduct;
use Extensions\venta\Models\VentaProductLink;
use Extensions\venta\Models\VentaSetting;
use Extensions\venta\Services\Venta\VentaClient;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class VentaProductGroupController extends Controller
{
    public function navRedirect()
    {
        $store = VentaSetting::where('enabled', true)->first();
        if (!$store) {
            return redirect()->route('ext.venta.index')->with('error', 'No enabled Venta store. Add one first.');
        }
        return redirect()->route('ext.venta.product-groups.index', $store->id);
    }

    /**
     * Turn Venta's refusal into something the operator can act on.
     *
     * One refusal in particular was a dead end. When the ERP holds no link it
     * takes the CREATE branch, and if Venta already has that SKU it answers
     * 422 "The sku has already been taken." The operator then had no move
     * left on that row:
     *
     *   Push            creates, and 422s again, forever
     *   Update          never offered, because the page sees no link
     *   Delete from Venta   refuses: "not linked, nothing to delete"
     *   Match Venta ID  the actual exit, and nothing said so
     *
     * All three visible actions failed and the one that worked was
     * unmentioned. The raw response was dumped in as JSON, so the operator was
     * reading Laravel's validation envelope rather than a sentence about their
     * product.
     *
     * The underlying mistake is the mirror of the stale-link one: absence of a
     * local link row was read as absence from the marketplace. A link row is
     * not evidence about Venta in either direction.
     */
    private function pushFailureText(array $result, string $sku): string
    {
        $raw = json_encode($result['body'] ?? []);

        /*
         * Venta refuses to create a product at all when it cannot fetch the
         * images, so the whole push is rejected rather than the product
         * landing without pictures. On any installation reachable only at a
         * private address that is permanent: its SafeUrlFetcher blocks private
         * and reserved ranges outright. Worth saying in words, because the raw
         * envelope reads as a validation error the operator might try to fix
         * in the catalogue.
         */
        if (stripos($raw, 'images_unavailable') !== false) {
            return 'Venta would not create this product because it could not fetch its images. '
                .'Venta only accepts images from a publicly reachable address, and this ERP is on a '
                .'private one, so creating products there is not possible from this network.';
        }

        if (stripos($raw, 'sku has already been taken') === false) {
            return $raw;
        }

        return "Venta already has a product with SKU {$sku}, but this catalogue product is not "
            ."linked to it. Use Match Venta ID on this row to link them, then push again.";
    }

    /**
     * Read the per-image verdict Venta returns with every push.
     *
     * Its response carries `images` (the ones it downloaded and kept) and
     * `images_failed` (each rejected url with the reason it was rejected).
     * Nothing here read either, so a push whose product was created and whose
     * images were every one refused came back as "Created: 1, Updated: 0,
     * Failed: 0" - green, no failures, no mention of images. The product
     * really was created, so the count was not wrong; it was half the answer,
     * and the missing half was the part the operator was looking for.
     *
     * Venta names the reason precisely, so the reason is what gets carried up
     * rather than a count. On this installation every rejection reads "Host
     * '192.168.0.10' resolves to a disallowed address", which is its SSRF
     * guard refusing a private network address and is the whole explanation
     * for why images never arrive from a sandbox.
     *
     * @param  array<string, mixed>  $result   the client's response envelope
     * @param  int                   $pid      the ERP product just pushed
     * @param  int                   $sent     running total offered to Venta
     * @param  int                   $stored   running total Venta kept
     * @param  array<string, int>    $reasons  reason => how many images it cost
     */
    private function collectImageVerdict(array $result, int $pid, int &$sent, int &$stored, array &$reasons): void
    {
        $body = $result['body'] ?? [];
        $body = is_array($body) ? ($body['data'] ?? $body) : [];

        $kept = $body['images'] ?? null;
        $failed = $body['images_failed'] ?? null;

        // A response with neither key is from an older Venta that does not
        // report on images at all. Silence there is not evidence of failure,
        // so nothing is claimed.
        if ($kept === null && $failed === null) {
            return;
        }

        $keptCount = is_array($kept) ? count($kept) : (int) $kept;
        $failedList = is_array($failed) ? $failed : [];

        $stored += $keptCount;
        $sent += $keptCount + count($failedList);

        foreach ($failedList as $entry) {
            $reason = is_array($entry)
                ? trim((string) ($entry['reason'] ?? 'no reason given'))
                : trim((string) $entry);

            if ($reason === '') {
                $reason = 'no reason given';
            }

            // Grouped by reason, not listed per url. Six images refused for
            // one reason is one thing an operator needs to know, not six.
            $reasons[$reason] = ($reasons[$reason] ?? 0) + 1;
        }
    }

    /**
     * Write a product's status to EVERY group of this store that holds it.
     *
     * Not just the group the operator happens to be standing in. Whether a
     * product is on Venta is one fact, and it was stored once per group, so
     * the same product read differently on different pages: a push that failed
     * in group X stayed red there forever after a successful push from group
     * Y, and - worse - a success recorded in Y left a green badge on X hiding
     * a real failure.
     *
     * Scoped to this STORE's groups. Venta is multi-store and every store is a
     * separate live shop; a result against one says nothing about another.
     *
     * Every status write goes through here. Fanning out is not a rule for the
     * next author to remember, because that rule is what was already being
     * forgotten.
     */
    private function writeProductStatus(VentaProductGroup $g, int $productId, array $attributes): void
    {
        \App\Support\ChannelProductStatus::write(
            'venta_product_group_products',
            'venta_product_group_id',
            VentaProductGroup::where('venta_setting_id', $g->venta_setting_id)->pluck('id')->all(),
            $productId,
            $attributes
        );
    }

    /**
     * The SKUs this product could be known by on Venta, best first.
     */
    private function candidateSkus(string $pfx, int $productId): array
    {
        $prod = DB::table($pfx.'product')->where('product_id', $productId)->first(['sku', 'model']);

        if (! $prod) {
            return [];
        }

        $skus = [];
        foreach ([$prod->sku ?? '', $prod->model ?? ''] as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate !== '' && ! in_array($candidate, $skus, true)) {
                $skus[] = $candidate;
            }
        }

        return $skus;
    }

    /**
     * Ask Venta what is actually true, and make the local link agree.
     *
     * This is the piece the page never had. Every action on a row decided what
     * to do from a link row - the ERP's own memory - which can be wrong in both
     * directions, and was. A product deleted in Venta's admin left the ERP
     * believing it was still there; a database restored from another
     * environment left links pointing at ids that meant something else.
     *
     * Nothing else in this controller may guess. Reconcile first, act second.
     *
     * Returns one of:
     *   confirmed    the link was right and Venta still has it
     *   adopted      Venta had it, we had no link, now we do
     *   repointed    Venta had it under a different id than we recorded
     *   lost         Venta no longer has it; the stale link has been dropped
     *   new          neither side has it linked; nothing to reconcile
     *   blocked      another catalogue product holds the id Venta gave us
     *   unreachable  Venta could not answer; nothing was concluded or changed
     *
     * The last one exists because the first draft did not have it, and that
     * was dangerous. A 404 and a dead connection both left the search empty,
     * so a store that was merely DOWN read as a store that had deleted
     * everything, and the 'lost' branch below would have deleted every link in
     * the group. Absence of an answer is not an answer. Only an explicit 404
     * from a reachable store is allowed to mean the product is gone.
     *
     * @return array{state: string, venta_id: int|null, sku: string|null, message: string|null}
     */
    private function reconcileLink(VentaClient $client, int $store, int $productId, string $pfx): array
    {
        $link = VentaProductLink::where('venta_setting_id', $store)
            ->where('product_id', $productId)
            ->first();

        $found = null;
        $foundSku = null;
        $denied = null;

        foreach ($this->candidateSkus($pfx, $productId) as $sku) {
            $result = $client->get("products/{$sku}");
            $status = (int) ($result['status'] ?? 0);

            if ($result['ok'] ?? false) {
                $body = $result['body']['data'] ?? $result['body'] ?? [];
                $id = (int) ($body['id'] ?? 0);

                if ($id > 0) {
                    $found = $id;
                    $foundSku = $sku;
                    break;
                }

                continue;
            }

            // 404 is Venta answering. Anything else is Venta not answering:
            // status 0 is a transport failure, 5xx is the store falling over,
            // 401/403 mean the credentials stopped working. None of those are
            // evidence that the product is gone, so none may delete a link.
            if ($status !== 404) {
                $denied = $status === 0
                    ? 'Venta could not be reached, so nothing was checked or changed.'
                    : "Venta answered {$status} instead of saying whether the product exists, so nothing was changed.";
            }
        }

        if ($found === null && $denied !== null) {
            return ['state' => 'unreachable', 'venta_id' => null, 'sku' => null, 'message' => $denied];
        }

        // Venta does not have it under any of our SKUs.
        if ($found === null) {
            if ($link) {
                // The link is a memory of something that is no longer there.
                // Dropping it is what lets the push below create rather than
                // send an update Venta can only answer 404 to.
                $link->delete();

                return ['state' => 'lost', 'venta_id' => null, 'sku' => null, 'message' => null];
            }

            return ['state' => 'new', 'venta_id' => null, 'sku' => null, 'message' => null];
        }

        if ($link && (int) $link->venta_product_id === $found) {
            return ['state' => 'confirmed', 'venta_id' => $found, 'sku' => $foundSku, 'message' => null];
        }

        $wasPointingElsewhere = $link !== null;

        if ($clash = $this->linkProductTo($store, $productId, $found, $foundSku)) {
            return ['state' => 'blocked', 'venta_id' => $found, 'sku' => $foundSku, 'message' => $clash];
        }

        return [
            'state' => $wasPointingElsewhere ? 'repointed' : 'adopted',
            'venta_id' => $found,
            'sku' => $foundSku,
            'message' => null,
        ];
    }

    /**
     * Point an ERP product at a Venta product, or say who is in the way.
     *
     * A unique key on (venta_setting_id, venta_product_id) enforces one Venta
     * product per ERP product. A STALE link held by a different product
     * therefore blocks this write, and on a database copied from another
     * environment that happens constantly: the copy carries links to Venta
     * products this environment never had, and matching by SKU keeps landing
     * on ids those dead links already claim.
     *
     * Unhandled it is a 500. It was one, three times over, because all three
     * paths that write a link - Match, Match in bulk, and Push - each had
     * their own copy of this updateOrCreate and only the one inside Push was
     * ever guarded. One definition now, so a fourth caller cannot reintroduce
     * it.
     *
     * The conflicting link is NOT taken over. It may be perfectly good, and
     * stealing it would leave that product silently pointing at nothing.
     *
     * @return string|null null when linked, or the reason it could not be
     */
    private function linkProductTo(int $store, int $productId, $ventaProductId, ?string $sku): ?string
    {
        $conflict = VentaProductLink::where('venta_setting_id', $store)
            ->where('venta_product_id', $ventaProductId)
            ->where('product_id', '!=', $productId)
            ->first();

        if ($conflict) {
            return "Venta product {$ventaProductId} is already linked to catalogue product "
                ."#{$conflict->product_id}. Unlink that product first, then try this one again.";
        }

        VentaProductLink::updateOrCreate(
            ['venta_setting_id' => $store, 'product_id' => $productId],
            ['venta_product_id' => $ventaProductId, 'sku' => $sku]
        );

        return null;
    }

    private function store_(int $storeId): VentaSetting
    {
        // Allow navigation into disabled stores so operators can finish
        // configuring them after Add Store. READING is all this grants;
        // anything that talks to Venta must go through storeForWrite_().
        return VentaSetting::findOrFail($storeId);
    }

    /**
     * The store for an action that TALKS TO VENTA.
     *
     * The comment above this method used to claim that "sync/cron paths use
     * their own enabled-filtered lookups so disabled stores never reach the
     * marketplace API". Half true, and the dangerous half was false: the three
     * console commands do filter on enabled, but every push in this controller
     * - products, stock, prices, delete, and both id matchers - resolved its
     * store through store_(), which is findOrFail with no enabled test at all.
     *
     * Venta has no sandbox. base_url on these rows is the real storefront
     * (https://shop-a.example, https://shop-b.example), so a disabled store
     * was one button press away from a write to a live shop, by an operator
     * who had switched it off precisely to stop that.
     *
     * Disabled means disabled. Returns null and the caller refuses.
     */
    private function storeForWrite_(int $storeId): ?VentaSetting
    {
        $setting = VentaSetting::findOrFail($storeId);

        return $setting->enabled ? $setting : null;
    }

    private function productsRedirect(int $store, int $group)
    {
        $return = request()->input('_return');
        if ($return && str_starts_with($return, '/')) {
            return redirect($return);
        }
        return redirect()->route('ext.venta.product-groups.products', [$store, $group]);
    }

    // ── Group CRUD ──────────────────────────────────────────

    public function index(int $store)
    {
        $setting = $this->store_($store);

        $groups = VentaProductGroup::where('venta_setting_id', $store)->orderByDesc('id')->get();

        $productCounts = [];
        foreach ($groups as $g) {
            $productCounts[$g->id] = $g->products()->count();
        }

        return view('ext-venta::product-groups.index', [
            'setting'       => $setting,
            'groups'        => $groups,
            'productCounts' => $productCounts,
        ]);
    }

    public function create(int $store)
    {
        $setting = $this->store_($store);
        return $this->form($setting, new VentaProductGroup(['venta_setting_id' => $store]), 'create');
    }

    public function edit(int $store, int $group)
    {
        $setting = $this->store_($store);
        $g = VentaProductGroup::where('venta_setting_id', $store)->findOrFail($group);
        return $this->form($setting, $g, 'edit');
    }

    private function form(VentaSetting $setting, VentaProductGroup $group, string $mode)
    {
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $catalogCategories = DB::table($pfx . 'category_description')
            ->where('language_id', $langId)
            ->orderBy('name')
            ->get(['category_id', 'name']);

        $manufacturers = DB::table($pfx . 'manufacturer')
            ->orderBy('name')
            ->get(['manufacturer_id', 'name']);

        // Load current group products for the dual-listbox
        // On validation failure, restore from old('product_ids') so selections aren't lost
        $oldProductIds = old('product_ids');
        $groupProducts = collect();

        if (!empty($oldProductIds)) {
            $productIds = array_filter(array_map('intval', (array) $oldProductIds));
        } elseif ($group->exists) {
            $productIds = $group->products()->pluck('product_id')->toArray();
        } else {
            $productIds = [];
        }

        if (!empty($productIds)) {
            $groupProducts = DB::table($pfx . 'product as p')
                ->join($pfx . 'product_description as pd', function ($j) use ($langId) {
                    $j->on('p.product_id', '=', 'pd.product_id')
                        ->where('pd.language_id', '=', $langId);
                })
                ->whereIn('p.product_id', $productIds)
                ->select('p.product_id', 'pd.name', 'p.sku', 'p.model', 'p.quantity')
                ->orderBy('pd.name')
                ->get();
        }

        return view('ext-venta::product-groups.form', [
            'setting'           => $setting,
            'group'             => $group,
            'mode'              => $mode,
            'catalogCategories' => $catalogCategories,
            'manufacturers'     => $manufacturers,
            'groupProducts'     => $groupProducts,
        ]);
    }

    public function store(Request $request, int $store)
    {
        $this->store_($store);

        $data = $request->validate([
            'name'              => 'required|string|max:255',
            'venta_category_id' => 'nullable|integer',
            'venta_brand_id'    => 'nullable|integer',
            'markup_percent'    => 'nullable|numeric|min:0',
            'markup_fixed'      => 'nullable|numeric|min:0',
        ]);

        $group = VentaProductGroup::create([
            'venta_setting_id'     => $store,
            'name'                 => $data['name'],
            'venta_category_id'    => $data['venta_category_id'] ?? null,
            'venta_brand_id'       => $data['venta_brand_id'] ?? null,
            'catalog_category_ids' => null,
            'manufacturer_ids'     => null,
            'markup_percent'       => $data['markup_percent'] ?? 0,
            'markup_fixed'         => $data['markup_fixed'] ?? 0,
        ]);

        // Add products from dual-listbox
        $productIds = array_filter(array_map('intval', (array) $request->input('product_ids', [])));
        foreach ($productIds as $pid) {
            VentaProductGroupProduct::create([
                'venta_product_group_id' => $group->id,
                'product_id'             => $pid,
            ]);
        }

        ActivityLogger::log('created', 'Venta Product Group', $group->id, $group->name);

        return redirect()->route('ext.venta.product-groups.edit', [$store, $group->id])
            ->with('status', 'Product group created with ' . count($productIds) . ' product(s).');
    }

    public function update(Request $request, int $store, int $group)
    {
        $this->store_($store);
        $g = VentaProductGroup::where('venta_setting_id', $store)->findOrFail($group);

        $data = $request->validate([
            'name'              => 'required|string|max:255',
            'venta_category_id' => 'nullable|integer',
            'venta_brand_id'    => 'nullable|integer',
            'markup_percent'    => 'nullable|numeric|min:0',
            'markup_fixed'      => 'nullable|numeric|min:0',
        ]);

        $g->update([
            'name'                 => $data['name'],
            'venta_category_id'    => $data['venta_category_id'] ?? $g->venta_category_id,
            'venta_brand_id'       => $data['venta_brand_id'] ?? $g->venta_brand_id,
            'catalog_category_ids' => null,
            'manufacturer_ids'     => null,
            'markup_percent'       => $data['markup_percent'] ?? $g->markup_percent,
            'markup_fixed'         => $data['markup_fixed'] ?? $g->markup_fixed,
        ]);

        // Sync products from dual-listbox
        $submittedIds = array_filter(array_map('intval', (array) $request->input('product_ids', [])));
        $existingPivots = $g->products()->get()->keyBy('product_id');
        $existingIds = $existingPivots->keys()->map(fn($k) => (int) $k)->toArray();

        // Add new products
        foreach (array_diff($submittedIds, $existingIds) as $pid) {
            VentaProductGroupProduct::create([
                'venta_product_group_id' => $g->id,
                'product_id'             => $pid,
            ]);
        }

        // Remove products no longer in the list
        $toRemove = array_diff($existingIds, $submittedIds);
        if (!empty($toRemove)) {
            $g->products()->whereIn('product_id', $toRemove)->delete();
        }

        ActivityLogger::log('updated', 'Venta Product Group', $g->id, $g->name);

        return redirect()->route('ext.venta.product-groups.edit', [$store, $g->id])
            ->with('status', 'Product group saved.');
    }

    public function destroy(int $store, int $group)
    {
        $this->store_($store);
        $g = VentaProductGroup::where('venta_setting_id', $store)->findOrFail($group);
        $name = $g->name;
        $g->delete();

        ActivityLogger::log('deleted', 'Venta Product Group', (int) $group, $name);

        return redirect()->route('ext.venta.product-groups.index', $store)
            ->with('status', 'Product group "' . e($name) . '" deleted.');
    }

    // ── Products listing ────────────────────────────────────

    public function products(Request $request, int $store, int $group)
    {
        $setting = $this->store_($store);
        $g = VentaProductGroup::where('venta_setting_id', $store)->findOrFail($group);

        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $productIds = $this->getMatchingProductIds($g);

        if (empty($productIds)) {
            return view('ext-venta::product-groups.products', [
                'setting'              => $setting,
                'group'                => $g,
                'products'             => new \Illuminate\Pagination\LengthAwarePaginator([], 0, 50),
                'optionRowsByProductId' => collect(),
                'manualIds'            => [],
            ]);
        }

        $q = trim((string) $request->input('q'));
        $syncStatus = (string) $request->input('sync_status', 'all');
        $erpStatus = (string) $request->input('erp_status', 'all');

        $query = DB::table($pfx . 'product as p')
            ->join($pfx . 'product_description as pd', function ($j) use ($langId) {
                $j->on('p.product_id', '=', 'pd.product_id')
                    ->where('pd.language_id', '=', $langId);
            })
            ->leftJoin($pfx . 'manufacturer as m', 'p.manufacturer_id', '=', 'm.manufacturer_id')
            ->leftJoin('venta_product_links as vpl', function ($j) use ($store) {
                $j->on('p.product_id', '=', 'vpl.product_id')
                    ->where('vpl.venta_setting_id', '=', $store);
            })
            ->leftJoin('venta_product_group_products as vpgp', function ($j) use ($g) {
                $j->on('p.product_id', '=', 'vpgp.product_id')
                    ->where('vpgp.venta_product_group_id', '=', $g->id);
            })
            ->whereIn('p.product_id', $productIds)
            ->select(
                'p.product_id',
                'pd.name',
                'p.model',
                'p.sku',
                'p.price',
                'p.quantity',
                'p.status',
                'p.image',
                'm.name as manufacturer_name',
                'vpl.venta_product_id',
                'vpl.sku as venta_sku',
                'vpgp.sync_status',
                'vpgp.last_pushed_at',
                'vpgp.last_confirmed_at',
                'vpgp.push_error'
            );

        if ($q !== '') {
            $query->where(function ($w) use ($q) {
                $w->where('pd.name', 'like', "%{$q}%")
                    ->orWhere('p.sku', 'like', "%{$q}%")
                    ->orWhere('p.model', 'like', "%{$q}%");
            });
        }

        if ($erpStatus === 'enabled') {
            $query->where('p.status', 1);
        } elseif ($erpStatus === 'disabled') {
            $query->where('p.status', 0);
        }

        // See Lazada: the Pushed filter returned rows the badge calls Failed.
        // 'failed' is included alongside 'error' because this channel's view
        // treats both as a failure ($failed tests for either), so the filter
        // has to agree with it.
        if ($syncStatus === 'pushed') {
            $query->whereNotNull('vpl.venta_product_id')
                ->whereNotIn('vpgp.sync_status', ['unlinked', 'error', 'failed']);
        } elseif ($syncStatus === 'pending') {
            $query->whereNull('vpl.venta_product_id')
                ->where(function ($w) {
                    $w->whereNotIn('vpgp.sync_status', ['error', 'failed', 'unlinked'])
                        ->orWhereNull('vpgp.sync_status');
                });
        } elseif ($syncStatus === 'error') {
            $query->whereIn('vpgp.sync_status', ['error', 'failed']);
        }

        $products = $query->orderBy('p.product_id', 'desc')->paginate(50)->withQueryString();

        // Option rows (variations) for display — combinations first, fallback to option values
        $paginatedIds = $products->pluck('product_id')->toArray();
        $optionRowsByProductId = collect();
        if (!empty($paginatedIds)) {
            // Check combinations first
            $combos = DB::table('product_option_combinations as poc')
                ->whereIn('poc.product_id', $paginatedIds)
                ->orderBy('poc.product_id')
                ->orderBy('poc.sort_order')
                ->get();

            $comboProductIds = $combos->pluck('product_id')->unique()->toArray();

            if ($combos->isNotEmpty()) {
                $comboIds = $combos->pluck('id')->toArray();
                $comboValues = DB::table('product_option_combination_values as pocv')
                    ->join($pfx . 'product_option_value as pov', 'pocv.product_option_value_id', '=', 'pov.product_option_value_id')
                    ->join($pfx . 'option_value_description as ovd', function ($j) use ($langId) {
                        $j->on('pov.option_value_id', '=', 'ovd.option_value_id')
                            ->where('ovd.language_id', '=', $langId);
                    })
                    ->whereIn('pocv.combination_id', $comboIds)
                    ->select('pocv.combination_id', 'ovd.name as value_name')
                    ->get()
                    ->groupBy('combination_id');

                foreach ($combos as $c) {
                    $valueNames = ($comboValues->get($c->id) ?? collect())->pluck('value_name')->implode(' / ');
                    $row = (object) [
                        'product_id'             => $c->product_id,
                        'product_option_value_id' => $c->id,
                        'option_name'            => 'Option',
                        'option_value_name'      => $valueNames,
                        'option_quantity'         => (int) $c->quantity,
                        'option_sku'             => $c->sku,
                        'option_absolute_price'  => (float) $c->absolute_price,
                    ];
                    if (!$optionRowsByProductId->has($c->product_id)) {
                        $optionRowsByProductId[$c->product_id] = collect();
                    }
                    $optionRowsByProductId[$c->product_id]->push($row);
                }
            }

            // Fallback: option values for products without combinations
            $nonComboIds = array_diff($paginatedIds, $comboProductIds);
            if (!empty($nonComboIds)) {
                $optValues = DB::table($pfx . 'product_option_value as pov')
                    ->join($pfx . 'option_description as od', function ($j) use ($langId) {
                        $j->on('pov.option_id', '=', 'od.option_id')->where('od.language_id', '=', $langId);
                    })
                    ->join($pfx . 'option_value_description as ovd', function ($j) use ($langId) {
                        $j->on('pov.option_value_id', '=', 'ovd.option_value_id')->where('ovd.language_id', '=', $langId);
                    })
                    ->whereIn('pov.product_id', $nonComboIds)
                    ->select(
                        'pov.product_id', 'pov.product_option_value_id',
                        'od.name as option_name', 'ovd.name as option_value_name',
                        'pov.quantity as option_quantity', 'pov.sku as option_sku',
                        'pov.absolute_price as option_absolute_price'
                    )
                    ->orderBy('pov.product_id')
                    ->orderBy('pov.product_option_value_id')
                    ->get()
                    ->groupBy('product_id');

                $optionRowsByProductId = $optionRowsByProductId->union($optValues);
            }
        }

        $manualIds = $g->products()->pluck('product_id')->toArray();

        return view('ext-venta::product-groups.products', [
            'setting'              => $setting,
            'group'                => $g,
            'products'             => $products,
            'optionRowsByProductId' => $optionRowsByProductId,
            'manualIds'            => $manualIds,
            'q'                    => $q,
            'syncStatus'           => $syncStatus,
            'erpStatus'            => $erpStatus,
        ]);
    }

    // ── Sync ID (match ERP SKU to Venta product) ────────────

    public function syncId(int $store, int $group, int $product)
    {
        $setting = $this->storeForWrite_($store);

        if (! $setting) {
            return redirect()->back()->with('error',
                'That Venta store is turned off, so nothing was sent to it. Enable it in its settings first.');
        }
        $g = VentaProductGroup::where('venta_setting_id', $store)->findOrFail($group);

        // Verify the product is in this group
        $productIds = $this->getMatchingProductIds($g);
        if (!in_array($product, $productIds)) {
            return $this->productsRedirect($store, $group)
                ->with('error', 'Product not in this group.');
        }

        $pfx = (string) config('catalog.prefix');
        $client = new VentaClient($setting);

        // Collect all SKUs for this product (parent SKU + model + option value SKUs)
        $prod = DB::table($pfx . 'product')->where('product_id', $product)->first(['sku', 'model']);
        if (!$prod) {
            return $this->productsRedirect($store, $group)
                ->with('error', 'Product not found in catalog.');
        }

        $skus = [];
        if ($prod->sku && trim($prod->sku) !== '') {
            $skus[] = trim($prod->sku);
        }
        if ($prod->model && trim($prod->model) !== '' && !in_array(trim($prod->model), $skus)) {
            $skus[] = trim($prod->model);
        }

        $optSkus = DB::table($pfx . 'product_option_value')
            ->where('product_id', $product)
            ->whereNotNull('sku')
            ->where('sku', '!=', '')
            ->pluck('sku')
            ->map(fn($s) => trim($s))
            ->unique()
            ->toArray();
        $skus = array_unique(array_merge($skus, $optSkus));

        if (empty($skus)) {
            return $this->productsRedirect($store, $group)
                ->with('error', 'Product has no SKU to match against Venta.');
        }

        // Search Venta API by each SKU
        $matchedVentaId = null;
        $matchedSku = null;

        foreach ($skus as $sku) {
            $result = $client->getProducts(perPage: 50, sku: $sku);

            if (!($result['ok'] ?? false)) {
                continue;
            }

            $ventaProducts = $result['body']['data'] ?? $result['body'] ?? [];
            foreach ($ventaProducts as $vp) {
                $vpId = $vp['id'] ?? null;
                $vpSku = $vp['sku'] ?? null;

                if ($vpId && $vpSku !== null && strcasecmp(trim((string) $vpSku), $sku) === 0) {
                    $matchedVentaId = (int) $vpId;
                    $matchedSku = $sku;
                    break 2;
                }

                // Also check variants if present
                foreach ($vp['variants'] ?? [] as $variant) {
                    $varSku = $variant['sku'] ?? null;
                    if ($varSku !== null && strcasecmp(trim((string) $varSku), $sku) === 0) {
                        $matchedVentaId = (int) $vpId;
                        $matchedSku = $sku;
                        break 3;
                    }
                }
            }
        }

        if (!$matchedVentaId) {
            return $this->productsRedirect($store, $group)
                ->with('error', 'No matching product found on Venta for SKU(s): ' . implode(', ', $skus));
        }

        // Save or update the link, unless another product already holds it.
        if ($clash = $this->linkProductTo($store, $product, $matchedVentaId, $matchedSku)) {
            return $this->productsRedirect($store, $group)->with('error', $clash);
        }

        // Update sync status on group product pivot
        $this->writeProductStatus($g, $product, [
                'sync_status' => 'synced',
                'venta_sku'   => $matchedSku,
                'push_error'  => null,
            ]);

        return $this->productsRedirect($store, $group)
            ->with('status', "Venta product ID {$matchedVentaId} synced for SKU: {$matchedSku}");
    }

    /**
     * Mass sync Venta IDs for selected products by SKU matching.
     */
    /**
     * List products that exist on Venta but that no catalogue product claims.
     *
     * The check above answers "is what we have still there". This answers the
     * mirror question, "is there something there we do not know about", and
     * the two together are the only way to see the whole picture. Orphans are
     * produced by ordinary events: a product created in Venta's admin, a link
     * deleted, a catalogue product removed while its Venta twin stayed live.
     *
     * Read-only on purpose. It reports; it never adopts, never deletes, and
     * never writes a link. An automatic adopt-by-SKU is exactly how a product
     * ends up pointed at a stranger, and the operator is the one who knows
     * whether a name that looks similar is actually the same product.
     */
    public function orphans(Request $request, int $store, int $group)
    {
        $setting = $this->store_($store);
        $g = VentaProductGroup::where('venta_setting_id', $store)->findOrFail($group);

        if (! $setting->enabled) {
            return $this->productsRedirect($store, $group)->with('error',
                'That Venta store is turned off, so its products could not be listed.');
        }

        $storeName = $setting->store_name ?: 'Unnamed store';
        $client = new VentaClient($setting);

        $known = VentaProductLink::where('venta_setting_id', $store)
            ->whereNotNull('venta_product_id')
            ->pluck('venta_product_id')
            ->map('intval')
            ->flip();

        $orphans = [];
        $scanned = 0;
        $page = 1;
        $maxPages = 20;
        $perPage = 100;
        $truncated = false;
        $error = null;

        while ($page <= $maxPages) {
            $result = $client->get('products', ['per_page' => $perPage, 'page' => $page]);

            if (! ($result['ok'] ?? false)) {
                $status = (int) ($result['status'] ?? 0);
                $error = $status === 0
                    ? $storeName . ' could not be reached, so the list below may be incomplete.'
                    : $storeName . ' answered ' . $status . ', so the list below may be incomplete.';
                break;
            }

            $body = $result['body'] ?? [];
            $rows = $body['data'] ?? (is_array($body) ? $body : []);

            if (! is_array($rows) || $rows === []) {
                break;
            }

            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $scanned++;
                $id = (int) ($row['id'] ?? 0);

                if ($id <= 0 || $known->has($id)) {
                    continue;
                }

                $orphans[] = [
                    'id' => $id,
                    'sku' => (string) ($row['sku'] ?? ''),
                    'name' => (string) ($row['name'] ?? ''),
                ];
            }

            // Stop when the page came back short: that is the last page
            // whether or not this Venta build sends pagination meta.
            if (count($rows) < $perPage) {
                break;
            }

            if ($page === $maxPages) {
                $truncated = true;
            }

            $page++;
        }

        return view('ext-venta::product-groups.orphans', [
            'setting' => $setting,
            'group' => $g,
            'storeName' => $storeName,
            'orphans' => $orphans,
            'scanned' => $scanned,
            'truncated' => $truncated,
            'error' => $error,
        ]);
    }

    /**
     * Ask Venta about these products and make the ERP agree. No writing to Venta.
     *
     * This replaces "Match Venta IDs", which could only ever fill in blanks:
     * its first act was to skip any product that already had a link, so the
     * one thing an operator actually needs to find out - whether the link it
     * already has is still true - was the one thing it refused to look at. A
     * product deleted in Venta's admin was invisible to it.
     *
     * It is deliberately a separate button from Send rather than something the
     * schedule does to every product on every run. Checking is one or two GETs
     * per product, so a group of 137 is up to 274 calls; doing that hourly for
     * every group would spend thousands of calls a day re-answering a question
     * that only changes when somebody edits Venta directly. The operator knows
     * when that has happened, and this is the button for it.
     *
     * With rows ticked it checks those. With none ticked it checks the group.
     */
    public function checkAgainstVenta(Request $request, int $store, int $group)
    {
        $setting = $this->storeForWrite_($store);

        if (! $setting) {
            return redirect()->back()->with('error',
                'That Venta store is turned off, so nothing could be checked. Enable it in its settings first.');
        }

        $g = VentaProductGroup::where('venta_setting_id', $store)->findOrFail($group);

        $ids = array_values(array_unique(array_map('intval', array_filter((array) $request->input('ids', [])))));

        if (empty($ids)) {
            $ids = DB::table('venta_product_group_products')
                ->where('venta_product_group_id', $group)
                ->pluck('product_id')->map('intval')->all();
        }

        if (empty($ids)) {
            return $this->productsRedirect($store, $group)->with('error', 'This group has no products to check.');
        }

        // A ceiling, because this runs in a web request and each product costs
        // a round trip. It is reported rather than applied quietly: a check
        // that silently examined the first 200 of 500 would leave the operator
        // believing the other 300 had been confirmed.
        $limit = 200;
        $skippedForSize = max(0, count($ids) - $limit);
        $ids = array_slice($ids, 0, $limit);

        $storeName = $setting->store_name ?: 'Unnamed store';
        $pfx = (string) config('catalog.prefix');
        $client = new VentaClient($setting);

        $verdicts = [];
        $clashes = [];

        foreach ($ids as $pid) {
            $verdict = $this->reconcileLink($client, $store, $pid, $pfx);
            $state = $verdict['state'];
            $verdicts[$state] = ($verdicts[$state] ?? 0) + 1;

            if ($state === 'unreachable') {
                // Venta never answered, so nothing is known and nothing is
                // written. The row keeps whatever it said before, which is
                // honest: it was always a belief and it still is.
                continue;
            }

            if ($state === 'blocked') {
                $clashes[] = "#{$pid}: {$verdict['message']}";
                $this->writeProductStatus($g, $pid, [
                    'sync_status' => 'error',
                    'push_error' => $verdict['message'],
                    'last_confirmed_at' => now(),
                ]);
                continue;
            }

            if ($state === 'lost') {
                // reconcileLink has already dropped the dead link. The row goes
                // back to not-pushed, which is what it truthfully is.
                $this->writeProductStatus($g, $pid, [
                    'sync_status' => 'pending',
                    'push_error' => null,
                    'venta_sku' => null,
                    'last_pushed_at' => null,
                    'last_confirmed_at' => now(),
                ]);
                continue;
            }

            if ($state === 'new') {
                $this->writeProductStatus($g, $pid, [
                    'sync_status' => 'pending',
                    'push_error' => null,
                    'last_confirmed_at' => now(),
                ]);
                continue;
            }

            // confirmed, adopted, repointed: Venta has it and the link is right.
            $this->writeProductStatus($g, $pid, [
                'sync_status' => 'pushed',
                'venta_sku' => $verdict['sku'],
                'push_error' => null,
                'last_confirmed_at' => now(),
            ]);
        }

        $checked = count($ids) - ($verdicts['unreachable'] ?? 0);

        // The message reports FINDINGS. Nothing here pushed anything, so it
        // must not imply that anything was fixed on Venta's side.
        $parts = [];
        if (! empty($verdicts['confirmed'])) {
            $parts[] = $verdicts['confirmed'] . ' still on ' . $storeName;
        }
        if (! empty($verdicts['adopted'])) {
            $parts[] = $verdicts['adopted'] . ' found and linked';
        }
        if (! empty($verdicts['repointed'])) {
            $parts[] = $verdicts['repointed'] . ' pointed at the wrong product and corrected';
        }
        if (! empty($verdicts['lost'])) {
            $parts[] = $verdicts['lost'] . ' no longer on ' . $storeName . ', now marked not pushed';
        }
        if (! empty($verdicts['new'])) {
            $parts[] = $verdicts['new'] . ' not pushed yet';
        }
        if ($clashes) {
            $parts[] = count($clashes) . ' blocked by another product holding the same Venta id';
        }
        if (! empty($verdicts['unreachable'])) {
            $parts[] = $verdicts['unreachable'] . ' could not be checked because ' . $storeName . ' did not answer';
        }

        $msg = "Checked {$checked} against {$storeName}. " . ($parts ? implode('. ', $parts) . '.' : 'Nothing to report.');

        if ($skippedForSize > 0) {
            $msg .= " {$skippedForSize} further " . ($skippedForSize === 1 ? 'product was' : 'products were')
                . ' not checked this time. Run it again to continue.';
        }

        if ($clashes) {
            $msg .= ' ' . implode('; ', array_slice($clashes, 0, 2));
            if (count($clashes) > 2) {
                $msg .= ' (and ' . (count($clashes) - 2) . ' more)';
            }
        }

        $problems = count($clashes) + ($verdicts['unreachable'] ?? 0);
        $tone = $problems === 0 ? 'status' : ($checked > 0 ? 'warning' : 'error');

        return $this->productsRedirect($store, $group)->with($tone, $msg);
    }

    // ── Push Products (create/update on Venta) ──────────────

    public function pushProducts(Request $request, int $store, int $group)
    {

        $setting = $this->storeForWrite_($store);

        if (! $setting) {
            return redirect()->back()->with('error',
                'That Venta store is turned off, so nothing was sent to it. Enable it in its settings first.');
        }
        $g = VentaProductGroup::where('venta_setting_id', $store)->findOrFail($group);

        $ids = array_map('intval', array_filter((array) $request->input('ids', [])));
        if (empty($ids)) {
            return $this->productsRedirect($store, $group)
                ->with('error', 'No products selected.');
        }

        $client = new VentaClient($setting);
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $created = 0;
        $updated = 0;
        $verdicts = [];

        // Venta answers every push with a per-image verdict. Nothing read it,
        // so a push whose product landed and whose images were all refused
        // reported "Created: 1, Failed: 0" and said nothing about the images.
        $imagesSent = 0;
        $imagesStored = 0;
        $imageRejections = [];
        $failed = 0;
        $errors = [];

        foreach ($ids as $pid) {
            $prod = DB::table($pfx . 'product as p')
                ->join($pfx . 'product_description as pd', function ($j) use ($langId) {
                    $j->on('p.product_id', '=', 'pd.product_id')
                        ->where('pd.language_id', '=', $langId);
                })
                ->leftJoin($pfx . 'manufacturer as m', 'p.manufacturer_id', '=', 'm.manufacturer_id')
                ->where('p.product_id', $pid)
                ->first([
                    'p.product_id', 'pd.name', 'pd.description', 'p.model', 'p.sku',
                    'p.price', 'p.cost', 'p.quantity', 'p.status', 'p.image',
                    'p.weight', 'p.length', 'p.width', 'p.height',
                    'm.name as brand_name',
                ]);

            if (!$prod) {
                $failed++;
                continue;
            }

            $sku = $prod->sku ?: $prod->model;
            if (!$sku || trim($sku) === '') {
                $failed++;
                $errors[] = "Product #{$pid}: No SKU";
                continue;
            }

            // Build product data payload
            $data = [
                'name'        => $prod->name,
                'sku'         => $sku,
                'price'       => (float) $prod->price,
                'cost'        => (float) ($prod->cost ?? 0),
                'quantity'    => (int) $prod->quantity,
                'weight'      => (float) ($prod->weight ?? 0),
                'length'      => (float) ($prod->length ?? 0),
                'width'       => (float) ($prod->width ?? 0),
                'height'      => (float) ($prod->height ?? 0),
                'status'      => (bool) $prod->status,
                'description' => $prod->description ?? '',
            ];

            // Send brand as name — Venta API will find-or-create
            if ($prod->brand_name) {
                $data['brand_name'] = $prod->brand_name;
            }

            // Send categories as names — Venta API will find-or-create
            $catNames = DB::table($pfx . 'product_to_category as ptc')
                ->join($pfx . 'category_description as cd', function ($j) use ($langId) {
                    $j->on('ptc.category_id', '=', 'cd.category_id')
                        ->where('cd.language_id', '=', $langId);
                })
                ->where('ptc.product_id', $pid)
                ->pluck('cd.name')
                ->filter()
                ->values()
                ->toArray();
            if (!empty($catNames)) {
                $data['category_names'] = $catNames;
            }

            // Add images
            $images = $this->getProductImages($pfx, $pid);
            if (!empty($images)) {
                $data['images'] = $images;
            }

            // Get variants — check combinations first, fall back to option values
            $combos = DB::table('product_option_combinations as poc')
                ->where('poc.product_id', $pid)
                ->orderBy('poc.sort_order')
                ->get();

            if ($combos->isNotEmpty()) {
                // Combination-based variants (multi-option products)
                $comboIds = $combos->pluck('id')->toArray();
                $comboValues = DB::table('product_option_combination_values as pocv')
                    ->join($pfx . 'product_option_value as pov', 'pocv.product_option_value_id', '=', 'pov.product_option_value_id')
                    ->join($pfx . 'option_value_description as ovd', function ($j) use ($langId) {
                        $j->on('pov.option_value_id', '=', 'ovd.option_value_id')
                            ->where('ovd.language_id', '=', $langId);
                    })
                    ->whereIn('pocv.combination_id', $comboIds)
                    ->select('pocv.combination_id', 'ovd.name as value_name')
                    ->get()
                    ->groupBy('combination_id');

                $variants = [];
                foreach ($combos as $c) {
                    $valueNames = ($comboValues->get($c->id) ?? collect())->pluck('value_name')->implode(' / ');
                    $variants[] = [
                        'sku'         => $c->sku ?: ($sku . '-' . $c->id),
                        'name'        => $valueNames,
                        'option_name' => 'Option',
                        'price'       => (float) $c->absolute_price,
                        'cost'        => (float) $c->absolute_cost,
                        'quantity'    => (int) $c->quantity,
                        'weight'      => (float) ($prod->weight ?? 0),
                    ];
                }
                $data['variants'] = $variants;
            } else {
                // Single-option variants
                $optionValues = DB::table($pfx . 'product_option_value as pov')
                    ->join($pfx . 'option_value_description as ovd', function ($j) use ($langId) {
                        $j->on('pov.option_value_id', '=', 'ovd.option_value_id')
                            ->where('ovd.language_id', '=', $langId);
                    })
                    ->join($pfx . 'option_description as od', function ($j) use ($langId) {
                        $j->on('pov.option_id', '=', 'od.option_id')
                            ->where('od.language_id', '=', $langId);
                    })
                    ->where('pov.product_id', $pid)
                    ->get([
                        'pov.product_option_value_id', 'pov.sku as variant_sku',
                        'pov.quantity as variant_qty', 'pov.price as variant_price',
                        'pov.price_prefix', 'pov.absolute_price',
                        'pov.cost as variant_cost', 'pov.cost_prefix', 'pov.absolute_cost',
                        'ovd.name as option_value_name', 'od.name as option_name',
                    ]);

                if ($optionValues->isNotEmpty()) {
                    $variants = [];
                    foreach ($optionValues as $ov) {
                        $variantSku = $ov->variant_sku ?: ($sku . '-' . $ov->product_option_value_id);

                        // Price: absolute_price takes priority, then modifier
                        if ((float) ($ov->absolute_price ?? 0) > 0) {
                            $variantPrice = (float) $ov->absolute_price;
                        } else {
                            $variantPrice = (float) $prod->price;
                            if ($ov->variant_price) {
                                $variantPrice = $ov->price_prefix === '-'
                                    ? $variantPrice - (float) $ov->variant_price
                                    : $variantPrice + (float) $ov->variant_price;
                            }
                        }

                        // Cost: absolute_cost takes priority, then modifier
                        if ((float) ($ov->absolute_cost ?? 0) > 0) {
                            $variantCost = (float) $ov->absolute_cost;
                        } else {
                            $variantCost = (float) ($prod->cost ?? 0);
                            if ($ov->variant_cost) {
                                $variantCost = $ov->cost_prefix === '-'
                                    ? $variantCost - (float) $ov->variant_cost
                                    : $variantCost + (float) $ov->variant_cost;
                            }
                        }

                        $variants[] = [
                            'sku'         => $variantSku,
                            'name'        => $ov->option_value_name,
                            'option_name' => $ov->option_name,
                            'price'       => $variantPrice,
                            'cost'        => $variantCost,
                            'quantity'    => (int) $ov->variant_qty,
                            'weight'      => (float) ($prod->weight ?? 0),
                        ];
                    }
                    $data['variants'] = $variants;
                }
            }

            /*
             * ASK VENTA FIRST. Everything below used to be decided from the
             * local link row - the ERP's own memory - which is wrong in both
             * directions often enough that it produced a closed trap: a
             * product deleted in Venta's admin left the ERP offering only
             * Update, which can answer nothing but 404, while Push was hidden
             * because a link existed.
             *
             * Reconciling here rather than in a separate action means the row
             * button and the bulk bar both get it, there is one code path, and
             * no state of the page can hide the thing that works.
             *
             * The cost is one extra GET per product. That is what correctness
             * costs, and it is cheaper than an operator unlinking and
             * re-linking in a circle.
             */
            $verdict = $this->reconcileLink($client, $store, $pid, $pfx);
            $verdicts[$verdict['state']] = ($verdicts[$verdict['state']] ?? 0) + 1;

            // Neither of these may push. 'blocked' means another product holds
            // the id, so writing would corrupt that product's link.
            // 'unreachable' means we do not know what is on Venta, and pushing
            // into that ignorance is how a create lands on top of something
            // that already exists.
            if ($verdict['state'] === 'blocked' || $verdict['state'] === 'unreachable') {
                $failed++;
                $errors[] = "#{$pid}: {$verdict['message']}";
                $this->writeProductStatus($g, $pid, [
                    'sync_status' => 'error',
                    'push_error' => $verdict['message'],
                    'last_pushed_at' => now(),
                ]);
                continue;
            }

            // Venta answered about this product, so whatever the row now says
            // is a checked fact rather than a remembered one.
            $this->writeProductStatus($g, $pid, ['last_confirmed_at' => now()]);

            // Re-read: reconcileLink may have created, repointed or dropped it.
            $existingLink = VentaProductLink::where('venta_setting_id', $store)
                ->where('product_id', $pid)
                ->first();

            if ($existingLink && $existingLink->venta_product_id) {
                // Update existing product on Venta
                $result = $client->updateProduct($sku, $data);
                if ($result['ok']) {
                    $updated++;
                    $this->collectImageVerdict($result, $pid, $imagesSent, $imagesStored, $imageRejections);
                    $this->writeProductStatus($g, $pid, [
                            'sync_status'  => 'synced',
                            'last_pushed_at' => now(),
                            'push_error'   => null,
                            'venta_sku'    => $sku,
                        ]);
                } else {
                    $failed++;
                    $errMsg = json_encode($result['body']);
                    $errors[] = "Product #{$pid}: {$errMsg}";
                    $this->writeProductStatus($g, $pid, ['sync_status' => 'failed', 'push_error' => $errMsg]);
                }
            } else {
                // Create new product on Venta
                $result = $client->createProduct($data);
                if ($result['ok']) {
                    $created++;
                    $this->collectImageVerdict($result, $pid, $imagesSent, $imagesStored, $imageRejections);
                    $ventaProductId = $result['body']['id'] ?? $result['body']['data']['id'] ?? 0;

                    /*
                     * One Venta product maps to one ERP product, enforced by a
                     * unique key on (store, venta_product_id). A stale link
                     * held by ANOTHER product can therefore block this write,
                     * and it does: recreating one product here got back Venta
                     * id 1885, which a second product still held a dead link
                     * to from the same copied database. Unhandled, that
                     * surfaced as a 500 after the product had already been
                     * created on Venta - the worst possible moment to fail,
                     * because the work was done and the record of it was lost.
                     *
                     * The conflicting link is NOT taken over automatically.
                     * The other product's link may be perfectly good, and
                     * stealing it would leave that product silently pointing
                     * at nothing. Named instead, so the operator can see which
                     * one is in the way.
                     */
                    if ($clash = $this->linkProductTo($store, $pid, $ventaProductId, $sku)) {
                        $failed++;
                        $created--;
                        $errors[] = "#{$pid}: created on Venta as {$ventaProductId}, but {$clash}";
                        continue;
                    }

                    $this->writeProductStatus($g, $pid, [
                            'sync_status'  => 'synced',
                            'last_pushed_at' => now(),
                            'push_error'   => null,
                            'venta_sku'    => $sku,
                        ]);
                } else {
                    $failed++;
                    $errMsg = $this->pushFailureText($result, $sku);
                    $errors[] = "Product #{$pid}: {$errMsg}";
                    $this->writeProductStatus($g, $pid, ['sync_status' => 'failed', 'push_error' => Str::limit($errMsg, 480)]);
                }
            }
        }

        // Record that products reached Venta.
        //
        // Nothing wrote last_product_sync_at. The column existed and the
        // settings page printed it, but no code path set it, so "Products
        // pushed: Never" was permanent however many products were pushed.
        // This is the only path that pushes them - venta:sync accepts only
        // `orders`, so there is no scheduled product sync to stamp it from.
        if ($created > 0 || $updated > 0) {
            $setting->update(['last_product_sync_at' => now()]);
        }

        $msg = "Push complete — Created: {$created}, Updated: {$updated}, Failed: {$failed}";

        /*
         * What reconciling FOUND, in the operator's terms.
         *
         * Counts of created and updated describe what we did. They do not say
         * that Venta had quietly lost two products, or that a link was
         * pointing at the wrong one - which is the thing the operator came to
         * find out and previously had no way to learn.
         */
        // States what reconciling FOUND, never what followed from it. Saying
        // "was recreated" here claimed an outcome the push had not reached
        // yet, so a recreate that then failed still read as a recreate.
        $notes = [];
        if (! empty($verdicts['lost'])) {
            $notes[] = $verdicts['lost'] . ' had been removed on Venta';
        }
        if (! empty($verdicts['adopted'])) {
            $notes[] = $verdicts['adopted'] . ' already existed on Venta and ' .
                ($verdicts['adopted'] === 1 ? 'was linked' : 'were linked');
        }
        if (! empty($verdicts['repointed'])) {
            $notes[] = $verdicts['repointed'] . ' had been linked to the wrong Venta product';
        }
        if (! empty($verdicts['unreachable'])) {
            $notes[] = $verdicts['unreachable'] . ' could not be checked because Venta did not answer';
        }
        if ($notes) {
            $msg .= '. ' . ucfirst(implode('; ', $notes));
        }

        /*
         * The images, said out loud.
         *
         * A product can land perfectly and carry none of its pictures, which
         * is what happens on any sandbox: Venta refuses to download from a
         * private address. Reported as its own clause rather than folded into
         * the failure count, because the product genuinely did push - saying
         * "Failed: 1" about it would be a different lie from the one being
         * fixed.
         */
        if ($imagesSent > 0 && $imagesStored < $imagesSent) {
            $rejected = $imagesSent - $imagesStored;
            $msg .= ". {$rejected} of {$imagesSent} images were rejected by Venta";

            // Venta's own words. One line per distinct reason, because six
            // images refused for one reason is one thing to know, not six.
            arsort($imageRejections);
            $lines = [];
            foreach (array_slice($imageRejections, 0, 2, true) as $reason => $count) {
                $lines[] = "{$count}x {$reason}";
            }
            $msg .= ': ' . implode('; ', $lines);

            if (count($imageRejections) > 2) {
                $msg .= ' (and ' . (count($imageRejections) - 2) . ' other reasons)';
            }
        } elseif ($imagesSent > 0) {
            $msg .= ". {$imagesStored} images stored";
        }

        if (! empty($errors)) {
            Log::warning('Venta pushProducts errors', ['store' => $store, 'group' => $group, 'errors' => $errors]);

            // The reasons reach the OPERATOR, not only the log. "Failed: 1"
            // with the explanation written to a file they cannot read is the
            // same as no explanation: the one useful sentence here names the
            // product standing in the way and what to do about it.
            $msg .= '. ' . implode('; ', array_slice($errors, 0, 3));

            if (count($errors) > 3) {
                $msg .= ' (and ' . (count($errors) - 3) . ' more, shown against their rows)';
            }
        }

        // Green only when nothing was lost, and images count as something.
        // A push that stored the product and none of its pictures is not a
        // clean success, whatever the product count says.
        $lostImages = $imagesSent > 0 && $imagesStored < $imagesSent;

        $tone = match (true) {
            $failed > 0 && ($created + $updated) === 0 => 'error',
            $failed > 0 || $lostImages => 'warning',
            default => 'status',
        };

        return $this->productsRedirect($store, $group)->with($tone, $msg);
    }

    // ── Push Stock ──────────────────────────────────────────

    public function pushStock(Request $request, int $store, int $group)
    {
        $setting = $this->storeForWrite_($store);

        if (! $setting) {
            return redirect()->back()->with('error',
                'That Venta store is turned off, so nothing was sent to it. Enable it in its settings first.');
        }
        $g = VentaProductGroup::where('venta_setting_id', $store)->findOrFail($group);

        $ids = array_map('intval', array_filter((array) $request->input('ids', [])));
        if (empty($ids)) {
            return $this->productsRedirect($store, $group)
                ->with('error', 'No products selected.');
        }

        $client = new VentaClient($setting);
        $pfx = (string) config('catalog.prefix');

        // Get linked products for the selected IDs
        $links = VentaProductLink::where('venta_setting_id', $store)
            ->whereIn('product_id', $ids)
            ->get()
            ->keyBy('product_id');

        if ($links->isEmpty()) {
            return $this->productsRedirect($store, $group)
                ->with('error', 'No linked Venta products found for the selected items.');
        }

        // Fetch ERP quantities
        $erpProducts = DB::table($pfx . 'product')
            ->whereIn('product_id', $ids)
            ->get(['product_id', 'sku', 'quantity', 'status'])
            ->keyBy('product_id');

        // Fetch combinations first, then option values as fallback
        $erpCombos = DB::table('product_option_combinations')
            ->whereIn('product_id', $ids)
            ->get(['product_id', 'sku', 'quantity'])
            ->groupBy(fn($r) => (int) $r->product_id);

        $erpOptions = DB::table($pfx . 'product_option_value')
            ->whereIn('product_id', $ids)
            ->whereNotNull('sku')
            ->where('sku', '!=', '')
            ->get(['product_id', 'sku', 'quantity'])
            ->groupBy(fn($r) => (int) $r->product_id);

        $okCount = 0;
        $errCount = 0;
        $skipCount = 0;

        foreach ($ids as $pid) {
            $link = $links->get($pid);
            $product = $erpProducts->get($pid);

            if (!$link || !$product) {
                $skipCount++;
                continue;
            }

            if ((int) $product->status === 0) {
                $skipCount++;
                continue;
            }

            $sku = $link->sku ?: $product->sku;

            // Check combinations first, then option values
            $variants = $erpCombos->get($pid) ?? $erpOptions->get($pid);
            if ($variants && $variants->count() > 0) {
                $allOk = true;
                foreach ($variants as $ov) {
                    if (!$ov->sku || trim($ov->sku) === '') continue;
                    $result = $client->pushVariantStock(trim($ov->sku), max(0, (int) $ov->quantity));
                    if (!$result['ok']) {
                        $allOk = false;
                    }
                }
                // Also push parent stock
                $client->pushStock($sku, max(0, (int) $product->quantity));
                $allOk ? $okCount++ : $errCount++;
            } else {
                $result = $client->pushStock($sku, max(0, (int) $product->quantity));
                $result['ok'] ? $okCount++ : $errCount++;
            }
        }

        $msg = "Stock push complete — {$okCount} updated";
        if ($errCount > 0) $msg .= ", {$errCount} failed";
        if ($skipCount > 0) $msg .= ", {$skipCount} skipped";

        return $this->productsRedirect($store, $group)->with('status', $msg);
    }

    // ── Push Prices ─────────────────────────────────────────

    public function pushPrices(Request $request, int $store, int $group)
    {
        $setting = $this->storeForWrite_($store);

        if (! $setting) {
            return redirect()->back()->with('error',
                'That Venta store is turned off, so nothing was sent to it. Enable it in its settings first.');
        }
        $g = VentaProductGroup::where('venta_setting_id', $store)->findOrFail($group);

        $ids = array_map('intval', array_filter((array) $request->input('ids', [])));
        if (empty($ids)) {
            return $this->productsRedirect($store, $group)
                ->with('error', 'No products selected.');
        }

        $client = new VentaClient($setting);
        $pfx = (string) config('catalog.prefix');

        // Get linked products
        $links = VentaProductLink::where('venta_setting_id', $store)
            ->whereIn('product_id', $ids)
            ->get()
            ->keyBy('product_id');

        if ($links->isEmpty()) {
            return $this->productsRedirect($store, $group)
                ->with('error', 'No linked Venta products found for the selected items.');
        }

        // Fetch ERP prices
        $erpProducts = DB::table($pfx . 'product')
            ->whereIn('product_id', $ids)
            ->get(['product_id', 'sku', 'price', 'status'])
            ->keyBy('product_id');

        // Fetch combinations first, then option values as fallback
        $erpCombos = DB::table('product_option_combinations')
            ->whereIn('product_id', $ids)
            ->get(['product_id', 'sku', 'absolute_price', 'absolute_cost'])
            ->groupBy(fn($r) => (int) $r->product_id);

        $erpOptions = DB::table($pfx . 'product_option_value')
            ->whereIn('product_id', $ids)
            ->whereNotNull('sku')
            ->where('sku', '!=', '')
            ->get(['product_id', 'sku', 'price', 'price_prefix', 'absolute_price'])
            ->groupBy(fn($r) => (int) $r->product_id);

        $okCount = 0;
        $errCount = 0;
        $skipCount = 0;

        foreach ($ids as $pid) {
            $link = $links->get($pid);
            $product = $erpProducts->get($pid);

            if (!$link || !$product) {
                $skipCount++;
                continue;
            }

            if ((int) $product->status === 0) {
                $skipCount++;
                continue;
            }

            $sku = $link->sku ?: $product->sku;
            $basePrice = (float) $product->price;

            // Push parent price
            $result = $client->updateProduct($sku, ['price' => $basePrice]);
            if (!$result['ok']) {
                $errCount++;
                continue;
            }

            // Push variant prices — combinations first, then option values
            $combos = $erpCombos->get($pid);
            if ($combos && $combos->count() > 0) {
                foreach ($combos as $c) {
                    if (!$c->sku || trim($c->sku) === '') continue;
                    $client->updateVariant(trim($c->sku), ['price' => (float) $c->absolute_price]);
                }
            } else {
                $variants = $erpOptions->get($pid);
                if ($variants && $variants->count() > 0) {
                    foreach ($variants as $ov) {
                        if (!$ov->sku || trim($ov->sku) === '') continue;
                        if ((float) ($ov->absolute_price ?? 0) > 0) {
                            $variantPrice = (float) $ov->absolute_price;
                        } else {
                            $variantPrice = $basePrice;
                            if ($ov->price) {
                                $variantPrice = $ov->price_prefix === '-'
                                    ? $basePrice - (float) $ov->price
                                    : $basePrice + (float) $ov->price;
                            }
                        }
                        $client->updateVariant(trim($ov->sku), ['price' => $variantPrice]);
                    }
                }
            }

            $okCount++;
        }

        $msg = "Price push complete — {$okCount} updated";
        if ($errCount > 0) $msg .= ", {$errCount} failed";
        if ($skipCount > 0) $msg .= ", {$skipCount} skipped";

        return $this->productsRedirect($store, $group)->with('status', $msg);
    }

    // ── Unlink / Link / Mass Remove ─────────────────────────

    public function unlinkProduct(int $store, int $group, int $product)
    {
        $this->store_($store);
        $g = VentaProductGroup::where('venta_setting_id', $store)->findOrFail($group);

        /*
         * Unlink means FORGET, and it never did.
         *
         * It set a status flag and left the link row in place, so the ERP went
         * on believing the product was on Venta. The row then offered only
         * "Link to Venta again", which read that surviving row, found it, and
         * reported success without contacting Venta at all - a closed loop
         * with no marketplace in it. Deleting the link is what makes the next
         * send re-match or create.
         */
        $removed = VentaProductLink::where('venta_setting_id', $store)
            ->where('product_id', $product)
            ->delete();

        $this->writeProductStatus($g, $product, [
            'sync_status' => 'pending',
            'push_error' => null,
        ]);

        return $this->productsRedirect($store, $group)->with(
            'status',
            $removed > 0
                ? 'Unlinked. This product is no longer connected to anything on Venta; sending it again will re-match or create it.'
                : 'That product was not linked to Venta, so there was nothing to unlink.'
        );
    }

    /**
     * The bulk half. Every product is reported on: which ones went, which did
     * not, and why - rather than a count that leaves the operator to work out
     * whether their listings are still on a public storefront.
     */
    private function deleteManyFromVenta($setting, $g, int $store, int $group, array $productIds)
    {
        $client = new VentaClient($setting);
        $deleted = 0;
        $skipped = 0;
        $errors = [];

        $links = VentaProductLink::where('venta_setting_id', $store)
            ->whereIn('product_id', $productIds)
            ->get()
            ->keyBy('product_id');

        foreach ($productIds as $pid) {
            $link = $links->get($pid);

            if (! $link || ! $link->sku) {
                $skipped++;
                continue;
            }

            $result = $client->delete("products/{$link->sku}");

            if ($result['ok']) {
                $link->delete();

                $this->writeProductStatus($g, $pid, ['sync_status' => 'pending', 'push_error' => null, 'last_pushed_at' => null]);

                $deleted++;
                continue;
            }

            $message = $result['body']['error'] ?? 'Unknown error';
            $errors[] = "#{$pid}: {$message}";

            $this->writeProductStatus($g, $pid, [
                    'sync_status' => 'error',
                    'push_error'  => Str::limit('Delete from Venta failed. ' . $message, 480),
                ]);

            usleep(150000);
        }

        $msg = "Delete from Venta: {$deleted} deleted";
        if ($skipped > 0) {
            $msg .= ", {$skipped} skipped (not linked)";
        }
        if ($errors) {
            $msg .= ', ' . count($errors) . ' failed. ' . implode('; ', array_slice($errors, 0, 3));
            if (count($errors) > 3) {
                $msg .= ' (and ' . (count($errors) - 3) . ' more, shown against their rows)';
            }
        }

        $tone = empty($errors) ? ($deleted > 0 ? 'status' : 'warning') : ($deleted > 0 ? 'warning' : 'error');

        return $this->productsRedirect($store, $group)->with($tone, $msg . '.');
    }

    /**
     * Delete one product from Venta, or several.
     *
     * $product is optional now. Venta was the only channel of the four that
     * could not delete in bulk: this took a single product id in the URL,
     * while Shopee, Lazada and TikTok all take ids[] in the body. So its bulk
     * bar had a red button in the last slot that meant "remove from group",
     * where the same slot on the other three means "delete from the
     * marketplace" - the same position, the same colour, opposite
     * consequences, on a page an operator moves across channels all day.
     *
     * The per-product route still passes a $product and behaves exactly as it
     * did; a bulk post passes none and sends ids[] instead.
     */
    public function deleteFromVenta(Request $request, int $store, int $group, ?int $product = null)
    {
        $setting = $this->storeForWrite_($store);

        if (! $setting) {
            return redirect()->back()->with('error',
                'That Venta store is turned off, so nothing was sent to it. Enable it in its settings first.');
        }
        $g = VentaProductGroup::where('venta_setting_id', $store)->findOrFail($group);

        $productIds = $product !== null
            ? [$product]
            : array_values(array_unique(array_filter(array_map('intval', (array) $request->input('ids', [])))));

        if (empty($productIds)) {
            return $this->productsRedirect($store, $group)->with('error', 'No products selected.');
        }

        if (count($productIds) > 1) {
            return $this->deleteManyFromVenta($setting, $g, $store, $group, $productIds);
        }

        $product = $productIds[0];

        $link = VentaProductLink::where('venta_setting_id', $store)
            ->where('product_id', $product)
            ->first();

        if (!$link || !$link->sku) {
            return $this->productsRedirect($store, $group)
                ->with('error', 'Product not linked to Venta — nothing to delete.');
        }

        $client = new VentaClient($setting);
        $result = $client->delete("products/{$link->sku}");

        if ($result['ok']) {
            $link->delete();

            $this->writeProductStatus($g, $product, ['sync_status' => 'pending', 'push_error' => null, 'last_pushed_at' => null]);

            return $this->productsRedirect($store, $group)
                ->with('status', 'Product deleted from Venta store.');
        }

        $error = $result['body']['error'] ?? 'Unknown error';
        return $this->productsRedirect($store, $group)
            ->with('error', 'Failed to delete from Venta: ' . $error);
    }

    public function linkProduct(int $store, int $group, int $product)
    {
        $this->store_($store);
        $g = VentaProductGroup::where('venta_setting_id', $store)->findOrFail($group);

        $pivot = VentaProductGroupProduct::where('venta_product_group_id', $g->id)
            ->where('product_id', $product)
            ->first();

        if ($pivot) {
            // Check if a venta_product_link exists for this product
            $hasLink = VentaProductLink::where('venta_setting_id', $store)
                ->where('product_id', $product)
                ->whereNotNull('venta_product_id')
                ->where('venta_product_id', '!=', 0)
                ->exists();

            $pivot->update(['sync_status' => $hasLink ? 'synced' : 'pending']);
        }

        return $this->productsRedirect($store, $group)
            ->with('status', 'Product re-linked.');
    }

    public function massRemove(Request $request, int $store, int $group)
    {
        $this->store_($store);
        $g = VentaProductGroup::where('venta_setting_id', $store)->findOrFail($group);

        $ids = array_map('intval', array_filter((array) $request->input('ids', [])));

        if (!empty($ids)) {
            $g->products()->whereIn('product_id', $ids)->delete();
        }

        return $this->productsRedirect($store, $group)
            ->with('status', count($ids) . ' product(s) removed from group.');
    }

    // ── Add Products / Remove / Search ──────────────────────

    public function addProducts(Request $request, int $store, int $group)
    {
        $this->store_($store);
        $g = VentaProductGroup::where('venta_setting_id', $store)->findOrFail($group);

        $ids = $request->input('ids', []);
        if (empty($ids)) {
            return $this->productsRedirect($store, $group)
                ->with('status', 'No products selected.');
        }

        $ids = array_map('intval', (array) $ids);
        $existing = $g->products()->pluck('product_id')->toArray();
        $added = 0;

        foreach ($ids as $pid) {
            if (in_array($pid, $existing)) continue;
            VentaProductGroupProduct::create([
                'venta_product_group_id' => $g->id,
                'product_id'             => $pid,
            ]);
            $added++;
        }

        return $this->productsRedirect($store, $group)
            ->with('status', "{$added} product(s) added to product group.");
    }

    public function removeProduct(int $store, int $group, int $product)
    {
        $this->store_($store);
        $g = VentaProductGroup::where('venta_setting_id', $store)->findOrFail($group);

        VentaProductGroupProduct::where('venta_product_group_id', $g->id)
            ->where('product_id', $product)
            ->delete();

        return $this->productsRedirect($store, $group)
            ->with('status', 'Product removed from product group.');
    }

    public function searchProducts(Request $request, int $store)
    {
        $this->store_($store);

        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');
        $q = trim((string) $request->input('q'));
        $categoryId = (int) $request->input('category_id', 0);
        $manufacturerId = (int) $request->input('manufacturer_id', 0);

        // Empty filters return the latest 100 products (the LIMIT below) so
        // operators can browse everything without first picking a category
        // or typing a search term.

        $query = DB::table($pfx . 'product as p')
            ->join($pfx . 'product_description as pd', function ($j) use ($langId) {
                $j->on('p.product_id', '=', 'pd.product_id')
                    ->where('pd.language_id', '=', $langId);
            })
            ->select('p.product_id', 'pd.name', 'p.sku', 'p.model', 'p.price', 'p.quantity');

        if ($categoryId > 0) {
            $query->join($pfx . 'product_to_category as ptc', 'p.product_id', '=', 'ptc.product_id')
                  ->where('ptc.category_id', $categoryId);
        }

        if ($manufacturerId > 0) {
            $query->where('p.manufacturer_id', $manufacturerId);
        }

        if (strlen($q) >= 2) {
            $query->where(function ($w) use ($q) {
                $w->where('pd.name', 'like', "%{$q}%")
                    ->orWhere('p.sku', 'like', "%{$q}%")
                    ->orWhere('p.model', 'like', "%{$q}%");
            });
        }

        $rows = $query->distinct()
            ->orderBy('p.product_id', 'desc')
            ->limit(100)
            ->get();

        return response()->json(['items' => $rows]);
    }

    // ── Helpers ──────────────────────────────────────────────

    private function getMatchingProductIds(VentaProductGroup $group): array
    {
        $pfx = (string) config('catalog.prefix');
        $catIds = $group->catalog_category_ids ?? [];
        $mfgIds = $group->manufacturer_ids ?? [];

        $filterIds = [];

        if (!empty($catIds) || !empty($mfgIds)) {
            $query = DB::table($pfx . 'product as p')->select('p.product_id');

            if (!empty($catIds)) {
                $query->join($pfx . 'product_to_category as ptc', 'p.product_id', '=', 'ptc.product_id')
                    ->whereIn('ptc.category_id', $catIds);
            }

            if (!empty($mfgIds)) {
                $query->whereIn('p.manufacturer_id', $mfgIds);
            }

            $filterIds = $query->distinct()->pluck('p.product_id')->toArray();
        }

        $manualIds = [];
        if ($group->exists) {
            $manualIds = $group->products()->pluck('product_id')->toArray();
        }

        return array_values(array_unique(array_merge($filterIds, $manualIds)));
    }

    private function getProductImages(string $pfx, int $productId): array
    {
        $images = [];

        // Main image
        $mainImage = DB::table($pfx . 'product')
            ->where('product_id', $productId)
            ->value('image');

        if ($mainImage && trim($mainImage) !== '') {
            $images[] = $this->imageUrl($mainImage);
        }

        // Additional images
        $additionalImages = DB::table($pfx . 'product_image')
            ->where('product_id', $productId)
            ->orderBy('sort_order')
            ->pluck('image')
            ->toArray();

        foreach ($additionalImages as $img) {
            if ($img && trim($img) !== '') {
                $images[] = $this->imageUrl($img);
            }
        }

        return $images;
    }

    /**
     * Convert a relative image path to a fully encoded public URL.
     */
    private function imageUrl(string $path): string
    {
        $segments = explode('/', ltrim($path, '/'));
        $encoded = implode('/', array_map('rawurlencode', $segments));
        return asset('storage/' . $encoded);
    }
}
