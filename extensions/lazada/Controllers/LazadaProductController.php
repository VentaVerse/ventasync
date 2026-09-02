<?php

namespace Extensions\lazada\Controllers;

use App\Http\Controllers\Controller;

use Extensions\lazada\Models\LazadaCategoryTemplate;
use Extensions\lazada\Models\LazadaCategory;
use Extensions\lazada\Models\LazadaBrand;
use Extensions\lazada\Models\LazadaApiLog;
use Extensions\lazada\Models\LazadaImageLink;
use Extensions\lazada\Models\LazadaProduct;
use Extensions\lazada\Models\LazadaProductAttribute;
use Extensions\lazada\Models\LazadaProductGroupAttribute;
use Extensions\lazada\Models\LazadaProductVariant;
use Extensions\lazada\Models\LazadaSetting;
use App\Services\ActivityLogger;
use Extensions\lazada\Services\Lazada\LazadaAttributes;
use Extensions\lazada\Services\Lazada\LazadaClient;
use Extensions\lazada\Services\Lazada\LazadaItemCache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LazadaProductController extends Controller
{
    // NOTE: We standardize on lazada_product_id (no legacy lazada_listing_id support).

    /** The attribute vocabulary lives in its own service now (Phase C, #184). */
    private function attrs(): LazadaAttributes
    {
        return app(LazadaAttributes::class);
    }

    /** The item read-back and its four caches (Phase C, #184). */
    private function itemCache(): LazadaItemCache
    {
        return app(LazadaItemCache::class);
    }


    public static function computeFinalPrice(float $basePrice, ?float $fixedMarkup, ?float $percentMarkup): float
    {
        // Formula: (Price * %) + Fixed
        $price = $basePrice;
        if ($percentMarkup !== null && $percentMarkup > 0) {
            $price += $basePrice * $percentMarkup / 100;
        }
        if ($fixedMarkup !== null && $fixedMarkup > 0) {
            $price += $fixedMarkup;
        }
        return round($price, 2);
    }


    /**
     * Convert an ERP product image path into an absolute URL that Lazada can fetch.
     *
     * - If value is already an absolute URL, keep it.
     * - Otherwise, assume the stored path is already relative to the public web root
     *   (e.g. "catalog/...").
     */
    /**
     * A product image URL for the OPERATOR'S BROWSER, not for Lazada.
     *
     * toPublicImageUrl() below builds an ABSOLUTE url, deliberately: Lazada's
     * servers fetch these over the internet, so they need a host, and that
     * host comes from catalog.public_url. That is correct for the payload and
     * wrong for this admin page, which is what emptied the listing grid.
     *
     * catalog.public_url was pointing at 192.168.122.8:8000, an address from
     * an earlier network. Every thumbnail on the page was requested from a
     * host the operator's browser cannot reach, so 2,074 products with images
     * on disk rendered as empty frames. Correcting that value would have fixed
     * it today and broken it again on the next address change, because the
     * page was asking a config setting a question it already knew the answer
     * to: the browser is already on the right host.
     *
     * So this returns a ROOT-RELATIVE path. It resolves against whatever
     * address the operator actually used - LAN ip, hostname, localhost, or a
     * tunnel - and cannot drift from it. An absolute url that is genuinely
     * somebody else's (a CDN, a marketplace cdn) is kept as it is; only
     * loopback is rewritten, because no browser but the server's own can
     * follow it.
     */
    private function toDisplayImageUrl(?string $imagePath): ?string
    {
        $imagePath = trim((string) ($imagePath ?? ''));

        if ($imagePath === '' || str_contains($imagePath, '..')) {
            return null;
        }

        if (Str::startsWith($imagePath, ['http://', 'https://'])) {
            $host = strtolower((string) parse_url($imagePath, PHP_URL_HOST));

            if (! in_array($host, ['localhost', '127.0.0.1', '0.0.0.0'], true)) {
                return $imagePath;
            }

            $path = ltrim((string) parse_url($imagePath, PHP_URL_PATH), '/');

            return ($path === '' || str_contains($path, '..'))
                ? null
                : '/' . $this->encodeUrlPath($path);
        }

        $path = ltrim($imagePath, '/');

        // Already carries a public prefix.
        if (Str::startsWith($path, ['storage/', 'image/'])) {
            return '/' . $this->encodeUrlPath($path);
        }

        // The Laravel public disk is where this install actually keeps them.
        try {
            if (file_exists(public_path('storage/' . $path))) {
                return '/' . $this->encodeUrlPath('storage/' . $path);
            }
        } catch (\Throwable $e) {
            // Fall through to the configured prefix.
        }

        $prefix = trim((string) config('catalog.image_prefix', 'image'), '/');

        return '/' . $this->encodeUrlPath(($prefix === '' ? 'image' : $prefix) . '/' . $path);
    }

    private function toPublicImageUrl(?string $imagePath): ?string
    {
        $imagePath = trim((string)($imagePath ?? ''));
        if ($imagePath === '') {
            return null;
        }

        // Basic safety: never generate URLs that include path traversal.
        if (str_contains($imagePath, '..')) {
            return null;
        }

        // If the DB already stores a full URL, keep it — except for localhost/loopback URLs.
        // Some imports (or older code) may have saved http://localhost/... which will break Lazada.
        // In that case, rewrite the host to the configured APP_URL host (or request host) while preserving the path.
        if (Str::startsWith($imagePath, ['http://', 'https://'])) {
            $host = strtolower((string) parse_url($imagePath, PHP_URL_HOST));
            if (in_array($host, ['localhost', '127.0.0.1', '0.0.0.0'], true)) {
                $base = $this->imageBaseUrl();
                $path = (string) parse_url($imagePath, PHP_URL_PATH);
                $path = ltrim($path, '/');

                if ($path === '' || str_contains($path, '..')) {
                    return null;
                }

                return $base . '/' . $path;
            }

            return $imagePath;
        }

        $base = $this->imageBaseUrl();
        $path = ltrim($imagePath, '/');

        // Many ERP installs store uploaded product images on the Laravel "public" disk
        // (public/storage -> storage/app/public). In that case, the publicly reachable URL
        // is typically "/storage/{path}" (e.g. /storage/catalog/Brand/file.jpg).
        //
        // Historically, some code assumed OpenCart-style "/image/{path}". Lazada requires
        // these URLs to return HTTP 200, so we must generate the correct public prefix.

        // If the stored path already includes a known public prefix, keep it.
        if (Str::startsWith($path, ['storage/', 'image/'])) {
            return $base . '/' . $this->encodeUrlPath($path);
        }

        // Prefer Laravel public disk if the file exists there: public/storage/{path}
        $storageCandidate = 'storage/' . $path;
        try {
            if (file_exists(public_path($storageCandidate))) {
                return $base . '/' . $this->encodeUrlPath($storageCandidate);
            }
        } catch (\Throwable $e) {
            // Ignore filesystem errors; fall back below.
        }

        // Fall back to configured prefix (default: "image").
        $prefix = trim((string) config('catalog.image_prefix', 'image'), '/');
        if ($prefix === '') {
            $prefix = 'image';
        }
        $prefixed = $prefix . '/' . $path;

        return $base . '/' . $this->encodeUrlPath($prefixed);
    }

    /**
     * Encode a relative URL path safely (encode each segment, preserve slashes).
     */
    private function encodeUrlPath(string $path): string
    {
        $path = ltrim($path, '/');
        $parts = array_map('rawurlencode', array_filter(explode('/', $path), fn($p) => $p !== ''));
        return implode('/', $parts);
    }

    /**
     * Returns the base URL used for building publicly reachable image URLs.
     *
     * We prefer config('app.url') (APP_URL), but if that is missing or somehow set to localhost,
     * we fall back to the current HTTP host.
     */
    private function imageBaseUrl(): string
    {
        // Prefer the storefront/public catalog URL. The ERP may run on a different subdomain.
        $base = rtrim((string) config('catalog.public_url', config('app.url')), '/');
        $host = strtolower((string) (parse_url($base, PHP_URL_HOST) ?? ''));

        if ($base === '' || $host === '' || in_array($host, ['localhost', '127.0.0.1', '0.0.0.0'], true)) {
            try {
                $base = rtrim(request()->getSchemeAndHttpHost(), '/');
            } catch (\Throwable $e) {
                // Ignore and keep whatever we have.
            }
        }

        return $base;
    }

    /**
     * Build a unique ordered list of product images (main + additional) as public URLs.
     */
    
    /**
     * Normalize an image URL/path into a publicly reachable URL based on APP_URL.
     * This is a last-resort guard to prevent localhost/loopback URLs from leaking into payloads.
     */
    private function normalizeImageUrl(string $urlOrPath): ?string
    {
        $urlOrPath = trim($urlOrPath);
        if ($urlOrPath === '') return null;

        // If it's a full URL, rewrite localhost/loopback hosts to APP_URL host.
        if (Str::startsWith($urlOrPath, ['http://', 'https://'])) {
            $host = strtolower((string) parse_url($urlOrPath, PHP_URL_HOST));
            if (in_array($host, ['localhost', '127.0.0.1', '0.0.0.0'], true)) {
                $base = $this->imageBaseUrl();
                $path = (string) parse_url($urlOrPath, PHP_URL_PATH);
                $path = ltrim($path, '/');
                if ($path === '' || str_contains($path, '..')) return null;
                return $base . '/' . $path;
            }
            return $urlOrPath;
        }

        // Relative path
        return $this->toPublicImageUrl($urlOrPath);
    }

private function getProductImageUrls(int $productId, ?object $productRow): array
    {
        $pfx = (string) config('catalog.prefix');

        $images = [];
        $main = $this->normalizeImageUrl((string)($productRow?->image ?? ''));
        if ($main) {
            $images[] = $main;
        }

        $additional = DB::table($pfx.'product_image')
            ->where('product_id', $productId)
            ->orderBy('sort_order')
            ->pluck('image')
            ->toArray();

        foreach ($additional as $img) {
            $url = $this->normalizeImageUrl((string) $img);
            if ($url && !in_array($url, $images, true)) {
                $images[] = $url;
            }
        }

        return $images;
    }


private function extractLazadaError(array $result): array
{
    $body = $result['body'] ?? null;
    $code = null;
    $message = null;

    if (is_array($body)) {
        // Common Lazada error format: {"type":"ISV","code":"...","message":"..."}
        $code = $body['code'] ?? ($body['error_code'] ?? null);
        $message = $body['message'] ?? ($body['error_message'] ?? null);
    }

    if ($code === null && is_string($body)) {
        $message = trim($body);
    }

    $codeStr = $code !== null ? trim((string)$code) : null;
    $msgStr = $message !== null ? trim((string)$message) : null;

    // Determine success. Lazada often returns code "0" for success.
    $okHttp = (bool)($result['ok'] ?? false);
    $okCode = ($codeStr === null) || ($codeStr === '0');
    $ok = $okHttp && $okCode;

    return [
        'ok' => $ok,
        'code' => $ok ? null : ($codeStr ?: 'UNKNOWN'),
        'message' => $ok ? null : ($msgStr ?: 'Unknown error'),
    ];
}

/**
 * Best-effort extractor for Lazada created item_id from /product/create response.
 * Lazada responses vary by region/version; we try a few common shapes.
 */
private function extractCreatedItemId(array $result): ?string
{
    $body = $result['body'] ?? null;
    if (!is_array($body)) {
        return null;
    }

    // Common shapes:
    // {"data": {"item_id": "123"}}
    // {"data": {"item_id": 123}}
    // {"item_id": "123"}
    $candidates = [
        data_get($body, 'data.item_id'),
        data_get($body, 'data.itemId'),
        data_get($body, 'item_id'),
        data_get($body, 'itemId'),
        data_get($body, 'ItemId'),
    ];

    foreach ($candidates as $v) {
        if (is_numeric($v)) {
            return (string) $v;
        }
        if (is_string($v) && trim($v) !== '') {
            return trim($v);
        }
    }

    return null;
}


private function persistProductSyncStatus(LazadaProduct $listing, string $action, array $result, ?string $pushSource = null): void
{
    $e = $this->extractLazadaError($result);

    $listing->last_synced_at = now();
    $listing->last_sync_action = $action;
    $listing->last_sync_ok = (bool)($e['ok'] ?? false);
    $listing->last_sync_error_code = $e['code'] ?? null;
    $listing->last_sync_error_message = $e['message'] ?? null;

    /* Provenance (#104, Lazada half): a SUCCESSFUL content push records
     * what went and where its settings came from - 'listing' for pushes
     * run on the listing's own record, 'group:{name}' for group pushes.
     * Sync and failure calls pass no source and stamp nothing. */
    if ($pushSource !== null && ($e['ok'] ?? false)) {
        $listing->last_pushed_at = now();
        $listing->last_push_source = $pushSource;
        $listing->last_push_settings = [
            'category_id' => (int) ($listing->primary_category_id ?? 0),
            'brand_id' => (int) ($listing->brand_id ?? 0),
            'markup_percent' => $listing->markup_percent,
            'markup_fixed' => $listing->markup_fixed,
        ];
    }

    // Avoid throwing if DB schema isn't migrated yet.
    try {
        $listing->save();
    } catch (\Throwable $ex) {
        // ignore
    }
}

    /** Listings-strip tab key => the status word Lazada returns on an item (and the mirror stores). */
    public const TAB_STATUS = [
        'active' => 'active',
        'inactive' => 'inactive',
        'pending' => 'pending',
        'violation' => 'rejected',
        'soldout' => 'sold-out',
        'deleted' => 'deleted',
    ];

    public function index(Request $request)
    {
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        /* ── The seller center's own tabs (Lazada parity, 2026-08-24) ── */
        $tabMap = [
            'active' => 'live',
            'inactive' => 'inactive',
            'pending' => 'pending',
            'violation' => 'rejected',
            'soldout' => 'sold-out',
            'deleted' => 'deleted',
        ];
        $lazadaTab = (string) $request->query('lazada_tab', 'all');
        if (!array_key_exists($lazadaTab, $tabMap)) {
            $lazadaTab = 'all';
        }

        /* Since 2026-08-28 the counts and the per-row statuses come from
         * the MIRROR on the listing rows (live_status, live_checked_at),
         * refreshed every 30 minutes by the automation, on demand by the
         * Refresh button, and written through by every push, toggle,
         * match, delete and unlink the ERP makes (#184 C). A page view
         * asks Lazada for nothing beyond filling its own blanks. */
        $liveSetting = LazadaSetting::query()->first()?->decrypted();
        $liveCreds = LazadaSetting::activeCredentials($liveSetting);
        $liveCounts = null;
        $liveTabError = null;
        $liveCheckedAt = null;
        if ($liveSetting && $liveCreds['complete']) {
            $statusMap = self::TAB_STATUS;
            $liveCounts = LazadaProduct::mirrorCounts($statusMap);
            $liveCheckedAt = LazadaProduct::query()->max('live_checked_at');
            $liveCheckedAt = $liveCheckedAt ? \Illuminate\Support\Carbon::parse($liveCheckedAt) : null;
        } else {
            $lazadaTab = 'all';
        }

        // Search & filter params
        $q = trim((string) $request->query('q', ''));
        $syncStatus = (string) $request->query('sync_status', 'all');
        if (!in_array($syncStatus, ['all', 'not_uploaded', 'uploaded', 'deleted', 'error', 'ready', 'not_ready'], true)) {
            $syncStatus = 'all';
        }
        $groupFilter = (string) $request->query('group', 'all');
        $manufacturerFilter = (string) $request->query('manufacturer', 'all');
        $erpStatus = (string) $request->query('erp_status', 'all');

        /* Sorting: allow-listed and mapped to a real column, ordered in
         * SQL - this page paginates at the database now (Phase C, #184),
         * where it used to load every row and sort a collection. */
        $sortColumns = [
            'id' => 'p.product_id',
            'product' => 'pd.name',
            'quantity' => 'p.quantity',
            'price' => 'p.price',
            'product_status' => 'p.status',
            'manufacturer' => 'm.name',
        ];
        // Listing-side sorts are expressions over the joined row, stated
        // here rather than interpolated from input.
        $sortExpressions = [
            'lazada_item_id' => "(lp.lazada_item_id IS NULL OR lp.lazada_item_id = ''), lp.lazada_item_id",
            'lazada_status' => "CASE WHEN lp.lazada_item_id IS NOT NULL AND lp.lazada_item_id <> '' THEN 2 WHEN lp.lazada_deleted_at IS NOT NULL THEN 1 ELSE 0 END",
            'last_sync' => "CASE WHEN lp.last_sync_ok IS NULL THEN 0 WHEN lp.last_sync_ok = 1 THEN 1 ELSE 2 END, lp.last_synced_at",
        ];
        $sort = (string) $request->query('sort', 'id');
        if (!array_key_exists($sort, $sortColumns) && !array_key_exists($sort, $sortExpressions)) {
            $sort = 'id';
        }
        $dir = strtolower((string) $request->query('dir', 'desc')) === 'asc' ? 'asc' : 'desc';

        /* ── The catalogue is the table (Phase C, #184) ──
         *
         * The query starts from the PRODUCT, exactly as Shopee's does,
         * and left-joins the listing row. Before this the page listed
         * lazada_products rows only - the CedCommerce worldview, where a
         * product existed for Lazada once someone had made a mapping row
         * for it. A product without one was invisible even under the
         * "Not listed" filter, the "All products" count was really the
         * mapping count, and every sort loaded the whole table into
         * memory. One preferred listing row per product: the one actually
         * linked to Lazada, else the newest. */
        $lpPick = DB::table('lazada_products')
            ->selectRaw("product_id, COALESCE(MAX(CASE WHEN lazada_item_id IS NOT NULL AND lazada_item_id <> '' AND unlinked_at IS NULL THEN id END), MAX(id)) as pick_id")
            ->whereNotNull('product_id')
            ->groupBy('product_id');

        $query = DB::table($pfx . 'product as p')
            ->leftJoin($pfx . 'product_description as pd', function ($j) use ($langId) {
                $j->on('p.product_id', '=', 'pd.product_id')
                    ->where('pd.language_id', '=', $langId);
            })
            ->leftJoin($pfx . 'manufacturer as m', 'p.manufacturer_id', '=', 'm.manufacturer_id')
            ->leftJoinSub($lpPick, 'lps', 'lps.product_id', '=', 'p.product_id')
            ->leftJoin('lazada_products as lp', 'lp.id', '=', 'lps.pick_id')
            ->select(
                'p.product_id', 'pd.name', 'p.image', 'p.model', 'p.sku',
                'p.price', 'p.quantity', 'p.status', 'm.name as manufacturer_name',
                'lp.id as listing_id', 'lp.lazada_item_id', 'lp.live_status',
                'lp.lazada_deleted_at', 'lp.unlinked_at',
                'lp.last_sync_ok', 'lp.last_synced_at',
                'lp.last_sync_error_code', 'lp.last_sync_error_message'
            );

        // Product group filter, through the pivot's denormalised product_id
        if ($groupFilter === 'none') {
            $groupedIds = DB::table('lazada_product_group_products')->whereNotNull('product_id')->pluck('product_id')->unique()->all();
            if (!empty($groupedIds)) {
                $query->whereNotIn('p.product_id', $groupedIds);
            }
        } elseif ($groupFilter !== 'all') {
            $specificIds = DB::table('lazada_product_group_products')
                ->where('lazada_product_group_id', (int) $groupFilter)
                ->whereNotNull('product_id')->pluck('product_id')->unique()->all();
            $query->whereIn('p.product_id', $specificIds ?: [0]);
        }

        // Manufacturer filter
        if ($manufacturerFilter !== 'all') {
            $query->where('p.manufacturer_id', (int) $manufacturerFilter);
        }

        // ERP Status filter
        if ($erpStatus === 'enabled') {
            $query->where('p.status', 1);
        } elseif ($erpStatus === 'disabled') {
            $query->where('p.status', 0);
        }

        // Text search: catalogue fields, or the Lazada item id itself
        if ($q !== '') {
            $query->where(function ($sub) use ($q) {
                $sub->where('pd.name', 'like', '%' . $q . '%')
                    ->orWhere('p.model', 'like', '%' . $q . '%')
                    ->orWhere('p.sku', 'like', '%' . $q . '%')
                    ->orWhere('lp.lazada_item_id', 'like', '%' . $q . '%');
            });
        }

        // Sync status filter
        if ($syncStatus === 'uploaded') {
            $query->whereNotNull('lp.lazada_item_id')->where('lp.lazada_item_id', '!=', '');
        } elseif ($syncStatus === 'not_uploaded') {
            $query->where(function ($sub) {
                $sub->whereNull('lp.lazada_item_id')->orWhere('lp.lazada_item_id', '=', '');
            })->whereNull('lp.lazada_deleted_at');
        } elseif ($syncStatus === 'deleted') {
            $query->whereNotNull('lp.lazada_deleted_at');
        } elseif ($syncStatus === 'error') {
            /* The reason to open this page: what failed, filterable. */
            $query->where('lp.last_sync_ok', false)->whereNotNull('lp.last_sync_ok');
        } elseif ($syncStatus === 'ready' || $syncStatus === 'not_ready') {
            /* The computed answer (#101): which unlisted products could be
             * pushed RIGHT NOW - by the same LazadaListingReadiness every
             * other surface reads, rows and rowless products alike. */
            $readiness = app(\Extensions\lazada\Services\Lazada\LazadaListingReadiness::class);
            $candidateRows = LazadaProduct::query()
                ->where(function ($sub) {
                    $sub->whereNull('lazada_item_id')->orWhere('lazada_item_id', '=', '');
                })
                ->whereNull('lazada_deleted_at')->whereNotNull('product_id')->get();
            $rowAnswers = $readiness->forListings($candidateRows);
            $readyByProduct = [];
            foreach ($candidateRows as $row) {
                $readyByProduct[(int) $row->product_id] = (bool) ($rowAnswers[(int) $row->id]['ready'] ?? false);
            }
            $mappedIds = LazadaProduct::query()->whereNotNull('product_id')->pluck('product_id')->map(fn ($v) => (int) $v)->unique()->all();
            $rowlessIds = DB::table($pfx . 'product')
                ->when(!empty($mappedIds), fn ($qq) => $qq->whereNotIn('product_id', $mappedIds))
                ->pluck('product_id')->map(fn ($v) => (int) $v)->all();
            foreach ($readiness->forProducts($rowlessIds) as $pid => $answer) {
                $readyByProduct[$pid] = (bool) $answer['ready'];
            }
            $wantReady = $syncStatus === 'ready';
            $matching = array_keys(array_filter($readyByProduct, fn ($r) => $r === $wantReady));
            $query->whereIn('p.product_id', $matching ?: [0]);
        }

        // The catalogue's own total, before a Lazada tab narrows the table.
        $catalogueTotal = (clone $query)->count();

        // A live tab narrows the table to the items Lazada holds in that
        // status right now. Only uploaded rows can match.
        if ($lazadaTab !== 'all' && $liveCounts !== null) {
            $query->where('lp.live_status', self::TAB_STATUS[$lazadaTab])
                ->whereNotNull('lp.lazada_item_id')->where('lp.lazada_item_id', '!=', '');
        }

        // Unlinked leftovers stay pinned to the top, as they always were:
        // a cut link is unfinished business, not one row among two thousand.
        $query->orderByRaw('(lp.unlinked_at IS NOT NULL) desc');
        if (isset($sortExpressions[$sort])) {
            $query->orderByRaw($sortExpressions[$sort] . ' ' . $dir);
        } else {
            $query->orderBy($sortColumns[$sort], $dir);
        }
        // A stable tiebreak, or two products with the same name swap places
        // between pages and a row appears twice while another never shows.
        if (($sortColumns[$sort] ?? null) !== 'p.product_id') {
            $query->orderBy('p.product_id', 'asc');
        }

        $products = $query->paginate(50)->withQueryString();

        foreach ($products as $row) {
            if (isset($row->name)) {
                $row->name = html_entity_decode($row->name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }

        $pageIds = $products->pluck('product_id')->map(fn ($v) => (int) $v)->all();
        $pageListingIds = $products->pluck('listing_id')->filter()->map(fn ($v) => (int) $v)->all();

        // The listing models for this page (groups ride along for display)
        $listingsById = LazadaProduct::query()
            ->with('groups')
            ->whereIn('id', $pageListingIds ?: [0])
            ->get()
            ->keyBy('id');

        // Thumbnails and variation rows, page-sized
        $productThumbsById = collect();
        $optionRowsByProductId = collect();
        if (!empty($pageIds)) {
            $productThumbsById = $products->mapWithKeys(function ($r) {
                return [(int) $r->product_id => $this->toDisplayImageUrl($r->image ?? null)];
            });
            $optionRowsByProductId = \App\Support\VariationRows::forProducts($pageIds);
        }

        /* The mirror fills its own blanks (the owner, 2026-08-28): a row with
         * an item id and no status is asked about once, up to ten per
         * view, then the page reads the mirror like every other row. */
        if ($liveCounts !== null) {
            $blank = $listingsById->filter(fn ($l) => !empty($l->lazada_item_id) && $l->live_status === null)->take(10);
            if ($blank->isNotEmpty()) {
                $live = app(\Extensions\lazada\Services\Lazada\LazadaLiveListing::class);
                $filled = false;
                foreach ($blank as $l) {
                    $answer = $live->fetch($liveSetting, $liveCreds, (string) $l->lazada_item_id);
                    $status = null;
                    if ($answer['live'] !== null && ($answer['live']['status'] ?? '') !== '') {
                        $status = (string) $answer['live']['status'];
                    } elseif ($answer['live'] === null && str_contains((string) $answer['error'], 'holds no record')) {
                        $status = 'missing';
                    }
                    if ($status === null) {
                        break; // Lazada is not answering; leave the rest blank rather than guessed
                    }
                    LazadaProduct::query()->where('id', $l->id)
                        ->update(['live_status' => $status, 'live_checked_at' => now()]);
                    $l->live_status = $status;
                    $filled = true;
                }
                if ($filled) {
                    $liveCounts = LazadaProduct::mirrorCounts(self::TAB_STATUS);
                    $liveCheckedAt = $liveCheckedAt ?? now();
                }
            }
        }

        /* ONE state per row (Phase C, #184): the engine folds the mirror,
         * drift and readiness into a single ListingState the blade renders
         * verbatim - badge, readiness and row actions alike (#188).
         * Computed after the blank fill so it reads a filled mirror. */
        $listingStates = app(\Extensions\lazada\Services\Lazada\LazadaListingStates::class)
            ->forProducts($pageIds);

        // How many catalogue products actually stand on Lazada, for the
        // page's subtitle - an honest count, not the mapping-row count.
        $listedTotal = LazadaProduct::query()
            ->whereNotNull('lazada_item_id')->where('lazada_item_id', '!=', '')
            ->whereNull('unlinked_at')->whereNotNull('product_id')
            ->distinct()->count('product_id');

        /* Orphan listings (Phase C, #184): rows whose catalog product no
         * longer exists. The catalogue-first table cannot carry them -
         * there is no product row to hang them on - and hiding them would
         * hide money possibly still sitting on Lazada, so they surface as
         * their own short queue above the table, like unmatched items. */
        $orphanListings = LazadaProduct::query()
            ->where(function ($sub) use ($pfx) {
                $sub->whereNull('product_id')
                    ->orWhereNotExists(function ($qq) use ($pfx) {
                        $qq->select(DB::raw(1))
                            ->from($pfx . 'product as op')
                            ->whereColumn('op.product_id', 'lazada_products.product_id');
                    });
            })
            ->orderByDesc('id')
            ->get();
        $orphanStates = app(\Extensions\lazada\Services\Lazada\LazadaListingStates::class)
            ->forProducts($orphanListings->pluck('product_id')->filter()->map(fn ($v) => (int) $v)->all());

        $unmatchedItems = \Extensions\lazada\Models\LazadaUnmatchedItem::query()
            ->where('status', 'unmatched')
            ->orderBy('item_name')
            ->get();

        $allManufacturers = DB::table($pfx . 'manufacturer')
            ->orderBy('name')
            ->pluck('name', 'manufacturer_id');

        $allGroups = \Extensions\lazada\Models\LazadaProductGroup::query()->orderBy('name')->pluck('name', 'id');

        return view('ext-lazada::products.index', [
            'products' => $products,
            'listingsById' => $listingsById,
            'listingStates' => $listingStates,
            'productThumbsById' => $productThumbsById,
            'optionRowsByProductId' => $optionRowsByProductId,
            'allGroups' => $allGroups,
            'allManufacturers' => $allManufacturers,
            'sort' => $sort,
            'dir' => $dir,
            'q' => $q,
            'syncStatus' => $syncStatus,
            'manufacturerFilter' => $manufacturerFilter,
            'erpStatus' => $erpStatus,
            'groupFilter' => $groupFilter,
            'paginator' => $products,
            'unmatchedItems' => $unmatchedItems,
            'orphanListings' => $orphanListings,
            'orphanStates' => $orphanStates,
            'lazadaTab' => $lazadaTab,
            'liveCounts' => $liveCounts,
            'liveTabError' => $liveTabError,
            'liveCheckedAt' => $liveCheckedAt,
            'catalogueTotal' => $catalogueTotal,
            'listedTotal' => $listedTotal,
        ]);
    }

    /** The Refresh button on the listings strip: the same walk the 30-minute automation runs. */
    public function refreshStatus(Request $request, \Extensions\lazada\Services\Lazada\LazadaLiveListing $live)
    {
        $setting = LazadaSetting::query()->first()?->decrypted();
        $creds = LazadaSetting::activeCredentials($setting);
        if (!$setting || !$creds['complete']) {
            return redirect()->back()->with('error', 'Missing Lazada settings.');
        }

        $result = $live->refreshMirror($setting, $creds);
        if ($result['error'] !== null) {
            return redirect()->back()->with('error', 'Lazada did not answer: ' . $result['error'] . ' The statuses shown are from the last refresh.');
        }

        // One word per state, the engine's (#184 C): 'rejected', not 'in violation'.
        $labels = ['active' => 'active', 'inactive' => 'inactive', 'pending' => 'pending QC', 'rejected' => 'rejected',
            'sold-out' => 'sold out', 'soldout' => 'sold out', 'deleted' => 'deleted', 'missing' => 'not found on Lazada'];
        $parts = [];
        foreach ($result['counts'] as $status => $n) {
            $parts[] = number_format($n) . ' ' . ($labels[$status] ?? $status);
        }

        return redirect()->back()->with('status', 'Refreshed from Lazada: ' . ($parts ? implode(', ', $parts) : 'no uploaded listings') . '.');
    }

    /**
     * Sync ERP product quantity to Lazada using Seller SKU.
     * NOTE: Your app may not have permission for this API yet — we still call it so you can see the exact error.
     */
    public function syncQuantity(Request $request, int $id, LazadaClient $client)
    {
        $listing = LazadaProduct::query()->findOrFail($id);

        $setting = LazadaSetting::query()->first()?->decrypted();
        $creds = LazadaSetting::activeCredentials($setting);
        if (!$setting || !$setting->region || !$creds['app_key'] || !$creds['app_secret'] || !$creds['access_token']) {
            return redirect()->to(url()->previous(route('ext.lazada.products.index')))
                ->with('status', 'Missing Lazada settings (region/app key/app secret/access token).');
        }

        $pfx = (string) config('catalog.prefix');

        $productId = (int) $listing->product_id;

        $product = DB::table($pfx.'product as p')
            ->where('p.product_id', $productId)
            ->first(['p.product_id', 'p.sku', 'p.quantity', 'p.status']);

        if (!$product) {
            return redirect()->to(url()->previous(route('ext.lazada.products.index')))
                ->with('status', 'Cannot sync: ERP product not found for listing #' . $listing->id . '.');
        }

        if ((int) $product->status === 0) {
            return redirect()->to(url()->previous(route('ext.lazada.products.index')))
                ->with('status', 'Cannot sync quantity: product #' . $productId . ' is disabled. Enable it first.');
        }

        // Resolve Lazada SkuId for each variant (required by Lazada production API)
        if (empty($listing->lazada_item_id)) {
            return redirect()->to(url()->previous(route('ext.lazada.products.index')))
                ->with('status', 'Cannot sync qty: product has no Lazada Item ID. Sync Lazada ID first.');
        }
        $skuIdMap = $this->itemCache()->resolveSkuIds($listing, $setting, $client);
        if (empty($skuIdMap)) {
            return redirect()->to(url()->previous(route('ext.lazada.products.index')))
                ->with('status', 'Cannot sync qty: could not resolve Lazada SkuIds for this product.');
        }

        // Build ERP variant SKUs + quantities
        $variantSkus = $this->getErpVariantStockByProductId($productId);
        if (empty($variantSkus)) {
            $sku = trim((string) ($product->sku ?? ''));
            if ($sku === '') {
                return redirect()->to(url()->previous(route('ext.lazada.products.index')))
                    ->with('status', 'Cannot sync: ERP product SKU is empty for product #' . (int)$product->product_id . '.');
            }
            $variantSkus = [[
                'seller_sku' => $sku,
                'quantity' => max(0, (int) ($product->quantity ?? 0)),
            ]];
        }

        $apiPath = '/product/price_quantity/update';
        $ok = 0;
        $err = 0;
        $last = null;

        foreach ($variantSkus as $v) {
            $sku = trim((string)($v['seller_sku'] ?? ''));
            if ($sku === '') {
                continue;
            }
            $skuId = $skuIdMap[$sku] ?? null;
            if ($skuId === null) {
                $err++;
                continue;
            }
            $qty = max(0, (int)($v['quantity'] ?? 0));
            $payloadXml = $this->buildQuantityUpdateXml($skuId, $qty);

            $timestamp = (string) round(microtime(true) * 1000);
            $params = [
                'app_key' => (string) $creds['app_key'],
                'sign_method' => 'sha256',
                'timestamp' => $timestamp,
                'access_token' => (string) $creds['access_token'],
                'payload' => $payloadXml,
            ];
            $params['sign'] = $client->sign($apiPath, $params, (string) $creds['app_secret']);

            $result = $client->post((string) $setting->region, $apiPath, $params);
            $last = $result;

            LazadaApiLog::safeCreate([
                'pack' => 'lazada.product.price_quantity.update.variant',
                'method' => 'POST',
                'api_path' => $apiPath,
                'auth_required' => true,
                'request_params' => $params,
                'response_status' => (int)($result['status'] ?? 0),
                'ok' => (bool)($result['ok'] ?? false),
                'response_body' => $result['body'] ?? $result,
                'user_id' => auth()->id(),
            ]);

            $e = $this->extractLazadaError($result);
            if ($e['ok']) {
                $ok++;
            } else {
                $err++;
            }
        }

        $summary = [
            'ok' => $err === 0,
            'status' => (int)($last['status'] ?? 200),
            'body' => [
                'code' => $err === 0 ? '0' : 'PARTIAL_FAILURE',
                'message' => "Synced qty for ".count($variantSkus)." SKU(s). Success: {$ok}, Error: {$err}.",
                'last' => $last['body'] ?? $last,
            ],
        ];

        $this->persistProductSyncStatus($listing, 'sync_quantity', $summary);

        return redirect()->to(url()->previous(route('ext.lazada.products.index')))
            ->with('status', $summary['body']['message']);
    }

    /**
     * Sync ERP product price to Lazada using Seller SKU.
     * NOTE: Your app may not have permission for this API yet — we still call it so you can see the exact error.
     */
    public function syncPrice(Request $request, int $id, LazadaClient $client)
    {
        $listing = LazadaProduct::query()->findOrFail($id);

        $setting = LazadaSetting::query()->first()?->decrypted();
        $creds = LazadaSetting::activeCredentials($setting);
        if (!$setting || !$setting->region || !$creds['app_key'] || !$creds['app_secret'] || !$creds['access_token']) {
            return redirect()->to(url()->previous(route('ext.lazada.products.index')))
                ->with('status', 'Missing Lazada settings (region/app key/app secret/access token).');
        }

        $pfx = (string) config('catalog.prefix');

        $productId = (int) $listing->product_id;

        $product = DB::table($pfx.'product as p')
            ->where('p.product_id', $productId)
            ->first(['p.product_id', 'p.sku', 'p.price']);

        if (!$product) {
            return redirect()->to(url()->previous(route('ext.lazada.products.index')))
                ->with('status', 'Cannot sync: ERP product not found for listing #' . $listing->id . '.');
        }

        $basePrice = (float) ($product->price ?? 0);
        if ($basePrice < 0) {
            $basePrice = 0;
        }

        // Resolve effective markup (listing-level first, then group fallback)
        $fixedMarkup = $listing->markup_fixed;
        $percentMarkup = $listing->markup_percent;
        if ($fixedMarkup === null && $percentMarkup === null) {
            $mkGroup = $listing->groups()->first();
            if ($mkGroup) {
                $fixedMarkup = $mkGroup->markup_fixed;
                $percentMarkup = $mkGroup->markup_percent;
            }
        }

        // Resolve Lazada SkuId for each variant (required by Lazada production API)
        if (empty($listing->lazada_item_id)) {
            return redirect()->to(url()->previous(route('ext.lazada.products.index')))
                ->with('status', 'Cannot sync price: product has no Lazada Item ID. Sync Lazada ID first.');
        }
        $skuIdMap = $this->itemCache()->resolveSkuIds($listing, $setting, $client);
        if (empty($skuIdMap)) {
            return redirect()->to(url()->previous(route('ext.lazada.products.index')))
                ->with('status', 'Cannot sync price: could not resolve Lazada SkuIds for this product.');
        }

        // If product has option SKUs, sync all variant prices (base +/- option adjustment).
        // Otherwise, sync main product SKU.
        $variantPrices = $this->getErpVariantPricesByProductId($productId, $basePrice);
        if (empty($variantPrices)) {
            $sku = trim((string) ($product->sku ?? ''));
            if ($sku === '') {
                return redirect()->to(url()->previous(route('ext.lazada.products.index')))
                    ->with('status', 'Cannot sync: ERP product SKU is empty for product #' . (int)$product->product_id . '.');
            }
            $variantPrices = [[
                'seller_sku' => $sku,
                'price' => $basePrice,
            ]];
        }

        // Apply markup to all variant prices
        foreach ($variantPrices as &$vp) {
            $vp['price'] = self::computeFinalPrice((float) $vp['price'], $fixedMarkup, $percentMarkup);
        }
        unset($vp);

        $apiPath = '/product/price_quantity/update';
        $ok = 0;
        $err = 0;
        $last = null;

        foreach ($variantPrices as $v) {
            $sku = trim((string)($v['seller_sku'] ?? ''));
            if ($sku === '') {
                continue;
            }
            $skuId = $skuIdMap[$sku] ?? null;
            if ($skuId === null) {
                $err++;
                continue;
            }
            $price = (float)($v['price'] ?? 0);
            if ($price < 0) $price = 0;
            $priceStr = number_format($price, 2, '.', '');
            $payloadXml = $this->buildPriceUpdateXml($skuId, $priceStr);

            $timestamp = (string) round(microtime(true) * 1000);
            $params = [
                'app_key' => (string) $creds['app_key'],
                'sign_method' => 'sha256',
                'timestamp' => $timestamp,
                'access_token' => (string) $creds['access_token'],
                'payload' => $payloadXml,
            ];
            $params['sign'] = $client->sign($apiPath, $params, (string) $creds['app_secret']);

            $result = $client->post((string) $setting->region, $apiPath, $params);
            $last = $result;

            LazadaApiLog::safeCreate([
                'pack' => 'lazada.product.price_quantity.update.variant',
                'method' => 'POST',
                'api_path' => $apiPath,
                'auth_required' => true,
                'request_params' => $params,
                'response_status' => (int)($result['status'] ?? 0),
                'ok' => (bool)($result['ok'] ?? false),
                'response_body' => $result['body'] ?? $result,
                'user_id' => auth()->id(),
            ]);

            $e = $this->extractLazadaError($result);
            if ($e['ok']) {
                $ok++;
            } else {
                $err++;
            }
        }

        $summary = [
            'ok' => $err === 0,
            'status' => (int)($last['status'] ?? 200),
            'body' => [
                'code' => $err === 0 ? '0' : 'PARTIAL_FAILURE',
                'message' => "Synced price for ".count($variantPrices)." SKU(s). Success: {$ok}, Error: {$err}.",
                'last' => $last['body'] ?? $last,
            ],
        ];

        $this->persistProductSyncStatus($listing, 'sync_price', $summary);

        return redirect()->to(url()->previous(route('ext.lazada.products.index')))
            ->with('status', $summary['body']['message']);
    }

    /**
     * Delete/Remove a product on Lazada using Seller SKU.
     * API Path: /product/remove
     * NOTE: Your app may not have permission for this API yet — we still call it so you can see the exact error.
     */
    public function deleteFromLazada(Request $request, int $id, LazadaClient $client)
    {
        $listing = LazadaProduct::query()->findOrFail($id);

        $setting = LazadaSetting::query()->first()?->decrypted();
        $creds = LazadaSetting::activeCredentials($setting);
        if (!$setting || !$setting->region || !$creds['app_key'] || !$creds['app_secret'] || !$creds['access_token']) {
            return redirect()->to(url()->previous(route('ext.lazada.products.index')))
                ->with('status', 'Missing Lazada settings (region/app key/app secret/access token).');
        }

        $pfx = (string) config('catalog.prefix');

        $product = DB::table($pfx.'product as p')
            ->where('p.product_id', (int) $listing->product_id)
            ->first(['p.product_id', 'p.sku']);

        if (!$product) {
            return redirect()->to(url()->previous(route('ext.lazada.products.index')))
                ->with('status', 'Cannot delete: ERP product not found for listing #' . $listing->id . '.');
        }

        $productId = (int) ($product->product_id ?? 0);
        $mainSku = trim((string) ($product->sku ?? ''));

        // For products with options, remove all variant SKUs (SellerSku list) when possible.
        $variantSkus = $this->getErpVariantStockByProductId($productId);
        $sellerSkuList = [];
        foreach ($variantSkus as $v) {
            $s = trim((string)($v['seller_sku'] ?? ''));
            if ($s !== '') {
                $sellerSkuList[] = $s;
            }
        }
        $sellerSkuList = array_values(array_unique($sellerSkuList));

        if (empty($sellerSkuList)) {
            if ($mainSku === '') {
                return redirect()->to(url()->previous(route('ext.lazada.products.index')))
                    ->with('status', 'Cannot delete: ERP product SKU is empty for product #' . (int)$productId . '.');
            }
            $sellerSkuList = [$mainSku];
        } else {
            if ($mainSku !== '' && !in_array($mainSku, $sellerSkuList, true)) {
                $sellerSkuList[] = $mainSku;
            }
        }

        $apiPath = '/product/remove';
        $timestamp = (string) round(microtime(true) * 1000);
        // Lazada /product/remove expects seller_sku_list and/or sku_id_list as API params (NOT XML payload).
        // Prefer sku_id_list when we have lazada_item_id + sku_id to avoid ambiguity when duplicate SellerSku exists.

        $skuIdList = [];

        // IMPORTANT:
        // We currently do not persist Lazada sku_id mapping for each variant in DB.
        // To keep delete reliable (and match previously successful calls), if we have lazada_item_id,
        // fetch sku ids via /product/item/get and build sku_id_list.
        $itemId = trim((string) ($listing->lazada_item_id ?? ''));
        if ($itemId !== '') {
            try {
                $skuIdList = $this->itemCache()->fetchSkuIdListByItemId(
                    $client,
                    (string) $setting->region,
                    (string) $creds['app_key'],
                    (string) $creds['app_secret'],
                    (string) $creds['access_token'],
                    $itemId
                );
            } catch (\Throwable $ex) {
                // best-effort only
            }
        }

        $params = [
            'app_key' => (string) $creds['app_key'],
            'sign_method' => 'sha256',
            'timestamp' => $timestamp,
            'access_token' => (string) $creds['access_token'],
        ];

        // If sku_id_list is available, prefer sending ONLY sku_id_list.
        // This matches the previously successful behavior in your logs and avoids Lazada edge cases where
        // seller_sku_list-only calls return E006.
        if (!empty($skuIdList)) {
            $params['sku_id_list'] = json_encode($skuIdList, JSON_UNESCAPED_SLASHES);
        } else {
            $params['seller_sku_list'] = json_encode($sellerSkuList, JSON_UNESCAPED_SLASHES);
        }
        $params['sign'] = $client->sign($apiPath, $params, (string) $creds['app_secret']);

        // /product/remove does NOT consistently support GET across regions/accounts.
        // We keep this strictly POST (asForm) to avoid UnsupportedHTTPMethod.
        // For E006 (internal error), do a single safe POST retry with a fresh timestamp/sign.
        $attempts = [];
        $result = $client->post((string) $setting->region, $apiPath, $params);
        $attempts[] = ['method' => 'POST', 'params' => $params, 'result' => $result];

        $err = $this->extractLazadaError($result);
        if (!empty($err['code']) && (string) $err['code'] === '6') {
            $paramsRetry = $params;
            $paramsRetry['timestamp'] = (string) round(microtime(true) * 1000);
            $paramsRetry['sign'] = $client->sign($apiPath, $paramsRetry, (string) $creds['app_secret']);
            $result = $client->post((string) $setting->region, $apiPath, $paramsRetry);
            $attempts[] = ['method' => 'POST', 'params' => $paramsRetry, 'result' => $result];
        }

        $this->persistProductSyncStatus($listing, 'delete_from_lazada', $result);

        // If delete is successful, mark as deleted locally and clear lazada_item_id.
        $e = $this->extractLazadaError($result);
        if (!empty($e['ok'])) {
            try {
                $listing->lazada_item_id = null;
                $listing->lazada_deleted_at = now();
                // The link is gone; a mirror value for it would be stale the
                // moment it survived (#184 C).
                $listing->live_status = null;
                $listing->live_checked_at = null;
                $listing->save();
            } catch (\Throwable $ex) {
                // Avoid throwing if DB schema isn't migrated yet.
            }
        }

        // Log the final attempt + any fallback attempt for troubleshooting.
        foreach ($attempts as $a) {
            $r = $a['result'] ?? [];
            LazadaApiLog::safeCreate([
                'pack' => 'lazada.product.remove',
                'method' => (string) ($a['method'] ?? 'POST'),
                'api_path' => $apiPath,
                'auth_required' => true,
                'request_params' => (array) ($a['params'] ?? $params),
                'response_status' => (int) ($r['status'] ?? 0),
                'ok' => (bool) ($r['ok'] ?? false),
                'response_body' => $r['body'] ?? $r,
                'user_id' => auth()->id(),
            ]);
        }

        ActivityLogger::log('deleted', 'Lazada Product', $listing->id, 'ERP #' . (int)$listing->product_id);

        return redirect()->to(url()->previous(route('ext.lazada.products.index')))
            ->with('status', $this->formatLazadaResultMessage('Delete (Lazada)', $result));
    }

    // ── Unlink Product from Lazada ──────────────────────────────────

    public function unlink(int $id)
    {
        $listing = LazadaProduct::query()->findOrFail($id);

        $productId = $listing->product_id;
        $itemId = $listing->lazada_item_id;

        // Mark as unlinked — product_id stays so display info is preserved
        $listing->lazada_item_id = null;
        $listing->unlinked_at = now();
        // The mirror described the link that was just cut (#184 C).
        $listing->live_status = null;
        $listing->live_checked_at = null;
        $listing->save();

        // Remove cached Lazada variants so sync jobs skip this listing
        $listing->variants()->delete();

        $label = $itemId ? "Lazada item {$itemId}" : "listing #{$id}";

        return redirect()->back()->with('status', "Unlinked ERP product #{$productId} from {$label}.");
    }

    // ── Remove unlinked listing from local list ─────────────────────

    public function removeFromList(int $id)
    {
        $listing = LazadaProduct::query()->findOrFail($id);

        if (!$listing->unlinked_at) {
            return redirect()->back()->with('error', 'Cannot remove a linked product. Unlink it first.');
        }

        $itemId = $listing->lazada_item_id;

        // Delete variant mappings and the listing record locally — does NOT touch Lazada
        $listing->variants()->delete();
        $listing->groups()->detach();
        $listing->delete();

        $label = $itemId ? "Lazada item {$itemId}" : "listing #{$id}";

        return redirect()->back()->with('status', "Removed {$label} from the local list. The Lazada listing was not affected.");
    }

    /**
     * Upload/Create a product on Lazada for this listing.
     * API Path: /product/create
     */

    /**
     * Sync Lazada Item ID into ERP by Seller SKU.
     * Use case: product exists on Lazada already (manual upload / migrated catalog), but ERP has no lazada_item_id yet.
     * Only allowed when lazada_item_id is NULL to avoid accidental relinking.
     */
    public function syncLazadaId(Request $request, int $id, LazadaClient $client)
    {
        $listing = LazadaProduct::query()->findOrFail($id);

        if (!empty($listing->lazada_item_id) && !$listing->unlinked_at) {
            // Already linked and not unlinked — full cache refresh
            $setting = LazadaSetting::query()->first()?->decrypted();
            $creds = LazadaSetting::activeCredentials($setting);
            if ($setting) {
                $this->itemCache()->refreshListing($listing, $setting, $client);
            }
            return redirect()->to(url()->previous(route('ext.lazada.products.index')))->with('status', 'Sync Lazada ID: cache refreshed.');
        }

        $setting = LazadaSetting::query()->first()?->decrypted();
        $creds = LazadaSetting::activeCredentials($setting);
        if (!$setting || !$setting->region || !$creds['app_key'] || !$creds['app_secret'] || !$creds['access_token']) {
            return redirect()->to(url()->previous(route('ext.lazada.products.index')))
                ->with('status', 'Missing Lazada settings (region/app key/app secret/access token).');
        }

        $pfx = (string) config('catalog.prefix');

        $product = DB::table($pfx.'product as p')
            ->where('p.product_id', (int) $listing->product_id)
            ->first(['p.product_id', 'p.sku']);

        if (!$product) {
            $this->persistProductSyncStatus($listing, 'sync_lazada_id', ['ok' => false, 'body' => ['code' => 'ERP_PRODUCT_NOT_FOUND', 'message' => 'ERP product not found for this Lazada product mapping.']]);
            return redirect()->to(url()->previous(route('ext.lazada.products.index')))
                ->with('status', 'Sync Lazada ID: ERP product not found.');
        }

        // Collect all SKUs: option value SKUs first (Lazada stores variant SKUs), main SKU as fallback
        $mainSku = trim((string) ($product->sku ?? ''));

        $ovSkus = DB::table($pfx . 'product_option_value')
            ->where('product_id', (int) $listing->product_id)
            ->whereNotNull('sku')
            ->where('sku', '!=', '')
            ->pluck('sku')
            ->map(fn($s) => trim((string) $s))
            ->filter(fn($s) => $s !== '')
            ->unique()
            ->values()
            ->toArray();

        $allSkus = $ovSkus;
        if ($mainSku !== '' && !in_array($mainSku, $allSkus)) {
            $allSkus[] = $mainSku;
        }

        if (empty($allSkus)) {
            $this->persistProductSyncStatus($listing, 'sync_lazada_id', ['ok' => false, 'body' => ['code' => 'MISSING_SKU', 'message' => 'ERP product has no SKU (main or option values).']]);
            return redirect()->to(url()->previous(route('ext.lazada.products.index')))
                ->with('status', 'Sync Lazada ID: product has no SKU.');
        }

        // Search Lazada page by page, try each SKU until a match is found
        $matchedItemId = null;
        $matchCount = 0;
        foreach ($allSkus as $s) {
            [$found, $count] = $this->findLazadaItemBySku($setting, $client, $s);
            if ($found) {
                $matchedItemId = $found;
                $matchCount = $count;
                break;
            }
            $matchCount = max($matchCount, $count);
        }

        if ($matchCount > 1) {
            $this->persistProductSyncStatus($listing, 'sync_lazada_id', [
                'ok' => false,
                'body' => ['code' => 'MULTIPLE_MATCHES', 'message' => 'Multiple Lazada items matched. Please resolve duplicates in Lazada first.'],
            ]);
            return redirect()->to(url()->previous(route('ext.lazada.products.index')))
                ->with('status', 'Sync Lazada ID: multiple Lazada items matched.');
        }

        if ($matchedItemId) {
            try {
                $listing->lazada_item_id = (string) $matchedItemId;
                $listing->lazada_deleted_at = null;
                $listing->unlinked_at = null;
                // A fresh link voids the mirror; the refresh below reads the
                // item back and fills it with Lazada's answer (#184 C).
                $listing->live_status = null;
                $listing->live_checked_at = null;
                $listing->save();

                // Full cache refresh: variants, mirror, stale cleanup, sync_status
                $this->itemCache()->refreshListing($listing, $setting, $client);
            } catch (\Throwable $ex) {
                // keep status log; avoid throwing in production
            }

            return redirect()->to(url()->previous(route('ext.lazada.products.index')))
                ->with('status', 'Sync Lazada ID: linked successfully.');
        }

        $this->persistProductSyncStatus($listing, 'sync_lazada_id', ['ok' => false, 'body' => ['code' => 'NOT_FOUND', 'message' => 'No Lazada product found for SKUs: ' . implode(', ', $allSkus)]]);
        return redirect()->to(url()->previous(route('ext.lazada.products.index')))
            ->with('status', 'Sync Lazada ID: no Lazada product found for SKUs: ' . implode(', ', $allSkus));
    }

    public function uploadToLazada(Request $request, int $id, LazadaClient $client)
    {
        $listing = LazadaProduct::query()->findOrFail($id);

        $setting = LazadaSetting::query()->first()?->decrypted();
        $creds = LazadaSetting::activeCredentials($setting);
        if (!$setting || !$setting->region || !$creds['app_key'] || !$creds['app_secret'] || !$creds['access_token']) {
            return redirect()->to(url()->previous(route('ext.lazada.products.index')))
                ->with('status', 'Missing Lazada settings (region/app key/app secret/access token).');
        }


        // Determine create vs update
        $isUpdate = !empty($listing->lazada_item_id) && empty($listing->lazada_deleted_at);

        if (empty($listing->primary_category_id)) {
            $this->persistProductSyncStatus($listing, 'upload_to_lazada', ['ok' => false, 'body' => ['code' => 'MISSING_PRIMARY_CATEGORY', 'message' => 'Primary category is required before upload.']]);
            return redirect()->to(url()->previous(route('ext.lazada.products.index')))
                ->with('status', 'Upload: Primary Category is required before uploading.');
        }

        // Validate that the ERP product has a SKU (or model as fallback).
        $pfx = (string) config('catalog.prefix');
        $erpProduct = DB::table($pfx . 'product')->where('product_id', (int) $listing->product_id)->first(['sku', 'model', 'status']);

        if ((int) ($erpProduct->status ?? 1) === 0) {
            $this->persistProductSyncStatus($listing, 'upload_to_lazada', ['ok' => false, 'body' => ['code' => 'PRODUCT_DISABLED', 'message' => 'ERP product is disabled. Enable it before uploading.']]);
            return redirect()->to(url()->previous(route('ext.lazada.products.index')))
                ->with('status', 'Upload: ERP product is disabled. Enable it before uploading to Lazada.');
        }

        $erpSku = trim((string) ($erpProduct->sku ?? ''));
        if ($erpSku === '') {
            $erpSku = trim((string) ($erpProduct->model ?? ''));
        }
        if ($erpSku === '') {
            $this->persistProductSyncStatus($listing, 'upload_to_lazada', ['ok' => false, 'body' => ['code' => 'MISSING_SKU', 'message' => 'Product SKU is required. Please set a SKU (or Model) in the ERP product first.']]);
            return redirect()->to(url()->previous(route('ext.lazada.products.index')))
                ->with('status', 'Upload: Product SKU is required. Please set a SKU (or Model) in the core product first.');
        }

        // Build payload from listing mapping. If mapping is incomplete, we do not send an invalid payload.
        try {
            [$productPayload, $preview] = $this->buildLazadaProductCreatePayload($listing, $setting, $client);
            $productPayload = $this->ensureLazadaInlinkImages($productPayload, $setting, $client);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $msg = collect($e->errors())->flatten()->first() ?: 'Listing mapping is incomplete.';
            $this->persistProductSyncStatus($listing, 'upload_to_lazada', ['ok' => false, 'body' => ['code' => 'VALIDATION_ERROR', 'message' => (string)$msg]]);
            return redirect()->to(url()->previous(route('ext.lazada.products.index')))->with('status', 'Upload: ' . $msg);
        }

        // For updates, inject ItemId and SkuId so Lazada knows which product/SKUs to update
        if ($isUpdate) {
            $productPayload['Request']['Product']['ItemId'] = (int) $listing->lazada_item_id;

            // Inject SkuId into each SKU entry from cached variants
            $variantMap = LazadaProductVariant::where('lazada_product_id', $listing->id)
                ->whereNotNull('sku_id')
                ->pluck('sku_id', 'seller_sku')
                ->toArray();

            foreach ($productPayload['Request']['Product']['Skus']['Sku'] as $idx => $sku) {
                $sellerSku = $sku['SellerSku'] ?? '';
                if (isset($variantMap[$sellerSku])) {
                    $productPayload['Request']['Product']['Skus']['Sku'][$idx]['SkuId'] = (int) $variantMap[$sellerSku];
                }
            }
        }

        $apiPath = $isUpdate ? '/product/update' : '/product/create';
        $timestamp = (string) round(microtime(true) * 1000);
        $params = [
            'app_key' => (string) $creds['app_key'],
            'sign_method' => 'sha256',
            'timestamp' => $timestamp,
            'access_token' => (string) $creds['access_token'],
            'payload' => json_encode($productPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
        $params['sign'] = $client->sign($apiPath, $params, (string) $creds['app_secret']);

        $result = $client->post((string) $setting->region, $apiPath, $params);

        $this->persistProductSyncStatus($listing, 'upload_to_lazada', $result, 'listing');

        // If successful, store lazada_item_id (create) or refresh variants (update).
        $e = $this->extractLazadaError($result);
        if (!empty($e['ok'])) {
            if ($isUpdate) {
                // Refresh variant cache after update
                $this->itemCache()->refreshListing($listing, $setting, $client);
                return redirect()->to(url()->previous(route('ext.lazada.products.index')))
                    ->with('status', 'Product updated on Lazada successfully.');
            }

            $itemId = $this->extractCreatedItemId($result);
            try {
                if ($itemId) {
                    $listing->lazada_item_id = $itemId;
                }
                $listing->lazada_deleted_at = null;
                $listing->unlinked_at = null;
                // A fresh link voids the mirror: whatever status the row wore
                // belonged to the previous link. Read back below, never guessed.
                $listing->live_status = null;
                $listing->live_checked_at = null;
                $listing->save();
                // Persist Lazada sku_id and shop_sku (returned from /product/create) into variants table.
                // Also resolve product_option_value_id so push-stock can map SKU → ERP qty.
                $skuList = data_get($result, 'body.data.sku_list', []);
                if (is_array($skuList)) {
                    // Build SKU → product_option_value_id map from ERP option values.
                    $pfxPost = (string) config('catalog.prefix');
                    $erpPovRows = DB::table($pfxPost . 'product_option_value')
                        ->where('product_id', (int) $listing->product_id)
                        ->whereNotNull('sku')
                        ->where('sku', '!=', '')
                        ->get(['product_option_value_id', 'sku']);
                    $skuToPovId = [];
                    foreach ($erpPovRows as $epov) {
                        $skuToPovId[trim((string) $epov->sku)] = (int) $epov->product_option_value_id;
                    }

                    foreach ($skuList as $row) {
                        if (!is_array($row)) {
                            continue;
                        }
                        $sellerSku = $row['seller_sku'] ?? $row['SellerSku'] ?? null;
                        if (!$sellerSku) {
                            continue;
                        }
                        $shopSku = $row['shop_sku'] ?? null;
                        $skuId = $row['sku_id'] ?? null;

                        // Resolve product_option_value_id: check if variant row already has it,
                        // otherwise try matching seller_sku against ERP option value SKUs.
                        $existingVariant = \Extensions\lazada\Models\LazadaProductVariant::where('lazada_product_id', $listing->id)
                            ->where('seller_sku', (string) $sellerSku)
                            ->first();
                        $povId = $existingVariant->product_option_value_id ?? null;

                        if ($povId === null) {
                            // Direct match
                            $povId = $skuToPovId[(string) $sellerSku] ?? null;
                            // Suffixed match (e.g., "SKU-123" where 123 is pov_id)
                            if ($povId === null) {
                                foreach ($skuToPovId as $erpSku => $id) {
                                    if (str_starts_with((string) $sellerSku, $erpSku . '-')) {
                                        $povId = $id;
                                        break;
                                    }
                                }
                            }
                        }

                        try {
                            \Extensions\lazada\Models\LazadaProductVariant::updateOrCreate(
                                [
                                    'lazada_product_id' => $listing->id,
                                    'seller_sku' => (string) $sellerSku,
                                ],
                                [
                                    'product_option_value_id' => $povId,
                                    'shop_sku' => $shopSku ? (string) $shopSku : null,
                                    'sku_id' => $skuId !== null ? (int) $skuId : null,
                                ]
                            );
                        } catch (\Throwable $ex2) {
                            // ignore
                        }
                    }
                }

                // Write-through (#184 C): read the item straight back so the
                // mirror learns what this push did without waiting for the sweep.
                $this->itemCache()->refreshListing($listing, $setting, $client);
            } catch (\Throwable $ex) {
                // ignore
            }
        }

        LazadaApiLog::safeCreate([
            'pack' => 'lazada.product.create',
            'method' => 'POST',
            'api_path' => $apiPath,
            'auth_required' => true,
            'request_params' => $params,
            'response_status' => (int)($result['status'] ?? 0),
            'ok' => (bool)($result['ok'] ?? false),
            'response_body' => $result['body'] ?? $result,
            'user_id' => auth()->id(),
        ]);

        return redirect()->to(url()->previous(route('ext.lazada.products.index')))
            ->with('status', $this->formatLazadaResultMessage('Upload', $result));
    }

    /**
     * The seller center's Active switch (Lazada). Deactivate is the
     * documented /product/deactivate (ItemId mandatory). Lazada publishes
     * NO activate endpoint - reactivation goes through /product/update
     * with SKU-level Status 'active', which is how its own payload schema
     * expresses it; whatever the marketplace answers is reported verbatim.
     */
    /**
     * Push (or end) the listing's discount on Lazada (#146). The rule is
     * a percent off with a window; each SKU's SalePrice is computed HERE
     * from the same final price a price sync would send, so a catalog
     * price change moves the discount with it. Ending sends SalePrice 0,
     * which clears the special price on Lazada's side.
     */
    public function pushDiscount(Request $request, int $id, LazadaClient $client)
    {
        $request->validate(['action' => 'required|in:push,end']);
        $ending = $request->input('action') === 'end';

        $listing = LazadaProduct::query()->findOrFail($id);
        if (empty($listing->lazada_item_id)) {
            return redirect()->back()->with('error', 'This listing is not on Lazada, so there is no price to discount.');
        }
        if (!$ending) {
            if ($listing->sale_percent === null || !$listing->sale_starts_at || !$listing->sale_ends_at) {
                return redirect()->back()->with('error', 'Set the discount first: a percent off, a start and an end. Save, then push it.');
            }
            if ($listing->sale_ends_at->isPast()) {
                return redirect()->back()->with('error', 'This discount\'s window has already ended. Set new dates and save first.');
            }
        }

        $setting = LazadaSetting::query()->first()?->decrypted();
        $creds = LazadaSetting::activeCredentials($setting);
        if (!$setting || !$creds['complete']) {
            return redirect()->back()->with('error', 'Missing Lazada settings.');
        }

        $pfx = (string) config('catalog.prefix');
        $product = DB::table($pfx.'product as p')
            ->where('p.product_id', (int) $listing->product_id)
            ->first(['p.product_id', 'p.sku', 'p.price']);
        if (!$product) {
            return redirect()->back()->with('error', 'The catalog product behind this listing is gone.');
        }
        $basePrice = max(0.0, (float) ($product->price ?? 0));

        $fixedMarkup = $listing->markup_fixed;
        $percentMarkup = $listing->markup_percent;
        if ($fixedMarkup === null && $percentMarkup === null) {
            $mkGroup = $listing->groups()->first();
            if ($mkGroup) {
                $fixedMarkup = $mkGroup->markup_fixed;
                $percentMarkup = $mkGroup->markup_percent;
            }
        }

        $skuIdMap = $this->itemCache()->resolveSkuIds($listing, $setting, $client);
        if (empty($skuIdMap)) {
            return redirect()->back()->with('error', 'Could not resolve this listing\'s Lazada SKU ids. Open the listing to refresh its variants, then try again.');
        }

        $variantPrices = $this->getErpVariantPricesByProductId((int) $listing->product_id, $basePrice);
        if (empty($variantPrices)) {
            $sku = trim((string) ($product->sku ?? ''));
            if ($sku === '') {
                return redirect()->back()->with('error', 'The catalog product has no SKU.');
            }
            $variantPrices = [['seller_sku' => $sku, 'price' => $basePrice]];
        }

        $saleStart = $listing->sale_starts_at?->format('Y-m-d');
        $saleEnd = $listing->sale_ends_at?->format('Y-m-d');
        $pct = (float) ($listing->sale_percent ?? 0);

        $apiPath = '/product/price_quantity/update';
        $ok = 0;
        $err = 0;
        $lastMessage = null;

        foreach ($variantPrices as $v) {
            $sku = trim((string) ($v['seller_sku'] ?? ''));
            $skuId = $sku !== '' ? ($skuIdMap[$sku] ?? null) : null;
            if ($skuId === null) {
                $err++;
                continue;
            }

            $price = self::computeFinalPrice((float) ($v['price'] ?? 0), $fixedMarkup, $percentMarkup);
            $priceStr = number_format(max(0, $price), 2, '.', '');
            $saleStr = $ending
                ? '0'
                : number_format(max(0.01, round($price * (1 - $pct / 100), 2)), 2, '.', '');

            $payloadXml = $this->buildPriceUpdateXml(
                (int) $skuId,
                $priceStr,
                $saleStr,
                $ending ? null : $saleStart,
                $ending ? null : $saleEnd
            );

            $timestamp = (string) round(microtime(true) * 1000);
            $params = [
                'app_key' => (string) $creds['app_key'],
                'sign_method' => 'sha256',
                'timestamp' => $timestamp,
                'access_token' => (string) $creds['access_token'],
                'payload' => $payloadXml,
            ];
            $params['sign'] = $client->sign($apiPath, $params, (string) $creds['app_secret']);

            try {
                $result = $client->post((string) $setting->region, $apiPath, $params);
            } catch (\Throwable $e) {
                $err++;
                $lastMessage = \App\Support\TransportError::plain($e, 'Lazada');
                continue;
            }

            LazadaApiLog::safeCreate([
                'pack' => 'lazada.listings.discount', 'method' => 'POST',
                'api_path' => $apiPath, 'auth_required' => true,
                'request_params' => $params,
                'response_status' => (int) ($result['status'] ?? 0),
                'ok' => (bool) ($result['ok'] ?? false),
                'response_body' => $result['body'] ?? $result,
                'user_id' => auth()->id(),
            ]);

            $e = $this->extractLazadaError($result);
            if ($e['ok']) {
                $ok++;
            } else {
                $err++;
                $lastMessage = $e['message'] ?? 'Unknown error';
            }
        }

        if ($ending && $err === 0) {
            $listing->forceFill(['sale_percent' => null, 'sale_starts_at' => null, 'sale_ends_at' => null, 'sale_pushed_at' => null])->save();
        } elseif (!$ending && $err === 0) {
            // Lazada reports no promotion id back; the stamp is how the
            // record knows its rule reached the marketplace at all.
            $listing->forceFill(['sale_pushed_at' => now()])->save();
        }

        if ($err > 0) {
            return redirect()->back()->with('error',
                ($ending ? 'End discount' : 'Discount push') . ": {$ok} SKU(s) ok, {$err} failed"
                . ($lastMessage ? ' - ' . $lastMessage : '') . '.');
        }

        return redirect()->back()->with('status', $ending
            ? 'Discount ended. Lazada sells at the full price again.'
            : "Discount pushed: {$ok} SKU(s) at {$pct}% off from {$saleStart} to {$saleEnd}.");
    }

    public function toggleListing(Request $request, int $id, LazadaClient $client)
    {
        $request->validate(['action' => 'required|in:activate,deactivate']);
        $deactivate = $request->input('action') === 'deactivate';
        $listing = LazadaProduct::query()->findOrFail($id);
        if (empty($listing->lazada_item_id)) {
            return redirect()->back()->with('error', 'This listing is not on Lazada, so there is nothing to ' . ($deactivate ? 'deactivate' : 'activate') . '.');
        }
        $setting = LazadaSetting::query()->first()?->decrypted();
        $creds = LazadaSetting::activeCredentials($setting);
        if (!$setting || !$creds['complete']) {
            return redirect()->back()->with('error', 'Missing Lazada settings.');
        }

        $r = $this->toggleOne($listing, $deactivate, $setting, $creds, $client);
        if (!$r['ok']) {
            return redirect()->back()->with('error', ($deactivate ? 'Deactivate' : 'Activate') . ' failed: ' . $r['message']);
        }

        return redirect()->back()->with('status', $deactivate
            ? 'Deactivated. Buyers no longer see this item on Lazada.'
            : 'Activation sent. Lazada usually applies it within a minute; Refresh from Lazada reads the truth back.');
    }

    /**
     * The live listing as Lazada holds it, in the shape /product/update
     * wants it back: its attributes verbatim, and its SKUs reduced to the
     * writable fields.
     *
     * Only writable fields are carried. Lazada returns read-only ones on
     * item/get (Url, ShopSku, and friends) that /product/update rejects,
     * and price and quantity are carried precisely so that a status change
     * cannot blank them if Lazada treats the Sku node as a replacement
     * rather than a patch.
     *
     * @return array{ok: bool, attributes?: array<string, mixed>, skus?: list<array<string, mixed>>, message?: string}
     */
    private function fetchLazadaItemForUpdate(LazadaClient $client, object $setting, array $creds, string $itemId): array
    {
        if (trim($itemId) === '') {
            return ['ok' => false, 'message' => 'This listing has no Lazada item id.'];
        }

        $apiPath = '/product/item/get';
        $params = [
            'app_key' => (string) $creds['app_key'],
            'sign_method' => 'sha256',
            'timestamp' => (string) round(microtime(true) * 1000),
            'access_token' => (string) $creds['access_token'],
            'item_id' => $itemId,
        ];
        $params['sign'] = $client->sign($apiPath, $params, (string) $creds['app_secret']);

        try {
            $res = $client->get((string) $setting->region, $apiPath, $params);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => \App\Support\TransportError::plain($e, 'Lazada')];
        }

        LazadaApiLog::safeCreate([
            'pack' => 'lazada.listings.toggle.read', 'method' => 'GET',
            'api_path' => $apiPath, 'auth_required' => true,
            'request_params' => $params,
            'response_status' => (int) ($res['status'] ?? 0),
            'ok' => (bool) ($res['ok'] ?? false),
            'response_body' => $res['body'] ?? null, 'user_id' => auth()->id(),
        ]);

        $e = $this->extractLazadaError($res);
        if (! ($e['ok'] ?? false)) {
            return ['ok' => false, 'message' => (string) ($e['message'] ?? 'Lazada refused the read.')];
        }

        $body = $res['body'] ?? [];
        $data = is_array($body) ? ($body['data'] ?? $body) : [];

        // item/get answers with lower-case keys; /product/update wants the
        // same machine names it was given, which is what these already are.
        $attributes = data_get($data, 'attributes') ?? data_get($data, 'Attributes') ?? [];
        $attributes = is_array($attributes) ? array_filter($attributes, fn ($v) => $v !== null && $v !== '') : [];

        $rawSkus = data_get($data, 'skus') ?? data_get($data, 'Skus.Sku') ?? [];
        $skus = [];
        foreach ((array) $rawSkus as $sku) {
            if (! is_array($sku)) {
                continue;
            }
            $skuId = $sku['SkuId'] ?? $sku['sku_id'] ?? null;
            if ($skuId === null || $skuId === '') {
                continue;
            }

            $row = ['SkuId' => (int) $skuId, 'Status' => 'active'];
            foreach ([
                'SellerSku' => ['SellerSku', 'SellerSKU', 'seller_sku'],
                'Quantity'  => ['quantity', 'Quantity'],
                'price'     => ['price', 'Price'],
            ] as $out => $candidates) {
                foreach ($candidates as $key) {
                    if (isset($sku[$key]) && $sku[$key] !== '' && $sku[$key] !== null) {
                        $row[$out] = $sku[$key];
                        break;
                    }
                }
            }

            $skus[] = $row;
        }

        return ['ok' => true, 'attributes' => $attributes, 'skus' => $skus];
    }

    /**
     * One activate or deactivate, the same call for the row menu, the
     * listing page and the selection bar. Lazada deactivates by ItemId;
     * activating goes through /product/update with the SKU ids the ERP has
     * cached, SKU-level Status active. On success the mirror is written
     * through so the next page view reads it without a refresh.
     *
     * @return array{ok: bool, message: string}
     */
    private function toggleOne(LazadaProduct $listing, bool $deactivate, object $setting, array $creds, LazadaClient $client): array
    {
        if ($deactivate) {
            $apiPath = '/product/deactivate';
            $productPayload = ['Request' => ['Product' => ['ItemId' => (int) $listing->lazada_item_id]]];
        } else {
            /* Reactivation goes through /product/update, which is NOT a patch
             * endpoint: Lazada validates the Product node you send against the
             * category schema instead of merging it into the stored listing,
             * so a Product without Attributes is refused with
             *
             *     E001: Parameter Attributes is mandatory
             *
             * even though the listing is already live and already has them
             * (the owner, live, 2026-09-01). The attributes therefore have to
             * travel with the call.
             *
             * They are read back from Lazada rather than rebuilt from the
             * ERP's own answers, for two reasons. The operator pressed
             * Activate, not Push changes: sending ERP content here would
             * overwrite the live name, description and dimensions as a side
             * effect of flipping a status. And the ERP's sheet may be
             * half-answered (see #175) on a listing Lazada is perfectly happy
             * with, which would make a live product impossible to switch back
             * on. Round-tripping the marketplace's own values changes exactly
             * one thing: Status.
             */
            $live = $this->fetchLazadaItemForUpdate($client, $setting, $creds, (string) $listing->lazada_item_id);
            if (! ($live['ok'] ?? false)) {
                return ['ok' => false, 'message' => (string) ($live['message'] ?? 'Could not read the listing from Lazada.')];
            }
            if (empty($live['attributes'])) {
                return ['ok' => false, 'message' => 'Lazada returned no attributes for this item, so activating it would send an incomplete product. Open the listing on Lazada and check it still exists.'];
            }
            if (empty($live['skus'])) {
                return ['ok' => false, 'message' => 'Lazada returned no SKUs for this item, so there is nothing to activate.'];
            }

            $apiPath = '/product/update';
            $productPayload = ['Request' => ['Product' => [
                'ItemId' => (int) $listing->lazada_item_id,
                'Attributes' => $live['attributes'],
                'Skus' => ['Sku' => $live['skus']],
            ]]];
        }
        $timestamp = (string) round(microtime(true) * 1000);
        $params = [
            'app_key' => (string) $creds['app_key'],
            'sign_method' => 'sha256',
            'timestamp' => $timestamp,
            'access_token' => (string) $creds['access_token'],
            'payload' => json_encode($productPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
        $params['sign'] = $client->sign($apiPath, $params, (string) $creds['app_secret']);
        try {
            $result = $client->post((string) $setting->region, $apiPath, $params);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => \App\Support\TransportError::plain($e, 'Lazada')];
        }
        LazadaApiLog::safeCreate([
            'pack' => 'lazada.listings.toggle', 'method' => 'POST',
            'api_path' => $apiPath, 'auth_required' => true,
            'request_params' => $params,
            'response_status' => (int) ($result['status'] ?? 0),
            'ok' => (bool) ($result['ok'] ?? false),
            'response_body' => $result['body'] ?? null, 'user_id' => auth()->id(),
        ]);
        $e = $this->extractLazadaError($result);
        if (!($e['ok'] ?? false)) {
            return ['ok' => false, 'message' => (string) ($e['message'] ?? 'Unknown error')];
        }

        $listing->forceFill(['live_status' => $deactivate ? 'inactive' : 'active', 'live_checked_at' => now()])->save();
        ActivityLogger::log(
            $deactivate ? 'deactivated' : 'activated',
            'Lazada Product',
            (int) $listing->id,
            'Item ' . $listing->lazada_item_id . ($deactivate ? ' deactivated on' : ' activated on') . ' Lazada'
        );

        return ['ok' => true, 'message' => ''];
    }

    /** The selection bar's Deactivate / Activate (the owner, 2026-08-28): the same call as the row's, for many rows. */
    public function bulkToggle(Request $request, LazadaClient $client)
    {
        $request->validate([
            'action' => 'required|in:activate,deactivate',
            'listing_ids' => 'required|array|min:1',
            'listing_ids.*' => 'integer',
        ]);
        $deactivate = $request->input('action') === 'deactivate';
        $verb = $deactivate ? 'Deactivated' : 'Activated';

        $setting = LazadaSetting::query()->first()?->decrypted();
        $creds = LazadaSetting::activeCredentials($setting);
        if (!$setting || !$creds['complete']) {
            return redirect()->back()->with('error', 'Missing Lazada settings.');
        }

        $ids = array_values(array_unique(array_map('intval', (array) $request->input('listing_ids'))));
        $listings = LazadaProduct::query()->whereIn('id', $ids)->get();
        $onLazada = $listings->filter(fn ($l) => !empty($l->lazada_item_id));
        $notOnLazada = count($ids) - $onLazada->count();
        if ($onLazada->isEmpty()) {
            return redirect()->back()->with('error', 'None of the selected listings is on Lazada, so there is nothing to ' . ($deactivate ? 'deactivate' : 'activate') . '.');
        }

        $done = 0;
        $refused = [];
        foreach ($onLazada as $listing) {
            $r = $this->toggleOne($listing, $deactivate, $setting, $creds, $client);
            if ($r['ok']) {
                $done++;
            } else {
                $refused[] = $r['message'];
            }
        }

        $parts = [];
        if ($done > 0) {
            $parts[] = $verb . ' ' . $done . ' ' . ($done === 1 ? 'item' : 'items') . ' on Lazada.';
        }
        if ($refused !== []) {
            $parts[] = count($refused) . ' refused: ' . implode('; ', array_slice(array_unique($refused), 0, 3)) . '.';
        }
        if ($notOnLazada > 0) {
            $parts[] = $notOnLazada . ' skipped, not on Lazada.';
        }

        return redirect()->back()->with($done === 0 ? 'error' : 'status', implode(' ', $parts));
    }

    public function bulkUploadToLazada(Request $request, LazadaClient $client)
    {
        $ids = $request->input('listing_ids', []);
        if (!is_array($ids)) {
            $ids = [];
        }
        $ids = array_values(array_unique(array_filter(array_map(fn($v) => (int)$v, $ids), fn($v) => $v > 0)));

        if (empty($ids)) {
            return redirect()->to(url()->previous(route('ext.lazada.products.index')))->with('status', 'Bulk Upload: no products selected.');
        }

        $setting = LazadaSetting::query()->first()?->decrypted();
        $creds = LazadaSetting::activeCredentials($setting);
        if (!$setting || !$setting->region || !$creds['app_key'] || !$creds['app_secret'] || !$creds['access_token']) {
            return redirect()->to(url()->previous(route('ext.lazada.products.index')))
                ->with('status', 'Missing Lazada settings (region/app key/app secret/access token).');
        }

        $listings = LazadaProduct::query()->whereIn('id', $ids)->get();

        /* REFUSE TO START rather than half-fail (#101, Lazada half): if any
         * selected listing is not ready, nothing is uploaded and the
         * unready are named with what they need. Already-uploaded rows are
         * exempt - re-uploads update in place. */
        $toCheck = $listings->filter(fn ($l) => empty($l->lazada_item_id));
        $readiness = app(\Extensions\lazada\Services\Lazada\LazadaListingReadiness::class)
            ->forListings($toCheck);
        $unready = array_filter($readiness, fn ($r) => !$r['ready']);
        if (!empty($unready)) {
            $lines = [];
            foreach (array_slice($unready, 0, 5, true) as $lid => $r) {
                $pid = (int) ($listings->firstWhere('id', $lid)?->product_id ?? 0);
                $lines[] = "#{$pid} needs " . implode(', ', $r['missing']);
            }
            $summary = 'Nothing was uploaded. ' . count($unready) . ' of ' . count($ids)
                . ' selected ' . (count($unready) === 1 ? 'is' : 'are') . ' not ready: '
                . implode('; ', $lines)
                . (count($unready) > 5 ? '; and ' . (count($unready) - 5) . ' more' : '')
                . '. Untick them, or finish their listings first.';

            return redirect()->to(url()->previous(route('ext.lazada.products.index')))->with('error', $summary);
        }

        $okCount = 0;
        $updatedCount = 0;
        $errCount = 0;

        $pfx = (string) config('catalog.prefix');

        foreach ($listings as $listing) {
            if (empty($listing->primary_category_id)) {
                $this->persistProductSyncStatus($listing, 'bulk_upload_to_lazada', ['ok' => false, 'body' => ['code' => 'MISSING_PRIMARY_CATEGORY', 'message' => 'Primary category is required before upload.']]);
                $errCount++;
                continue;
            }

            // Validate SKU
            $erpProduct = DB::table($pfx . 'product')->where('product_id', (int) $listing->product_id)->first(['sku', 'model', 'status']);

            if ((int) ($erpProduct->status ?? 1) === 0) {
                $this->persistProductSyncStatus($listing, 'bulk_upload_to_lazada', ['ok' => false, 'body' => ['code' => 'PRODUCT_DISABLED', 'message' => 'ERP product is disabled (status=0).']]);
                $errCount++;
                continue;
            }

            $erpSku = trim((string) ($erpProduct->sku ?? ''));
            if ($erpSku === '') {
                $erpSku = trim((string) ($erpProduct->model ?? ''));
            }
            if ($erpSku === '') {
                $this->persistProductSyncStatus($listing, 'bulk_upload_to_lazada', ['ok' => false, 'body' => ['code' => 'MISSING_SKU', 'message' => 'Product SKU is required.']]);
                $errCount++;
                continue;
            }

            try {
                [$productPayload, $preview] = $this->buildLazadaProductCreatePayload($listing, $setting, $client);
                $productPayload = $this->ensureLazadaInlinkImages($productPayload, $setting, $client);
            } catch (\Illuminate\Validation\ValidationException $e) {
                $msg = collect($e->errors())->flatten()->first() ?: 'Listing mapping is incomplete.';
                $this->persistProductSyncStatus($listing, 'bulk_upload_to_lazada', ['ok' => false, 'body' => ['code' => 'VALIDATION_ERROR', 'message' => (string)$msg]]);
                $errCount++;
                continue;
            }

            /* SEND SEMANTICS (parity #156, item 4): a listing already on
             * Lazada is updated in place - ItemId and each cached SkuId
             * injected so Lazada edits rather than duplicates - instead of
             * being created again. */
            $isUpdate = !empty($listing->lazada_item_id) && empty($listing->lazada_deleted_at);
            if ($isUpdate) {
                $productPayload['Request']['Product']['ItemId'] = (int) $listing->lazada_item_id;
                $variantMap = LazadaProductVariant::where('lazada_product_id', $listing->id)
                    ->whereNotNull('sku_id')->pluck('sku_id', 'seller_sku')->toArray();
                foreach ($productPayload['Request']['Product']['Skus']['Sku'] as $idx => $sku) {
                    $sellerSku = $sku['SellerSku'] ?? '';
                    if (isset($variantMap[$sellerSku])) {
                        $productPayload['Request']['Product']['Skus']['Sku'][$idx]['SkuId'] = (int) $variantMap[$sellerSku];
                    }
                }
            }
            $apiPath = $isUpdate ? '/product/update' : '/product/create';
            $timestamp = (string) round(microtime(true) * 1000);
            $params = [
                'app_key' => (string) $creds['app_key'],
                'sign_method' => 'sha256',
                'timestamp' => $timestamp,
                'access_token' => (string) $creds['access_token'],
                'payload' => json_encode($productPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ];
            $params['sign'] = $client->sign($apiPath, $params, (string) $creds['app_secret']);

            $result = $client->post((string) $setting->region, $apiPath, $params);

            $this->persistProductSyncStatus($listing, $isUpdate ? 'bulk_update_on_lazada' : 'bulk_upload_to_lazada', $result, 'listing');

            $e = $this->extractLazadaError($result);
            if (!empty($e['ok'])) {
                $isUpdate ? $updatedCount++ : $okCount++;
                $itemId = $isUpdate ? null : $this->extractCreatedItemId($result);
                try {
                    if ($itemId) {
                        $listing->lazada_item_id = $itemId;
                        // A fresh link voids the mirror; the read-back below
                        // fills it with Lazada's answer, never a guess.
                        $listing->live_status = null;
                        $listing->live_checked_at = null;
                    }
                    $listing->lazada_deleted_at = null;
                    $listing->save();
                    // Write-through (#184 C): the mirror learns what this push
                    // did without waiting for the 30-minute sweep.
                    $this->itemCache()->refreshListing($listing, $setting, $client);
                } catch (\Throwable $ex) {
                    // ignore
                }
            } else {
                $errCount++;
            }

            LazadaApiLog::safeCreate([
                'pack' => $isUpdate ? 'lazada.product.update.bulk' : 'lazada.product.create.bulk',
                'method' => 'POST',
                'api_path' => $apiPath,
                'auth_required' => true,
                'request_params' => $params,
                'response_status' => (int)($result['status'] ?? 0),
                'ok' => (bool)($result['ok'] ?? false),
                'response_body' => $result['body'] ?? $result,
                'user_id' => auth()->id(),
            ]);
        }

        return redirect()->to(url()->previous(route('ext.lazada.products.index')))
            ->with($errCount > 0 && ($okCount + $updatedCount) === 0 ? 'error' : 'status',
                "Send: {$okCount} created, {$updatedCount} already listed and updated in place" . ($errCount > 0 ? ", {$errCount} failed" : '') . '.');
    }

    public function bulkSyncLazadaId(Request $request, LazadaClient $client)
    {
        $ids = $request->input('listing_ids', []);
        if (!is_array($ids)) {
            $ids = [];
        }
        $ids = array_values(array_unique(array_filter(array_map(fn($v) => (int)$v, $ids), fn($v) => $v > 0)));

        if (empty($ids)) {
            return redirect()->to(url()->previous(route('ext.lazada.products.index')))->with('status', 'Bulk Sync Lazada ID: no products selected.');
        }

        $setting = LazadaSetting::query()->first()?->decrypted();
        $creds = LazadaSetting::activeCredentials($setting);
        if (!$setting || !$setting->region || !$creds['app_key'] || !$creds['app_secret'] || !$creds['access_token']) {
            return redirect()->to(url()->previous(route('ext.lazada.products.index')))
                ->with('status', 'Missing Lazada settings (region/app key/app secret/access token).');
        }

        $listings = LazadaProduct::query()->whereIn('id', $ids)->get();
        $pfx = (string) config('catalog.prefix');
        $okCount = 0;
        $errCount = 0;
        $skipCount = 0;

        // Count how many actually need syncing
        $needSync = $listings->filter(fn($l) => empty($l->lazada_item_id))->count();

        // For any multi-product batch, fetch the entire Lazada catalog ONCE and
        // match every selected product against that single map. The per-product
        // path (below) falls back to a full catalog scan whenever Lazada's
        // `search` misses a SellerSku — which is common — so N products meant N
        // scans and a request that could stall for minutes. One shared pass
        // removes the multiply. A lone product keeps the per-product path (a
        // hit via `search`/`sku_seller_list` is a single fast call).
        $skuToItemId = null;
        if ($needSync >= 2) {
            $map = $this->fetchLazadaSkuMap($setting, $client);
            // Index case-insensitively: Lazada SellerSku casing may differ from
            // the ERP SKU, and the per-product matcher already uses strcasecmp.
            $skuToItemId = [];
            foreach ((array) $map as $sellerSku => $itemIds) {
                $skuToItemId[strtolower((string) $sellerSku)] = $itemIds;
            }
        }

        foreach ($listings as $listing) {
            // Skip if already linked
            if (!empty($listing->lazada_item_id)) {
                $skipCount++;
                continue;
            }

            // Collect all SKUs for this product: main SKU + option value SKUs
            $product = DB::table($pfx . 'product')->where('product_id', (int) $listing->product_id)->first(['sku']);
            $mainSku = trim((string) ($product->sku ?? ''));

            // Option value SKUs first (Lazada stores variant SKUs), main SKU as fallback
            $ovSkus = DB::table($pfx . 'product_option_value')
                ->where('product_id', (int) $listing->product_id)
                ->whereNotNull('sku')
                ->where('sku', '!=', '')
                ->pluck('sku')
                ->map(fn($s) => trim((string) $s))
                ->filter(fn($s) => $s !== '')
                ->unique()
                ->values()
                ->toArray();

            $allSkus = $ovSkus;
            if ($mainSku !== '' && !in_array($mainSku, $allSkus)) {
                $allSkus[] = $mainSku;
            }

            if (empty($allSkus)) {
                $this->persistProductSyncStatus($listing, 'bulk_sync_lazada_id', ['ok' => false, 'body' => ['code' => 'MISSING_SKU', 'message' => 'ERP product has no SKU (main or option values).']]);
                $errCount++;
                continue;
            }

            // Find matching Lazada item_id
            $matchedItemId = null;
            $matchCount = 0;

            if ($skuToItemId !== null) {
                // Bulk mode: look up from pre-fetched map
                $matchedItemIds = [];
                foreach ($allSkus as $s) {
                    foreach ($skuToItemId[strtolower((string) $s)] ?? [] as $itemId) {
                        $matchedItemIds[$itemId] = true;
                    }
                }
                $matchCount = count($matchedItemIds);
                if ($matchCount === 1) {
                    $matchedItemId = array_key_first($matchedItemIds);
                }
            } else {
                // Small batch: search per product, stops on first match
                foreach ($allSkus as $s) {
                    [$found, $count] = $this->findLazadaItemBySku($setting, $client, $s);
                    if ($found) {
                        $matchedItemId = $found;
                        $matchCount = $count;
                        break;
                    }
                    $matchCount = max($matchCount, $count);
                }
            }

            if ($matchCount > 1) {
                $this->persistProductSyncStatus($listing, 'bulk_sync_lazada_id', [
                    'ok' => false,
                    'body' => ['code' => 'MULTIPLE_MATCHES', 'message' => 'Multiple Lazada items matched SKUs for this product.'],
                ]);
                $errCount++;
                continue;
            }

            if ($matchedItemId) {
                try {
                    $listing->lazada_item_id = (string) $matchedItemId;
                    $listing->lazada_deleted_at = null;
                    // Re-linking clears an earlier unlink: a row that still
                    // carried unlinked_at read as both linked (has item id) and
                    // unlinked, and the row menu — which shows the push actions
                    // only when linked AND not unlinked — collapsed to just Edit
                    // and Remove. Matching an id is the act of linking it.
                    $listing->unlinked_at = null;
                    // A fresh link voids the mirror: the row reads "Not checked
                    // yet" until the page filler or sweep asks Lazada, never a
                    // stale status from a previous link (#184 C).
                    $listing->live_status = null;
                    $listing->live_checked_at = null;
                    // Record the success so the row reads as linked: without this
                    // the item id was saved but last_sync_ok / error_message kept
                    // whatever a prior failed run left, so a freshly matched
                    // listing still showed "No Lazada product found for SKU".
                    // ['ok' => true] with no error body clears the stale code and
                    // message and stamps last_synced_at + last_sync_action.
                    $this->persistProductSyncStatus($listing, 'bulk_sync_lazada_id', ['ok' => true]);
                    $okCount++;
                } catch (\Throwable $ex) {
                    $errCount++;
                }
            } else {
                $this->persistProductSyncStatus($listing, 'bulk_sync_lazada_id', ['ok' => false, 'body' => ['code' => 'NOT_FOUND', 'message' => 'No Lazada product found for SKUs: ' . implode(', ', $allSkus) . '.']]);
                $errCount++;
            }
        }

        $msg = "Bulk Sync Lazada ID: processed " . count($ids) . " product(s). Linked: {$okCount}, Error: {$errCount}";
        if ($skipCount > 0) {
            $msg .= ", Skipped (already linked): {$skipCount}";
        }
        $msg .= ".";

        return redirect()->to(url()->previous(route('ext.lazada.products.index')))->with('status', $msg);
    }

public function bulkSyncQuantity(Request $request, LazadaClient $client)
{
    $ids = $request->input('listing_ids', []);
    if (!is_array($ids)) {
        $ids = [];
    }
    $ids = array_values(array_unique(array_filter(array_map(fn($v) => (int)$v, $ids), fn($v) => $v > 0)));

    if (empty($ids)) {
        return redirect()->to(url()->previous(route('ext.lazada.products.index')))->with('status', 'Bulk Sync Qty: no listings selected.');
    }

    $setting = LazadaSetting::query()->first()?->decrypted();
    $creds = LazadaSetting::activeCredentials($setting);
    if (!$setting || !$setting->region || !$creds['app_key'] || !$creds['app_secret'] || !$creds['access_token']) {
        return redirect()->to(url()->previous(route('ext.lazada.products.index')))
            ->with('status', 'Missing Lazada settings (region/app key/app secret/access token).');
    }

    $pfx = (string) config('catalog.prefix');

    $listings = LazadaProduct::query()->whereIn('id', $ids)->get();
    $productIds = $listings->pluck('product_id')->filter()->unique()->values()->all();

    $products = collect();
    if (!empty($productIds)) {
        $products = DB::table($pfx.'product as p')
            ->whereIn('p.product_id', $productIds)
            ->get(['p.product_id', 'p.sku', 'p.quantity', 'p.status'])
            ->keyBy('product_id');
    }

    // Preload ERP option value data keyed by product_option_value_id and by product_id.
    $erpOptionQty = collect();
    $erpOptionsByProduct = collect();
    if (!empty($productIds)) {
        $erpOvRows = DB::table($pfx.'product_option_value as pov')
            ->whereIn('pov.product_id', $productIds)
            ->get(['pov.product_id', 'pov.product_option_value_id', 'pov.sku', 'pov.quantity']);
        $erpOptionQty = $erpOvRows->keyBy('product_option_value_id');
        $erpOptionsByProduct = $erpOvRows->groupBy(fn($r) => (int) $r->product_id);
    }

    $okCount = 0;
    $errCount = 0;

    foreach ($listings as $listing) {
        $product = $products->get((int)$listing->product_id);
        if (!$product) {
            $this->persistProductSyncStatus($listing, 'bulk_sync_quantity', ['ok' => false, 'body' => ['code' => 'ERP_PRODUCT_NOT_FOUND', 'message' => 'ERP product not found']]);
            $errCount++;
            continue;
        }

        if ((int) $product->status === 0) {
            $this->persistProductSyncStatus($listing, 'bulk_sync_quantity', ['ok' => false, 'body' => ['code' => 'PRODUCT_DISABLED', 'message' => 'ERP product is disabled (status=0).']]);
            $errCount++;
            continue;
        }

        if (empty($listing->lazada_item_id)) {
            $this->persistProductSyncStatus($listing, 'bulk_sync_quantity', ['ok' => false, 'body' => ['code' => 'NO_ITEM_ID', 'message' => 'No Lazada Item ID. Sync Lazada ID first.']]);
            $errCount++;
            continue;
        }

        // Resolve Lazada SkuIds (cached or fetched from API)
        $skuIdMap = $this->itemCache()->resolveSkuIds($listing, $setting, $client);
        if (empty($skuIdMap)) {
            $this->persistProductSyncStatus($listing, 'bulk_sync_quantity', ['ok' => false, 'body' => ['code' => 'NO_SKU_IDS', 'message' => 'Could not resolve Lazada SkuIds']]);
            $errCount++;
            continue;
        }

        $apiPath = '/product/price_quantity/update';
        $pid = (int) ($product->product_id ?? 0);

        // Build {seller_sku, quantity} list from ERP data, then match to Lazada SkuId.
        $erpOvs = $erpOptionsByProduct->get($pid);
        $variantSkus = [];

        if ($erpOvs && count($erpOvs) > 0) {
            foreach ($erpOvs as $ov) {
                $sku = trim((string) ($ov->sku ?? ''));
                if ($sku === '') continue;
                $variantSkus[] = ['seller_sku' => $sku, 'quantity' => max(0, (int) ($ov->quantity ?? 0))];
            }
        }

        // Fallback: main product SKU
        if (empty($variantSkus)) {
            $sku = trim((string) ($product->sku ?? ''));
            if ($sku !== '') {
                $variantSkus[] = ['seller_sku' => $sku, 'quantity' => max(0, (int) ($product->quantity ?? 0))];
            }
        }

        if (empty($variantSkus)) {
            $this->persistProductSyncStatus($listing, 'bulk_sync_quantity', ['ok' => false, 'body' => ['code' => 'EMPTY_SKU', 'message' => 'No SKU found']]);
            $errCount++;
            continue;
        }

        $ok = 0;
        $err = 0;
        $last = null;
        foreach ($variantSkus as $v) {
            $sku = trim((string)($v['seller_sku'] ?? ''));
            if ($sku === '') continue;
            $skuId = $skuIdMap[$sku] ?? null;
            if ($skuId === null) {
                $err++;
                continue;
            }
            $qty = max(0, (int)($v['quantity'] ?? 0));
            $payloadXml = $this->buildQuantityUpdateXml($skuId, $qty);

            $timestamp = (string) round(microtime(true) * 1000);
            $params = [
                'app_key' => (string) $creds['app_key'],
                'sign_method' => 'sha256',
                'timestamp' => $timestamp,
                'access_token' => (string) $creds['access_token'],
                'payload' => $payloadXml,
            ];
            $params['sign'] = $client->sign($apiPath, $params, (string) $creds['app_secret']);

            $result = $client->post((string) $setting->region, $apiPath, $params);
            $last = $result;

            LazadaApiLog::safeCreate([
                'pack' => 'lazada.product.price_quantity.update.bulk.variant',
                'method' => 'POST',
                'api_path' => $apiPath,
                'auth_required' => true,
                'request_params' => $params,
                'response_status' => (int)($result['status'] ?? 0),
                'ok' => (bool)($result['ok'] ?? false),
                'response_body' => $result['body'] ?? $result,
                'user_id' => auth()->id(),
            ]);

            $e = $this->extractLazadaError($result);
            if ($e['ok']) {
                $ok++;
            } else {
                $err++;
            }
        }

        $summary = [
            'ok' => $err === 0,
            'status' => (int)($last['status'] ?? 200),
            'body' => [
                'code' => $err === 0 ? '0' : 'PARTIAL_FAILURE',
                'message' => "Bulk synced qty for ".count($variantSkus)." SKU(s). Success: {$ok}, Error: {$err}.",
                'last' => $last['body'] ?? $last,
            ],
        ];

        $this->persistProductSyncStatus($listing, 'bulk_sync_quantity', $summary);

        if ($err === 0) {
            $okCount++;
        } else {
            $errCount++;
        }
    }

    return redirect()->to(url()->previous(route('ext.lazada.products.index')))
        ->with('status', "Bulk Sync Qty: processed ".count($ids)." listing(s). Success: {$okCount}, Error: {$errCount}.");
}

public function bulkSyncPrice(Request $request, LazadaClient $client)
{
    $ids = $request->input('listing_ids', []);
    if (!is_array($ids)) {
        $ids = [];
    }
    $ids = array_values(array_unique(array_filter(array_map(fn($v) => (int)$v, $ids), fn($v) => $v > 0)));

    if (empty($ids)) {
        return redirect()->to(url()->previous(route('ext.lazada.products.index')))->with('status', 'Bulk Sync Price: no listings selected.');
    }

    $setting = LazadaSetting::query()->first()?->decrypted();
    $creds = LazadaSetting::activeCredentials($setting);
    if (!$setting || !$setting->region || !$creds['app_key'] || !$creds['app_secret'] || !$creds['access_token']) {
        return redirect()->to(url()->previous(route('ext.lazada.products.index')))
            ->with('status', 'Missing Lazada settings (region/app key/app secret/access token).');
    }

    $pfx = (string) config('catalog.prefix');

    $listings = LazadaProduct::query()->whereIn('id', $ids)->get();
    $productIds = $listings->pluck('product_id')->filter()->unique()->values()->all();

    $products = collect();
    if (!empty($productIds)) {
        $products = DB::table($pfx.'product as p')
            ->whereIn('p.product_id', $productIds)
            ->get(['p.product_id', 'p.sku', 'p.price'])
            ->keyBy('product_id');
    }

    // Preload ERP variant price adjustments (product_option_value) for selected products.
    $variantPriceByProductId = collect();
    if (!empty($productIds)) {
        $vrows = DB::table($pfx.'product_option_value as pov')
            ->whereIn('pov.product_id', $productIds)
            ->whereNotNull('pov.sku')
            ->where('pov.sku', '!=', '')
            ->orderBy('pov.product_option_value_id')
            ->get(['pov.product_id', 'pov.sku', 'pov.absolute_price']);
        $variantPriceByProductId = $vrows->groupBy(fn($r) => (int)$r->product_id);
    }

    $okCount = 0;
    $errCount = 0;

    foreach ($listings as $listing) {
        $product = $products->get((int)$listing->product_id);
        if (!$product) {
            $this->persistProductSyncStatus($listing, 'bulk_sync_price', ['ok' => false, 'body' => ['code' => 'ERP_PRODUCT_NOT_FOUND', 'message' => 'ERP product not found']]);
            $errCount++;
            continue;
        }

        if (empty($listing->lazada_item_id)) {
            $this->persistProductSyncStatus($listing, 'bulk_sync_price', ['ok' => false, 'body' => ['code' => 'NO_ITEM_ID', 'message' => 'No Lazada Item ID. Sync Lazada ID first.']]);
            $errCount++;
            continue;
        }

        // Resolve Lazada SkuIds (cached or fetched from API)
        $skuIdMap = $this->itemCache()->resolveSkuIds($listing, $setting, $client);
        if (empty($skuIdMap)) {
            $this->persistProductSyncStatus($listing, 'bulk_sync_price', ['ok' => false, 'body' => ['code' => 'NO_SKU_IDS', 'message' => 'Could not resolve Lazada SkuIds']]);
            $errCount++;
            continue;
        }

        $apiPath = '/product/price_quantity/update';

        $pid = (int)($product->product_id ?? 0);
        $basePrice = (float)($product->price ?? 0);
        if ($basePrice < 0) {
            $basePrice = 0;
        }

        $variants = $variantPriceByProductId->get($pid);
        $variantSkus = [];
        if ($variants && count($variants) > 0) {
            foreach ($variants as $vr) {
                $vSku = trim((string)($vr->sku ?? ''));
                if ($vSku === '') continue;
                $vPrice = (float)($vr->absolute_price ?? $basePrice);
                if ($vPrice < 0) $vPrice = 0;
                $variantSkus[] = ['seller_sku' => $vSku, 'price' => $vPrice];
            }
        }

        if (empty($variantSkus)) {
            $sku = trim((string)($product->sku ?? ''));
            if ($sku === '') {
                $this->persistProductSyncStatus($listing, 'bulk_sync_price', ['ok' => false, 'body' => ['code' => 'EMPTY_SKU', 'message' => 'ERP product SKU is empty']]);
                $errCount++;
                continue;
            }
            $variantSkus = [[
                'seller_sku' => $sku,
                'price' => $basePrice,
            ]];
        }

        // Apply listing/group markup to all variant prices
        $fixedMarkup = $listing->markup_fixed;
        $percentMarkup = $listing->markup_percent;
        if ($fixedMarkup === null && $percentMarkup === null) {
            $mkGroup = $listing->groups()->first();
            if ($mkGroup) {
                $fixedMarkup = $mkGroup->markup_fixed;
                $percentMarkup = $mkGroup->markup_percent;
            }
        }
        foreach ($variantSkus as &$vs) {
            $vs['price'] = self::computeFinalPrice((float) $vs['price'], $fixedMarkup, $percentMarkup);
        }
        unset($vs);

        $ok = 0;
        $err = 0;
        $last = null;
        foreach ($variantSkus as $v) {
            $sku = trim((string)($v['seller_sku'] ?? ''));
            if ($sku === '') continue;
            $skuId = $skuIdMap[$sku] ?? null;
            if ($skuId === null) {
                $err++;
                continue;
            }
            $price = (float)($v['price'] ?? 0);
            if ($price < 0) $price = 0;
            $priceStr = number_format($price, 2, '.', '');
            $payloadXml = $this->buildPriceUpdateXml($skuId, $priceStr);

            $timestamp = (string) round(microtime(true) * 1000);
            $params = [
                'app_key' => (string) $creds['app_key'],
                'sign_method' => 'sha256',
                'timestamp' => $timestamp,
                'access_token' => (string) $creds['access_token'],
                'payload' => $payloadXml,
            ];
            $params['sign'] = $client->sign($apiPath, $params, (string) $creds['app_secret']);

            $result = $client->post((string) $setting->region, $apiPath, $params);
            $last = $result;

            LazadaApiLog::safeCreate([
                'pack' => 'lazada.product.price_quantity.update.bulk.variant',
                'method' => 'POST',
                'api_path' => $apiPath,
                'auth_required' => true,
                'request_params' => $params,
                'response_status' => (int)($result['status'] ?? 0),
                'ok' => (bool)($result['ok'] ?? false),
                'response_body' => $result['body'] ?? $result,
                'user_id' => auth()->id(),
            ]);

            $e = $this->extractLazadaError($result);
            if ($e['ok']) {
                $ok++;
            } else {
                $err++;
            }
        }

        $summary = [
            'ok' => $err === 0,
            'status' => (int)($last['status'] ?? 200),
            'body' => [
                'code' => $err === 0 ? '0' : 'PARTIAL_FAILURE',
                'message' => "Bulk synced price for ".count($variantSkus)." SKU(s). Success: {$ok}, Error: {$err}.",
                'last' => $last['body'] ?? $last,
            ],
        ];

        $this->persistProductSyncStatus($listing, 'bulk_sync_price', $summary);

        if ($err === 0) {
            $okCount++;
        } else {
            $errCount++;
        }
    }

    return redirect()->to(url()->previous(route('ext.lazada.products.index')))
        ->with('status', "Bulk Sync Price: processed ".count($ids)." listing(s). Success: {$okCount}, Error: {$errCount}.");
}

public function bulkDeleteFromLazada(Request $request, LazadaClient $client)
{
    $ids = $request->input('listing_ids', []);
    if (!is_array($ids)) {
        $ids = [];
    }
    $ids = array_values(array_unique(array_filter(array_map(fn($v) => (int)$v, $ids), fn($v) => $v > 0)));

    if (empty($ids)) {
        return redirect()->to(url()->previous(route('ext.lazada.products.index')))->with('status', 'Bulk Delete: no listings selected.');
    }

    $setting = LazadaSetting::query()->first()?->decrypted();
    $creds = LazadaSetting::activeCredentials($setting);
    if (!$setting || !$setting->region || !$creds['app_key'] || !$creds['app_secret'] || !$creds['access_token']) {
        return redirect()->to(url()->previous(route('ext.lazada.products.index')))
            ->with('status', 'Missing Lazada settings (region/app key/app secret/access token).');
    }

    $pfx = (string) config('catalog.prefix');
    $listings = LazadaProduct::query()->whereIn('id', $ids)->get();
    $okCount = 0;
    $errCount = 0;

    foreach ($listings as $listing) {
        $product = DB::table($pfx.'product as p')
            ->where('p.product_id', (int) $listing->product_id)
            ->first(['p.product_id', 'p.sku']);

        if (!$product) {
            $this->persistProductSyncStatus($listing, 'bulk_delete_from_lazada', ['ok' => false, 'body' => ['code' => 'ERP_PRODUCT_NOT_FOUND', 'message' => 'ERP product not found']]);
            $errCount++;
            continue;
        }

        $productId = (int) ($product->product_id ?? 0);
        $mainSku = trim((string) ($product->sku ?? ''));

        $variantSkus = $this->getErpVariantStockByProductId($productId);
        $sellerSkuList = [];
        foreach ($variantSkus as $v) {
            $s = trim((string)($v['seller_sku'] ?? ''));
            if ($s !== '') {
                $sellerSkuList[] = $s;
            }
        }
        $sellerSkuList = array_values(array_unique($sellerSkuList));

        if (empty($sellerSkuList)) {
            if ($mainSku === '') {
                $this->persistProductSyncStatus($listing, 'bulk_delete_from_lazada', ['ok' => false, 'body' => ['code' => 'EMPTY_SKU', 'message' => 'ERP product SKU is empty']]);
                $errCount++;
                continue;
            }
            $sellerSkuList = [$mainSku];
        } else {
            if ($mainSku !== '' && !in_array($mainSku, $sellerSkuList, true)) {
                $sellerSkuList[] = $mainSku;
            }
        }

        $apiPath = '/product/remove';
        $timestamp = (string) round(microtime(true) * 1000);

        $skuIdList = [];
        $itemId = trim((string) ($listing->lazada_item_id ?? ''));
        if ($itemId !== '') {
            try {
                $skuIdList = $this->itemCache()->fetchSkuIdListByItemId(
                    $client,
                    (string) $setting->region,
                    (string) $creds['app_key'],
                    (string) $creds['app_secret'],
                    (string) $creds['access_token'],
                    $itemId
                );
            } catch (\Throwable $ex) {
                // best-effort only
            }
        }

        $params = [
            'app_key' => (string) $creds['app_key'],
            'sign_method' => 'sha256',
            'timestamp' => $timestamp,
            'access_token' => (string) $creds['access_token'],
        ];

        if (!empty($skuIdList)) {
            $params['sku_id_list'] = json_encode($skuIdList, JSON_UNESCAPED_SLASHES);
        } else {
            $params['seller_sku_list'] = json_encode($sellerSkuList, JSON_UNESCAPED_SLASHES);
        }
        $params['sign'] = $client->sign($apiPath, $params, (string) $creds['app_secret']);

        $result = $client->post((string) $setting->region, $apiPath, $params);

        $err = $this->extractLazadaError($result);
        if (!empty($err['code']) && (string) $err['code'] === '6') {
            $paramsRetry = $params;
            $paramsRetry['timestamp'] = (string) round(microtime(true) * 1000);
            $paramsRetry['sign'] = $client->sign($apiPath, $paramsRetry, (string) $creds['app_secret']);
            $result = $client->post((string) $setting->region, $apiPath, $paramsRetry);
        }

        $this->persistProductSyncStatus($listing, 'bulk_delete_from_lazada', $result);

        $e = $this->extractLazadaError($result);
        if (!empty($e['ok'])) {
            $okCount++;
            try {
                $listing->lazada_item_id = null;
                $listing->lazada_deleted_at = now();
                // The link is gone; void the mirror rather than keep a stale
                // status the sweep can no longer correct (#184 C).
                $listing->live_status = null;
                $listing->live_checked_at = null;
                $listing->save();
            } catch (\Throwable $ex) {
                // ignore
            }
        } else {
            $errCount++;
        }

        LazadaApiLog::safeCreate([
            'pack' => 'lazada.product.remove.bulk',
            'method' => 'POST',
            'api_path' => $apiPath,
            'auth_required' => true,
            'request_params' => $params,
            'response_status' => (int)($result['status'] ?? 0),
            'ok' => (bool)($result['ok'] ?? false),
            'response_body' => $result['body'] ?? $result,
            'user_id' => auth()->id(),
        ]);
    }

    return redirect()->to(url()->previous(route('ext.lazada.products.index')))
        ->with('status', "Bulk Delete: processed ".count($ids)." product(s). Success: {$okCount}, Error: {$errCount}.");
}

    public function create(Request $request)
    {
        // Cached Lazada categories for dropdown selection
        $categories = LazadaCategory::query()
            ->orderBy('name')
            ->limit(5000)
            ->get(['category_id', 'name']);

        // Brand search is handled by AJAX autocomplete; no need to preload all brands.
        // Product selection is handled by AJAX search (searchCatalogProducts endpoint).

        return view('ext-lazada::products.form', [
            'mode' => 'create',
            'listing' => new LazadaProduct([
                'product_id' => $request->input('product_id'),
            ]),
            'categories' => $categories,
            'template' => null,
            'attributes' => [],
            'saved' => [],
            'suggested' => [],
            'groupAttrs' => [],
            'productOwnAttrs' => [],
            'productImageUrl' => null,
            'brands' => collect(),
            'brandSuggestion' => ['brand_id' => null, 'brand_name' => null],
            'selectedBrandName' => '',
            'erpSourceFields' => LazadaAttributes::ERP_SOURCE_FIELDS,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'product_id' => 'required|integer|min:1',
            'primary_category_id' => 'nullable|integer|min:1',
            'brand_id' => 'nullable|integer|min:1',
            'no_brand' => 'nullable|boolean',
            'markup_fixed' => 'nullable|numeric|min:0',
            'markup_percent' => 'nullable|numeric|min:0',
            'sale_percent' => 'nullable|numeric|min:1|max:99',
            'sale_starts_at' => 'nullable|date|required_with:sale_percent',
            'sale_ends_at' => 'nullable|date|required_with:sale_percent|after:sale_starts_at',
        ]);

        $noBrand = (bool)($data['no_brand'] ?? false);
        if ($noBrand) {
            $data['brand_id'] = null;
        }

        $listing = LazadaProduct::query()->create([
            'product_id' => (int)$data['product_id'],
            'primary_category_id' => $data['primary_category_id'] ?? null,
            'brand_id' => $data['brand_id'] ?? null,
            'brand_name_override' => $noBrand ? 'No Brand' : null,
            'markup_fixed' => $data['markup_fixed'] ?? null,
            'markup_percent' => $data['markup_percent'] ?? null,
        ]);

        ActivityLogger::log('created', 'Lazada Product', $listing->id, 'ERP #' . (int)$data['product_id']);

        return redirect()->route('ext.lazada.products.edit', $listing->id);
    }

    public function edit(int $id, \Extensions\lazada\Services\Lazada\LazadaLiveListing $liveListing)
    {
        $listing = LazadaProduct::query()->findOrFail($id);

        /* The live half (Lazada parity with the Shopee listing page,
         * 2026-08-24): what Lazada says about this item RIGHT NOW, fetched
         * on this page load. Only an uploaded listing has a live half. */
        $live = null;
        $liveError = null;
        if (!empty($listing->lazada_item_id)) {
            $liveSetting = LazadaSetting::query()->first()?->decrypted();
            $liveCreds = LazadaSetting::activeCredentials($liveSetting);
            if (!$liveSetting || !$liveCreds['complete']) {
                $liveError = 'Lazada settings are incomplete, so the live state could not be fetched.';
            } else {
                $result = $liveListing->fetch($liveSetting, $liveCreds, (string) $listing->lazada_item_id);
                $live = $result['live'];
                $liveError = $result['error'];
                // The freshest answer there is: write it to the mirror so the
                // lists agree with this page at once (the owner, 2026-08-28).
                $mirror = $live !== null ? (string) ($live['status'] ?? '')
                    : (str_contains((string) $liveError, 'holds no record') ? 'missing' : '');
                if ($mirror !== '') {
                    LazadaProduct::query()->whereKey($listing->id)
                        ->update(['live_status' => $mirror, 'live_checked_at' => now()]);
                }
            }
        }

        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $products = DB::table($pfx.'product as p')
            ->leftJoin($pfx.'product_description as pd', function ($j) use ($langId) {
                $j->on('p.product_id', '=', 'pd.product_id')
                    ->where('pd.language_id', '=', $langId);
            })
            ->orderByDesc('p.product_id')
            ->limit(200)
            ->get(['p.product_id', 'p.sku', 'p.model', 'p.quantity', 'p.status', 'p.image', 'pd.name']);

        // Cached Lazada categories for dropdown selection
        $categories = LazadaCategory::query()
            ->orderBy('name')
            ->limit(5000)
            ->get(['category_id', 'name']);

        $template = null;
        $attributes = [];
        $selectedBrandName = '';
        if (!empty($listing->brand_id)) {
            $b = LazadaBrand::query()
                ->where('region', $this->region())
                ->where('brand_id', (int)$listing->brand_id)
                ->first();
            $selectedBrandName = $b ? (string)$b->name : '';
        }
        if ($listing->primary_category_id) {
            $template = LazadaCategoryTemplate::query()
                ->where('region', $this->region())
                ->where('primary_category_id', (int)$listing->primary_category_id)
                ->first();

            if ($template && $template->template_body) {
                $attributes = $this->attrs()->extractAttributes($template->template_body);
                // Brand is handled by the top-level Brand selector (listing.brand_id); do not render as a mappable attribute.
                $attributes = array_values(array_filter($attributes, function ($a) {
                    $k = strtolower(trim((string)($a['key'] ?? '')));
                    return $k !== 'brand';
                }));
            }
        }

        $productOwnSaved = LazadaProductAttribute::query()
            ->where('lazada_product_id', $listing->id)
            ->pluck('value', 'attribute_key')
            ->toArray();

        // Auto-populate from linked product group: group values serve as defaults
        $groupAttrs = [];
        $firstGroup = $listing->groups()->first();
        if ($firstGroup) {
            $groupAttrs = LazadaProductGroupAttribute::query()
                ->where('lazada_product_group_id', $firstGroup->id)
                ->pluck('value', 'attribute_key')
                ->toArray();
        }

        // Merge: product's own values take priority over group defaults
        $saved = array_merge($groupAttrs, $productOwnSaved);

        // Variants: use OpenCart-style product_option_value rows
        $variants = DB::table($pfx.'product_option_value as pov')
            ->leftJoin($pfx.'option_description as od', function ($j) use ($langId) {
                $j->on('pov.option_id', '=', 'od.option_id')
                    ->where('od.language_id', '=', $langId);
            })
            ->leftJoin($pfx.'option_value_description as ovd', function ($j) use ($langId) {
                $j->on('pov.option_value_id', '=', 'ovd.option_value_id')
                    ->where('ovd.language_id', '=', $langId);
            })
            ->where('pov.product_id', (int)$listing->product_id)
            ->orderBy('pov.product_option_value_id')
            ->get([
                'pov.product_option_value_id',
                'pov.option_id',
                'pov.option_value_id',
                'pov.sku',
                'pov.quantity',
                'pov.absolute_price',
                'od.name as option_name',
                'ovd.name as option_value_name',
            ]);

        $variantMap = LazadaProductVariant::query()
            ->where('lazada_product_id', $listing->id)
            ->get()
            ->keyBy(function ($v) {
                return $v->product_option_value_id === null ? 'base' : (string)$v->product_option_value_id;
            });

        // Base product info for payload preview (includes extra fields for mapping)
        $productRow = DB::table($pfx.'product as p')
            ->leftJoin($pfx.'product_description as pd', function ($j) use ($langId) {
                $j->on('p.product_id', '=', 'pd.product_id')
                    ->where('pd.language_id', '=', $langId);
            })
            ->leftJoin($pfx.'manufacturer as m', 'p.manufacturer_id', '=', 'm.manufacturer_id')
            ->where('p.product_id', (int)$listing->product_id)
            ->first([
                'p.product_id','p.sku','p.model','p.image','p.price','p.quantity',
                'p.weight','p.length','p.width','p.height','p.date_modified',
                'p.upc','p.ean','p.jan','p.isbn','p.mpn',
                'pd.name','pd.description','pd.meta_title','pd.meta_description',
                'm.name as manufacturer_name',
            ]);

        // Resolve __map: values to actual product field values for display
        $suggested = $this->attrs()->suggestForAttributes($attributes, $productRow, $saved);

        // For display, so browser facing rather than Lazada facing.
        $productImageUrl = $this->toDisplayImageUrl($productRow->image ?? null);

        // Brand suggestions
        $brandSuggestion = $this->attrs()->suggestBrandFromManufacturer($productRow->manufacturer_name ?? null);
        if (!$listing->brand_id && $brandSuggestion['brand_id']) {
            // Do not auto-save; just suggest in UI
        }

        return view('ext-lazada::products.form', [
            'mode' => 'edit',
            'listing' => $listing,
            'products' => $products,
            'categories' => $categories,
            'template' => $template,
            'attributes' => $attributes,
            'saved' => $saved,
            'variants' => $variants,
            'variantMap' => $variantMap,
            'productRow' => $productRow,
            'suggested' => $suggested,
            'groupAttrs' => $groupAttrs,
            'productOwnAttrs' => $productOwnSaved,
            'productImageUrl' => $productImageUrl,
            'brands' => collect(),
            'brandSuggestion' => $brandSuggestion,
            'selectedBrandName' => $selectedBrandName,
            'erpSourceFields' => LazadaAttributes::ERP_SOURCE_FIELDS,
            'payloadPreview' => session('payload_preview_json'),
            'pushRequest' => session('push_request_json'),
            'pushResponse' => session('push_response_json'),
            'live' => $live,
            'liveError' => $liveError,
        ]);
    }

    public function update(Request $request, int $id)
    {
        $listing = LazadaProduct::query()->findOrFail($id);

        $data = $request->validate([
            'primary_category_id' => 'nullable|integer|min:1',
            'brand_id' => 'nullable|integer|min:1',
            'no_brand' => 'nullable|boolean',
            'markup_fixed' => 'nullable|numeric|min:0',
            'markup_percent' => 'nullable|numeric|min:0',
            'sale_percent' => 'nullable|numeric|min:1|max:99',
            'sale_starts_at' => 'nullable|date|required_with:sale_percent',
            'sale_ends_at' => 'nullable|date|required_with:sale_percent|after:sale_starts_at',
        ]);

        $noBrand = (bool)($data['no_brand'] ?? false);
        if ($noBrand) {
            $data['brand_id'] = null;
        }

        $listing->primary_category_id = $data['primary_category_id'] ?? null;
        $listing->brand_id = $data['brand_id'] ?? null;
        $listing->brand_name_override = $noBrand ? 'No Brand' : null;
        $listing->markup_fixed = $data['markup_fixed'] ?? null;
        $listing->markup_percent = $data['markup_percent'] ?? null;

        /* The discount RULE (#146): percent off with a window; the sale
         * price itself is never stored - it is computed from the final
         * price at push time. Clearing the percent clears the rule. */
        $listing->sale_percent = ($data['sale_percent'] ?? null) !== null ? (float) $data['sale_percent'] : null;
        $listing->sale_starts_at = $listing->sale_percent !== null ? ($data['sale_starts_at'] ?? null) : null;
        $listing->sale_ends_at = $listing->sale_percent !== null ? ($data['sale_ends_at'] ?? null) : null;

        // Compute total_price from ERP base price + effective markup
        $pfx = (string) config('catalog.prefix');
        $erpPrice = (float) DB::table($pfx . 'product')->where('product_id', (int)$listing->product_id)->value('price');

        $fixedMarkup = $listing->markup_fixed;
        $percentMarkup = $listing->markup_percent;

        // Fall back to group markup if product has none
        if ($fixedMarkup === null && $percentMarkup === null) {
            $mkGroup = $listing->groups()->first();
            if ($mkGroup) {
                $fixedMarkup = $mkGroup->markup_fixed;
                $percentMarkup = $mkGroup->markup_percent;
            }
        }

        $listing->total_price = self::computeFinalPrice($erpPrice, $fixedMarkup, $percentMarkup);
        $listing->save();

        ActivityLogger::log('updated', 'Lazada Product', $listing->id, 'ERP #' . (int)$listing->product_id);

        return redirect()->route('ext.lazada.products.edit', $listing->id)->with('status', 'Listing updated.');
    }

    public function syncBrands(Request $request, int $id, LazadaClient $client)
    {
        $listing = LazadaProduct::query()->findOrFail($id);

        $req = $request->validate([
            'max_pages' => 'nullable|integer|min:1|max:200',
            'page_size' => 'nullable|integer|min:1|max:200',
            // Backward compatible with older UI/explorer inputs
            'page_no' => 'nullable|integer|min:1|max:100000',
            'startRow' => 'nullable|integer|min:0|max:100000000',
            'pageSize' => 'nullable|integer|min:1|max:200',
        ]);

        $maxPages = (int)($req['max_pages'] ?? 20);
        // Prefer Lazada-native key if supplied
        $pageSize = (int)($req['pageSize'] ?? ($req['page_size'] ?? 200));

        $setting = LazadaSetting::query()->first()?->decrypted();
        $creds = LazadaSetting::activeCredentials($setting);
        if (!$setting || !$setting->region || !$creds['app_key'] || !$creds['app_secret']) {
            return redirect()->route('ext.lazada.products.edit', $listing->id)
                ->with('status', 'Missing Lazada settings (region/app key/app secret).');
        }

        $apiPath = '/category/brands/query';
        $region = (string)$setting->region;
        $totalSaved = 0;

        // Lazada Open Platform GetBrandByPages requires startRow + pageSize (NOT page_no/page_size)
        // startRow is zero-based row offset.
        $explicitStartRow = isset($req['startRow']) ? (int)$req['startRow'] : null;
        $explicitPageNo = isset($req['page_no']) ? (int)$req['page_no'] : null;

        // If a startRow was explicitly provided, we will fetch starting from there (single page unless max_pages > 1).
        // Otherwise we page from 0 using max_pages.
        $startPageNo = $explicitPageNo && $explicitPageNo > 0 ? $explicitPageNo : 1;
        for ($pageNo = $startPageNo; $pageNo <= $maxPages; $pageNo++) {
            $timestamp = (string)round(microtime(true) * 1000);
            $startRow = $explicitStartRow !== null
                ? (int)($explicitStartRow + (($pageNo - $startPageNo) * $pageSize))
                : (int)(($pageNo - 1) * $pageSize);
            $params = [
                'app_key' => (string)$creds['app_key'],
                'sign_method' => 'sha256',
                'timestamp' => $timestamp,
                'startRow' => (string)$startRow,
                'pageSize' => (string)$pageSize,
                // Optional: if you want localized brand names, add languageCode (e.g. en_PH)
                // 'languageCode' => 'en_PH',
            ];
            $params['sign'] = $client->sign($apiPath, $params, (string)$creds['app_secret']);
            $result = $client->get($region, $apiPath, $params);

			LazadaApiLog::safeCreate([
                'pack' => 'lazada.brands.query',
                'method' => 'GET',
                'api_path' => $apiPath,
                'auth_required' => false,
                'request_params' => $params,
                'response_status' => (int)($result['status'] ?? 0),
                'ok' => (bool)($result['ok'] ?? false),
                'response_body' => $result['body'] ?? $result,
                'user_id' => auth()->id(),
			]);

			if (!(bool)($result['ok'] ?? false)) {
                break;
            }

            $brands = $this->extractBrandRows($result['body'] ?? null);
            if (empty($brands)) {
                break;
            }

            DB::transaction(function () use ($brands, $region, &$totalSaved) {
                foreach ($brands as $b) {
                    $brandId = (int)($b['brand_id'] ?? $b['id'] ?? 0);
                    $name = (string)($b['name'] ?? $b['brand_name'] ?? '');
                    $name = trim($name);
                    if ($brandId <= 0 || $name === '') {
                        continue;
                    }

                    LazadaBrand::query()->updateOrCreate(
                        ['region' => $region, 'brand_id' => $brandId],
                        ['name' => $name, 'raw' => is_array($b) ? $b : null]
                    );
                    $totalSaved++;
                }
            });
        }

        return redirect()->route('ext.lazada.products.edit', $listing->id)
            ->with('status', 'Brands synced. Saved/updated: ' . $totalSaved);
    }

    /**
     * Sync Lazada brands without requiring a listing record (useful on Create Listing).
     * This keeps brand cache up to date for dropdown usage.
     */
    public function syncBrandsGlobal(Request $request, LazadaClient $client)
    {
        $req = $request->validate([
            'max_pages' => 'nullable|integer|min:1|max:200',
            'page_size' => 'nullable|integer|min:1|max:200',
            // Backward compatible with older UI/explorer inputs
            'page_no' => 'nullable|integer|min:1|max:100000',
            'startRow' => 'nullable|integer|min:0|max:100000000',
            'pageSize' => 'nullable|integer|min:1|max:200',
        ]);

        $maxPages = (int)($req['max_pages'] ?? 20);
        $pageSize = (int)($req['pageSize'] ?? ($req['page_size'] ?? 200));

        $setting = LazadaSetting::query()->first()?->decrypted();
        $creds = LazadaSetting::activeCredentials($setting);
        if (!$setting || !$setting->region || !$creds['app_key'] || !$creds['app_secret']) {
            return back()->with('status', 'Missing Lazada settings (region/app key/app secret).');
        }

        $apiPath = '/category/brands/query';
        $region = (string)$setting->region;
        $totalSaved = 0;

        $explicitStartRow = isset($req['startRow']) ? (int)$req['startRow'] : null;
        $explicitPageNo = isset($req['page_no']) ? (int)$req['page_no'] : null;
        $startPageNo = $explicitPageNo && $explicitPageNo > 0 ? $explicitPageNo : 1;

        for ($pageNo = $startPageNo; $pageNo <= $maxPages; $pageNo++) {
            $timestamp = (string)round(microtime(true) * 1000);
            $startRow = $explicitStartRow !== null
                ? (int)($explicitStartRow + (($pageNo - $startPageNo) * $pageSize))
                : (int)(($pageNo - 1) * $pageSize);

            $params = [
                'app_key' => (string)$creds['app_key'],
                'sign_method' => 'sha256',
                'timestamp' => $timestamp,
                'startRow' => (string)$startRow,
                'pageSize' => (string)$pageSize,
            ];

            $params['sign'] = $client->sign($apiPath, $params, (string)$creds['app_secret']);
            $result = $client->get($region, $apiPath, $params);

            LazadaApiLog::safeCreate([
                'pack' => 'lazada.brands.query',
                'method' => 'GET',
                'api_path' => $apiPath,
                'auth_required' => false,
                'request_params' => $params,
                'response_status' => (int)($result['status'] ?? 0),
                'ok' => (bool)($result['ok'] ?? false),
                'response_body' => $result['body'] ?? $result,
                'user_id' => auth()->id(),
            ]);

            if (!(bool)($result['ok'] ?? false)) {
                break;
            }

            $brands = $this->extractBrandRows($result['body'] ?? null);
            if (empty($brands)) {
                break;
            }

            DB::transaction(function () use ($brands, $region, &$totalSaved) {
                foreach ($brands as $b) {
                    $brandId = (int)($b['brand_id'] ?? $b['id'] ?? 0);
                    $name = (string)($b['name'] ?? $b['brand_name'] ?? '');
                    $name = trim($name);
                    if ($brandId <= 0 || $name === '') {
                        continue;
                    }

                    LazadaBrand::query()->updateOrCreate(
                        ['region' => $region, 'brand_id' => $brandId],
                        ['name' => $name, 'raw' => is_array($b) ? $b : null]
                    );
                    $totalSaved++;
                }
            });
        }

        return back()->with('status', 'Brands synced. Saved/updated: ' . $totalSaved);
    }

    private function extractBrandRows($body): array
    {
        if (!is_array($body)) {
            return [];
        }

        // Lazada responses vary by endpoint/version. We support common shapes and also
        // do a recursive search for the first list that looks like brand rows.
        $data = $body['data'] ?? $body;

        // Fast paths for known keys
        $candidates = [
            $data['brands'] ?? null,
            $data['brand_list'] ?? null,
            $data['brandList'] ?? null,
            $data['module']['brands'] ?? null,
            $data['result']['brands'] ?? null,
            $data['items'] ?? null,
        ];

        foreach ($candidates as $cand) {
            if (is_array($cand) && array_is_list($cand)) {
                return $cand;
            }
        }

        $found = $this->findBrandRowListRecursive($data);
        return $found ?? [];
    }

    private function findBrandRowListRecursive($node): ?array
    {
        if (!is_array($node)) {
            return null;
        }

        if (array_is_list($node) && !empty($node) && is_array($node[0])) {
            $first = $node[0];
            $hasId = array_key_exists('brand_id', $first) || array_key_exists('id', $first);
            $hasName = array_key_exists('name', $first) || array_key_exists('brand_name', $first);
            if ($hasId && $hasName) {
                return $node;
            }
        }

        foreach ($node as $v) {
            if (is_array($v)) {
                $r = $this->findBrandRowListRecursive($v);
                if ($r !== null) {
                    return $r;
                }
            }
        }

        return null;
    }


    private function findBrandListRecursive($node): ?array
    {
        if (!is_array($node)) {
            return null;
        }

        // If this is a list, check if its elements look like brand rows.
        if (array_is_list($node) && !empty($node)) {
            $first = $node[0];
            if (is_array($first)) {
                $hasId = array_key_exists('brand_id', $first) || array_key_exists('brandId', $first) || array_key_exists('id', $first);
                $hasName = array_key_exists('name', $first) || array_key_exists('brand_name', $first) || array_key_exists('brandName', $first);
                if ($hasId && $hasName) {
                    return $node;
                }
            }
        }

        // Otherwise, traverse children.
        foreach ($node as $v) {
            if (is_array($v)) {
                $res = $this->findBrandListRecursive($v);
                if ($res !== null) {
                    return $res;
                }
            }
        }
        return null;
    }

    public function syncTemplate(Request $request, int $id, LazadaClient $client)
    {
        $listing = LazadaProduct::query()->findOrFail($id);

        $request->validate([
            'primary_category_id' => 'required|integer|min:1',
        ]);

        $primaryCategoryId = (int)$request->input('primary_category_id');
        $listing->primary_category_id = $primaryCategoryId;
        $listing->save();

        $setting = LazadaSetting::query()->first()?->decrypted();
        $creds = LazadaSetting::activeCredentials($setting);
        if (!$setting || !$setting->region || !$creds['app_key'] || !$creds['app_secret']) {
            return redirect()->route('ext.lazada.products.edit', $listing->id)->with('status', 'Missing Lazada settings.');
        }

        $apiPath = '/category/attributes/get';
        $timestamp = (string)round(microtime(true) * 1000);
        $params = [
            'app_key' => (string)$creds['app_key'],
            'sign_method' => 'sha256',
            'timestamp' => $timestamp,
            'primary_category_id' => (string)$primaryCategoryId,
        ];
        $params['sign'] = $client->sign($apiPath, $params, (string)$creds['app_secret']);
        $result = $client->get((string)$setting->region, $apiPath, $params);

        LazadaCategoryTemplate::query()->updateOrCreate(
            [
                'region' => (string)$setting->region,
                'primary_category_id' => $primaryCategoryId,
            ],
            [
                'template_body' => $result['body'] ?? null,
                'fetched_at' => now(),
            ]
        );

        return redirect()->route('ext.lazada.products.edit', $listing->id)->with('status', 'Category template synced.');
    }

    public function saveAttributes(Request $request, int $id)
    {
        $listing = LazadaProduct::query()->findOrFail($id);

        $data = $request->validate([
            'attributes' => 'array',
            'attributes.*' => 'nullable|string|max:2000',
        ]);

        $attrs = (array)($data['attributes'] ?? []);

        // Brand is handled by the top-level Brand selector (listing.brand_id); ignore any submitted "brand" attribute.
        if (array_key_exists('brand', $attrs)) {
            unset($attrs['brand']);
        }

        // Force mapped attributes to always use ERP values based on mappings.
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');
        $productRow = DB::table($pfx.'product as p')
            ->leftJoin($pfx.'product_description as pd', function ($j) use ($langId) {
                $j->on('p.product_id', '=', 'pd.product_id')
                    ->where('pd.language_id', '=', $langId);
            })
            ->leftJoin($pfx.'manufacturer as m', 'p.manufacturer_id', '=', 'm.manufacturer_id')
            ->where('p.product_id', (int)$listing->product_id)
            ->first([
                'p.product_id','p.sku','p.model','p.image','p.price','p.quantity',
                'p.weight','p.length','p.width','p.height','p.date_modified',
                'p.upc','p.ean','p.jan','p.isbn','p.mpn',
                'pd.name','pd.description','pd.meta_title','pd.meta_description',
                'm.name as manufacturer_name',
            ]);

        // Images are mandatory for Lazada product creation.
        $imageUrls = $this->getProductImageUrls((int)$listing->product_id, $productRow);
        $imageUrls = array_values(array_filter(array_map(fn($u) => $this->normalizeImageUrl((string)$u), $imageUrls)));
        if (empty($imageUrls)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'images' => 'At least 1 product image is required. Please upload a main image (and optional additional images) in the core ERP Product page first.'
            ]);
        }

        // Validate mandatory (required) attributes from the cached category template.
        $requiredKeys = [];
        $requiredNames = [];
        if ($listing->primary_category_id) {
            $template = LazadaCategoryTemplate::query()
                ->where('region', $this->region())
                ->where('primary_category_id', (int)$listing->primary_category_id)
                ->first();

            if ($template && $template->template_body) {
                $templateAttrs = $this->attrs()->extractAttributes($template->template_body);

                foreach ($templateAttrs as $a) {
                    if (!empty($a['required'])) {
                        $k = (string)($a['key'] ?? '');
                        if ($k !== '') {
                            $requiredKeys[] = $k;
                            $requiredNames[$k] = (string)($a['name'] ?? $k);
                        }
                    }
                }
            }
        }

        if (!empty($requiredKeys)) {
            // Build a merged map: existing saved values + incoming form values
            $existing = LazadaProductAttribute::query()
                ->where('lazada_product_id', $listing->id)
                ->pluck('value', 'attribute_key')
                ->toArray();

            $merged = $existing;
            foreach ($attrs as $k => $v) {
                $k = trim((string)$k);
                if ($k === '') continue;
                $merged[$k] = ($v === '' ? null : $v);
            }

            $errs = [];
            foreach ($requiredKeys as $k) {
                $keyLower = strtolower(trim((string)$k));
                if ($keyLower === 'brand') {
                    // Brand is handled by the top-level Brand selector (listing.brand_id).
                    continue;
                }
                if ($this->attrs()->isSkuLevelRequiredKey($k)) {
                    // Lazada marks some SKU-level fields as mandatory in templates (e.g. SellerSku, price).
                    // Those are handled in the Variant Mapping section / payload builder.
                    continue;
                }
                if (LazadaAttributes::isAutoFilledAttributeKey($k)) {
                    // Name, description and friends fill themselves from the
                    // catalogue at push time; blank here is not a problem.
                    continue;
                }
                $val = $merged[$k] ?? null;
                if ($val === null || trim((string)$val) === '') {
                    $label = $requiredNames[$k] ?? $k;
                    $errs['attributes.'.$k] = $label . ' is mandatory.';
                }
            }

            if (!empty($errs)) {
                return redirect()->route('ext.lazada.products.edit', $listing->id)
                    ->withErrors($errs)
                    ->withInput();
            }
        }

        $attrFk = 'lazada_product_id';
        DB::transaction(function () use ($listing, $attrs, $attrFk) {
            foreach ($attrs as $key => $value) {
                $key = trim((string)$key);
                if ($key === '') {
                    continue;
                }
                LazadaProductAttribute::query()->updateOrCreate(
                    [
                        $attrFk => $listing->id,
                        'attribute_key' => $key,
                    ],
                    [
                        'value' => $value === '' ? null : $value,
                    ]
                );
            }
        });

        return redirect()->route('ext.lazada.products.edit', $listing->id)->with('status', 'Attributes saved.');
    }

    public function saveVariants(Request $request, int $id)
    {
        $listing = LazadaProduct::query()->findOrFail($id);

        $data = $request->validate([
            'variants' => 'array',
            'variants.*.seller_sku' => 'nullable|string|max:64',
            'variants.*.price' => 'nullable|numeric',
            'variants.*.quantity' => 'nullable|integer',
        ]);

        $rows = (array)($data['variants'] ?? []);

        $variantFk = 'lazada_product_id';
        DB::transaction(function () use ($listing, $rows, $variantFk) {
            foreach ($rows as $povId => $row) {
                $povId = (string)$povId;
                if ($povId === '' || !is_array($row)) {
                    continue;
                }
                $idVal = ctype_digit($povId) ? (int)$povId : null;
                LazadaProductVariant::query()->updateOrCreate(
                    [
                        $variantFk => $listing->id,
                        'product_option_value_id' => $idVal,
                    ],
                    [
                        'seller_sku' => isset($row['seller_sku']) && $row['seller_sku'] !== '' ? (string)$row['seller_sku'] : null,
                        'price' => isset($row['price']) && $row['price'] !== '' ? (float)$row['price'] : null,
                        'quantity' => isset($row['quantity']) && $row['quantity'] !== '' ? (int)$row['quantity'] : null,
                    ]
                );
            }
        });

        return redirect()->route('ext.lazada.products.edit', $listing->id)->with('status', 'Variant mapping saved.');
    }

    public function buildPayload(Request $request, int $id)
    {
        $listing = LazadaProduct::query()->findOrFail($id);

        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $product = DB::table($pfx.'product as p')
            ->leftJoin($pfx.'product_description as pd', function ($j) use ($langId) {
                $j->on('p.product_id', '=', 'pd.product_id')
                    ->where('pd.language_id', '=', $langId);
            })
            ->leftJoin($pfx.'manufacturer as m', 'p.manufacturer_id', '=', 'm.manufacturer_id')
            ->where('p.product_id', (int)$listing->product_id)
            ->first([
                'p.product_id','p.sku','p.model','p.image','p.price','p.quantity',
                'p.weight','p.length','p.width','p.height','p.date_modified',
                'p.upc','p.ean','p.jan','p.isbn','p.mpn',
                'pd.name','pd.description','pd.meta_title','pd.meta_description',
                'm.name as manufacturer_name',
            ]);

        // Images are mandatory for Lazada product creation.
        $imageUrls = $this->getProductImageUrls((int)$listing->product_id, $product);
        $imageUrls = array_values(array_filter(array_map(fn($u) => $this->normalizeImageUrl((string)$u), $imageUrls)));
        if (empty($imageUrls)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'images' => 'At least 1 product image is required. Please upload a main image (and optional additional images) in the core ERP Product page first.'
            ]);
        }

        $attrs = LazadaProductAttribute::query()
            ->where('lazada_product_id', $listing->id)
            ->pluck('value', 'attribute_key')
            ->toArray();

        // Ensure Brand exists in attributes before validating required template keys.
        $brandId = (int)($listing->brand_id ?? 0);
        $brandOverride = trim((string)($listing->brand_name_override ?? ''));
        $isNoBrand = $brandOverride !== '' && strtolower($brandOverride) === 'no brand';

        if ($isNoBrand) {
            $attrs['brand'] = 'No Brand';
            unset($attrs['brand_id']);
        } elseif ($brandId > 0) {
            $brandName = LazadaBrand::query()
                ->where('region', $this->region())
                ->where('brand_id', $brandId)
                ->value('name');
            $attrs['brand'] = $brandName ? (string)$brandName : (string)$brandId;
            $attrs['brand_id'] = $brandId;
        } else {
            $attrs['brand'] = 'No Brand';
            unset($attrs['brand_id']);
        }

        // Remove present-but-null values for cleaner preview.
        $attrs = (array) $this->compactPayloadValue($attrs);

        // Resolve __map: attribute values to actual product field values for payload.
        $attrs = $this->attrs()->resolveMapAttributes($attrs, $product);
        $attrs = $this->attrs()->autoFillBasics($attrs, $product);

        // Validate mandatory attributes before building payload
        $requiredKeys = [];
        $requiredNames = [];
        if ($listing->primary_category_id) {
            $template = LazadaCategoryTemplate::query()
                ->where('region', $this->region())
                ->where('primary_category_id', (int)$listing->primary_category_id)
                ->first();
            if ($template && $template->template_body) {
                $templateAttrs = $this->attrs()->extractAttributes($template->template_body);

                foreach ($templateAttrs as $a) {
                    if (!empty($a['required'])) {
                        $k = (string)($a['key'] ?? '');
                        if ($k !== '') {
                            $requiredKeys[] = $k;
                            $requiredNames[$k] = (string)($a['name'] ?? $k);
                        }
                    }
                }
            }
        }
        if (!empty($requiredKeys)) {
            $errs = [];
            foreach ($requiredKeys as $k) {
                if ($this->attrs()->isSkuLevelRequiredKey($k)) {
                    continue;
                }
                $val = $attrs[$k] ?? null;
                if ($val === null || trim((string)$val) === '') {
                    $label = $requiredNames[$k] ?? $k;
                    $errs['attributes.'.$k] = $label . ' is mandatory.';
                }
            }
            if (!empty($errs)) {
                return redirect()->route('ext.lazada.products.edit', $listing->id)
                    ->withErrors($errs);
            }
        }

        $povRows = DB::table($pfx.'product_option_value')
            ->where('product_id', (int)$listing->product_id)
            ->orderBy('product_option_value_id')
            ->get(['product_option_value_id','sku','quantity','absolute_price']);

        $map = LazadaProductVariant::query()
            ->where('lazada_product_id', $listing->id)
            ->get()
            ->keyBy(function ($v) {
                return $v->product_option_value_id === null ? 'base' : (string)$v->product_option_value_id;
            });

        $skus = [];
        $basePrice = (float)($product->price ?? 0);
        $usedSellerSkus = [];

        // Effective markup for variant price computation
        $effectiveFixed = $listing->markup_fixed;
        $effectivePercent = $listing->markup_percent;
        if ($effectiveFixed === null && $effectivePercent === null) {
            $mkGroup = $listing->groups()->first();
            if ($mkGroup) {
                $effectiveFixed = $mkGroup->markup_fixed;
                $effectivePercent = $mkGroup->markup_percent;
            }
        }

        // SellerSku fallback: sku then model.
        $fallbackBaseSku = null;
        if ($product) {
            $fallbackBaseSku = trim((string)($product->sku ?? ''));
            if ($fallbackBaseSku === '') {
                $fallbackBaseSku = trim((string)($product->model ?? ''));
            }
            if ($fallbackBaseSku === '') {
                $fallbackBaseSku = null;
            }
        }
        if ($povRows->count() > 0) {
            foreach ($povRows as $r) {
                $key = (string)$r->product_option_value_id;
                $m = $map->get($key);

                // SellerSku mapping rule:
                // 1) Listing variant override
                // 2) ERP product.sku (single source of truth)
                // 3) ERP product.model
                $candidate = trim((string)($m && $m->seller_sku ? $m->seller_sku : ''));
                if ($candidate === '') {
                    $candidate = $fallbackBaseSku;
                }
                // Ensure uniqueness across variant rows
                if ($candidate !== null && $candidate !== '') {
                    if (isset($usedSellerSkus[$candidate])) {
                        $candidate = $candidate . '-' . (int)$r->product_option_value_id;
                    }
                    $usedSellerSkus[$candidate] = true;
                }

                $erpPrice = (float)($r->absolute_price ?? $basePrice);
                $finalPrice = $m && $m->price !== null ? (float)$m->price : $erpPrice;
                $finalPrice = self::computeFinalPrice($finalPrice, $effectiveFixed, $effectivePercent);
                $skus[] = [
                    'product_option_value_id' => (int)$r->product_option_value_id,
                    'seller_sku' => $candidate,
                    'price' => $finalPrice,
                    'quantity' => $m && $m->quantity !== null ? (int)$m->quantity : (int)$r->quantity,
                ];
            }
        } else {
            // No variants in ERP; show a single base SKU in preview.
            // Use stored total_price (base + markup) if available.
            $singlePrice = $listing->total_price ?? (float)($product->price ?? 0);
            $skus[] = [
                'product_option_value_id' => null,
                'seller_sku' => $fallbackBaseSku,
                'price' => (float)$singlePrice,
                'quantity' => (int)($product->quantity ?? 0),
            ];
        }

        // Validate SKU-level mandatory fields (SellerSku, price) if Lazada template requires them.
        if (!empty($requiredKeys)) {
            $skuErrs = [];
            $needsSellerSku = false;
            $needsPrice = false;
            foreach ($requiredKeys as $k) {
                $lk = strtolower((string)$k);
                if ($lk === 'sellersku' || $lk === 'seller_sku') $needsSellerSku = true;
                if ($lk === 'price') $needsPrice = true;
            }
            if ($needsSellerSku) {
                foreach ($skus as $i => $s) {
                    if (empty($s['seller_sku'])) {
                        $skuErrs['variants'] = 'SellerSku is mandatory. Please set SKU in your ERP variants or fill Variant Mapping overrides.';
                        break;
                    }
                }
            }
            if ($needsPrice) {
                foreach ($skus as $i => $s) {
                    if (!isset($s['price']) || $s['price'] === null || (float)$s['price'] <= 0) {
                        $skuErrs['variants'] = 'Price is mandatory. Please set product price in ERP (base price) and/or option price adjustments, or fill Variant Mapping overrides.';
                        break;
                    }
                }
            }
            if (!empty($skuErrs)) {
                return redirect()->route('ext.lazada.products.edit', $listing->id)
                    ->withErrors($skuErrs);
            }
        }

        // Brand resolution (preview only)
        $brandSuggestion = $this->attrs()->suggestBrandFromManufacturer($product?->manufacturer_name ?? null);
        $effectiveBrandId = $listing->brand_id ?: ($brandSuggestion['brand_id'] ?? null);
        $effectiveBrandName = $listing->brand_name_override;
        if ($effectiveBrandName === null || $effectiveBrandName === '') {
            // If we found a Lazada brand match, leave name null (ID is enough).
            // Otherwise, use manufacturer name as the text override.
            $effectiveBrandName = ($brandSuggestion['brand_id'] ?? null) ? null : (string)($product?->manufacturer_name ?? '');
        }

        $payload = [
            'region' => $this->region(),
            'product_id' => $listing->product_id,
            'primary_category_id' => $listing->primary_category_id,
            'brand_id' => $listing->brand_id,
            'brand_name_override' => $listing->brand_name_override,
            'effective_brand' => [
                'brand_id' => $effectiveBrandId,
                'brand_name' => $effectiveBrandName,
                'source' => $listing->brand_id ? 'listing.brand_id' : ($brandSuggestion['brand_id'] ? 'manufacturer->lazada_brand_match' : 'manufacturer_name'),
            ],
            'product' => $product ? [
                'sku' => $product->sku ?? null,
                'model' => $product->model ?? null,
                'name' => $product->name ?? null,
                'description' => $product->description ?? null,
                'manufacturer' => $product->manufacturer_name ?? null,
                'weight' => $product->weight ?? null,
                'dimensions' => [
                    'length' => $product->length ?? null,
                    'width' => $product->width ?? null,
                    'height' => $product->height ?? null,
                ],
            ] : null,
            'attributes' => $attrFinal ?? $attrs,
            'skus' => $skus,
        ];

        return redirect()->route('ext.lazada.products.edit', $listing->id)
            ->with('status', 'Payload built (preview only).')
            ->with('payload_preview_json', json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function pushSample(Request $request, int $id, LazadaClient $client)
    {
        $listing = LazadaProduct::query()->findOrFail($id);

        $setting = LazadaSetting::query()->first()?->decrypted();
        $creds = LazadaSetting::activeCredentials($setting);
        if (!$setting || !$setting->region || !$creds['app_key'] || !$creds['app_secret'] || !$creds['access_token']) {
            return redirect()->route('ext.lazada.products.edit', $listing->id)
                ->with('status', 'Missing Lazada settings (region/app key/app secret/access token).');
        }

        if (empty($listing->primary_category_id)) {
            return redirect()->route('ext.lazada.products.edit', $listing->id)
                ->withErrors(['primary_category_id' => 'Primary Category is required before pushing.']);
        }

        // Build a Lazada-ready product payload (best-effort). We'll learn from QC errors and iterate.
        try {
            [$productPayload, $preview] = $this->buildLazadaProductCreatePayload($listing, $setting, $client);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return redirect()->route('ext.lazada.products.edit', $listing->id)
                ->withErrors($e->errors())
                ->withInput();
        }

        // Lazada QC rejects non-Lazada image URLs (outer links).
        // Convert ERP/public image URLs to Lazada inlinks before pushing.
        try {
            $productPayload = $this->ensureLazadaInlinkImages($productPayload, $setting, $client);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return redirect()->route('ext.lazada.products.edit', $listing->id)
                ->withErrors($e->errors())
                ->withInput();
        }

        // Keep preview aligned with the final payload we are about to send.
        $preview['images'] = data_get($productPayload, 'Request.Product.Images.Image', []);
        $preview['payload'] = $productPayload;

        // Lazada product create expects a JSON string payload.
        $apiPath = '/product/create';
        $timestamp = (string) round(microtime(true) * 1000);
        $params = [
            'app_key' => (string) $creds['app_key'],
            'sign_method' => 'sha256',
            'timestamp' => $timestamp,
            'access_token' => (string) $creds['access_token'],
            'payload' => json_encode($productPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
        $params['sign'] = $client->sign($apiPath, $params, (string) $creds['app_secret']);

        $result = $client->post((string) $setting->region, $apiPath, $params);

        LazadaApiLog::safeCreate([
            'pack' => 'lazada.product.create',
            'method' => 'POST',
            'api_path' => $apiPath,
            'auth_required' => true,
            'request_params' => $params,
            'response_status' => (int)($result['status'] ?? 0),
            'ok' => (bool)($result['ok'] ?? false),
            'response_body' => $result['body'] ?? $result,
            'user_id' => auth()->id(),
        ]);

        return redirect()->route('ext.lazada.products.edit', $listing->id)
            ->with('status', 'Sample push sent to Lazada. Review the response below (QC errors will appear there).')
            ->with('push_request_json', json_encode($preview, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
            ->with('push_response_json', json_encode($result['body'] ?? $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Lazada requires "inlink" images for product create/update.
     * If we send non-Lazada URLs, QC returns BIZ_CHECK_EXIST_OUTER_MAIN_IMAGE.
     */
    public function ensureLazadaInlinkImages(array $productPayload, $setting, LazadaClient $client): array
    {
        $creds = LazadaSetting::activeCredentials($setting);

        $images = data_get($productPayload, 'Request.Product.Images.Image', []);
        if (!is_array($images) || empty($images)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'images' => 'At least 1 product image is required. (Product.Images.Image is empty.)'
            ]);
        }

        $images = array_values(array_filter($images, fn($v) => is_string($v) && trim($v) !== ''));
        if (empty($images)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'images' => 'At least 1 product image is required. (No valid image URL found.)'
            ]);
        }

        // Lazada migration APIs support up to 8 images per call; keep it predictable.
        $images = array_slice($images, 0, 8);

        // Reject localhost/private URLs: Lazada cannot fetch them.
        foreach ($images as $u) {
            $host = strtolower((string) parse_url($u, PHP_URL_HOST));
            if ($host === '' || in_array($host, ['localhost', '127.0.0.1', '0.0.0.0'], true)) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'images' => 'Your image URLs are using a local host (e.g., localhost). Lazada cannot access these. Please set APP_URL to your public domain and ensure the images are publicly reachable.'
                ]);
            }
        }

        $region = (string) $setting->region;
        $inlinks = $this->migrateImagesToLazadaInlinks(
            $region,
            $images,
            (string) $creds['app_key'],
            (string) $creds['app_secret'],
            (string) $creds['access_token'],
            $client
        );

        // Build an original->inlink map so we can sanitize SKU-level images reliably.
        $imageMap = [];
        foreach ($images as $i => $orig) {
            $orig = is_string($orig) ? trim($orig) : '';
            $in = $inlinks[$i] ?? null;
            if ($orig !== '' && is_string($in) && trim($in) !== '') {
                $imageMap[$orig] = $in;
            }
        }

        data_set($productPayload, 'Request.Product.Images.Image', $inlinks);

        // Sanitize SKU-level images:
        // Lazada rejects *any* non-Lazada URLs at SKU Images. Option images are optional,
        // so if we can't safely migrate them, we drop them.
        $skuRows = data_get($productPayload, 'Request.Product.Skus.Sku', []);
        if (is_array($skuRows)) {
            foreach ($skuRows as $idx => $row) {
                if (!is_array($row)) {
                    continue;
                }

                $skuImgs = data_get($row, 'Images.Image');
                if (!is_array($skuImgs)) {
                    $skuImgs = [];
                }

                $skuImgs = array_values(array_filter(array_map(fn($u) => is_string($u) ? trim($u) : '', $skuImgs), fn($u) => $u !== ''));

                if (!empty($skuImgs)) {
                    $clean = [];
                    foreach ($skuImgs as $u) {
                        // Already a Lazada inlink -> keep
                        if ($this->isLazadaInlinkUrl($u)) {
                            $clean[] = $u;
                            continue;
                        }

                        // If it matches a product image we already migrated, reuse its inlink.
                        if (isset($imageMap[$u]) && $this->isLazadaInlinkUrl($imageMap[$u])) {
                            $clean[] = $imageMap[$u];
                            continue;
                        }

                        // Best-effort: migrate this SKU image. If migration fails, drop it.
                        $newUrl = $this->migrateSingleImageXml(
                            $region,
                            $u,
                            (string) $creds['app_key'],
                            (string) $creds['app_secret'],
                            (string) $creds['access_token'],
                            $client
                        );
                        if (is_string($newUrl) && $newUrl !== '' && $this->isLazadaInlinkUrl($newUrl)) {
                            $clean[] = $newUrl;
                        }
                    }

                    $clean = array_values(array_unique($clean));
                    if (!empty($clean)) {
                        $row['Images'] = ['Image' => array_slice($clean, 0, 8)];
                    } else {
                        unset($row['Images']);
                    }
                } else {
                    // If empty/invalid, remove Images key to avoid sending blanks.
                    if (isset($row['Images'])) {
                        unset($row['Images']);
                    }
                }

                $skuRows[$idx] = $row;
            }

            data_set($productPayload, 'Request.Product.Skus.Sku', $skuRows);
        }

        // SKU-level main image: force first SKU image to the first inlink.
        $first = $inlinks[0] ?? null;
        if ($first && $this->isLazadaInlinkUrl((string)$first)) {
            $sku0 = data_get($productPayload, 'Request.Product.Skus.Sku.0');
            if (is_array($sku0)) {
                $sku0['Images'] = ['Image' => [(string)$first]];
                data_set($productPayload, 'Request.Product.Skus.Sku.0', $sku0);
            }
        }

        return $productPayload;
    }

    /**
     * Determine whether a URL is a Lazada inlink (i.e., Lazada-hosted image URL).
     *
     * Lazada rejects non-Lazada URLs in SKU Images (BIZ_CHECK_EXIST_OUTER_SKU_IMAGE).
     */
    private function isLazadaInlinkUrl(string $url): bool
    {
        $url = trim($url);
        if ($url === '') return false;
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '') return false;

        // Common Lazada/CDN hosts across regions and sandbox.
        if (str_ends_with($host, 'slatic.net')) return true;
        if (str_ends_with($host, 'lazcdn.com')) return true;
        if (str_contains($host, 'lazada')) return true; // defensive

        return false;
    }

    /**
     * Convert external/public image URLs to Lazada inlinks.
     *
     * IMPORTANT: We use /image/migrate (single-image) only.
     * Lazada's product APIs do not allow non-Lazada URLs in Images, and /image/migrate
     * returns the inlink immediately (data.image.url). This avoids the batch polling
     * complexity (/images/migrate + /image/response/get) that caused repeated format/
     * method errors during testing.
     */
    private function migrateImagesToLazadaInlinks(string $region, array $imageUrls, string $appKey, string $appSecret, string $accessToken, LazadaClient $client): array
    {
    $imageUrls = array_values(array_filter(array_map(fn($u) => trim((string)$u), $imageUrls), fn($u) => $u !== ''));
    $imageUrls = array_slice($imageUrls, 0, 8);

    // Keep Lazada inlinks as-is (no need to re-migrate).
    // This also prevents us from accidentally sending an outer URL to /image/migrate.
    $already = [];
    $needsInput = [];
    foreach ($imageUrls as $u) {
        if ($this->isLazadaInlinkUrl($u)) {
            $already[$u] = $u;
        } else {
            $needsInput[] = $u;
        }
    }

    // Serve from cache first.
    $out = [];
    $needs = [];
    foreach ($needsInput as $url) {
        $hash = hash('sha256', $url);
        $cached = LazadaImageLink::query()
            ->where('region', $region)
            ->where('original_hash', $hash)
            ->value('lazada_url');
        if ($cached) {
            $out[$url] = $cached;
        } else {
            $needs[] = $url;
        }
    }

    if (!empty($needs)) {
        // Prefer batch migration first (faster for multiple images). If it fails or partially succeeds,
        // fall back to the proven single-image migrate call.
        $batchMap = $this->tryMigrateBatchImagesXml($region, $needs, $appKey, $appSecret, $accessToken, $client);

        foreach ($needs as $originalUrl) {
            $newUrl = $batchMap[$originalUrl] ?? null;
            if (!is_string($newUrl) || trim($newUrl) === '') {
                $newUrl = $this->migrateSingleImageXml($region, $originalUrl, $appKey, $appSecret, $accessToken, $client);
            }

            if (!$newUrl) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'images' => 'Failed to migrate image to Lazada inlink. Please ensure the image URL is public and reachable.'
                ]);
            }

            $hash = hash('sha256', $originalUrl);
            LazadaImageLink::query()->updateOrCreate(
                ['region' => $region, 'original_hash' => $hash],
                ['original_url' => $originalUrl, 'lazada_url' => $newUrl]
            );
            $out[$originalUrl] = $newUrl;
        }
    }

    // Return in the same order as input.
    $ordered = [];
    foreach ($imageUrls as $u) {
        if (isset($already[$u])) {
            $ordered[] = $already[$u];
            continue;
        }
        if (isset($out[$u])) $ordered[] = $out[$u];
    }

    if (empty($ordered)) {
        throw \Illuminate\Validation\ValidationException::withMessages([
            'images' => 'Unable to prepare Lazada image links. No images were migrated.'
        ]);
    }

    return $ordered;
    }

    /**
     * Migrate a single image using /image/migrate with the proven XML payload.
     * Returns Lazada inlink (data.image.url) or null on failure.
     */
    private function migrateSingleImageXml(string $region, string $url, string $appKey, string $appSecret, string $accessToken, LazadaClient $client): ?string
    {
        $apiPath = '/image/migrate';

        // Lazada API Explorer + SDK example format
        $xml = '<?xml version="1.0" encoding="UTF-8" ?>'
            . '<Request>'
            . '<Image><Url>' . htmlspecialchars($url, ENT_XML1) . '</Url></Image>'
            . '</Request>';

        $timestamp = (string) round(microtime(true) * 1000);
        $params = [
            'app_key' => $appKey,
            'sign_method' => 'sha256',
            'timestamp' => $timestamp,
            'access_token' => $accessToken,
            'payload' => $xml,
        ];

        $params['sign'] = $client->sign($apiPath, $params, $appSecret);
        $resp = $client->post($region, $apiPath, $params);
        $body = $resp['body'] ?? [];

        $inlink = data_get($body, 'data.image.url') ?? data_get($body, 'data.image.Url');
        $ok = is_string($inlink) && $inlink !== '';

        LazadaApiLog::safeCreate([
            'pack'            => 'lazada.image.migrate.single',
            'method'          => 'POST',
            'api_path'        => $apiPath,
            'auth_required'   => true,
            'request_params'  => $params,
            'response_status' => (int) ($resp['status'] ?? 0),
            'ok'              => $ok,
            'response_body'   => $body,
            'user_id'         => auth()->id(),
        ]);

        return $ok ? $inlink : null;
    }

private function tryMigrateBatchImagesXml(string $region, array $urls, string $appKey, string $appSecret, string $accessToken, LazadaClient $client): array
{
    $urls = array_values(array_slice(array_filter(array_map(fn($u) => trim((string)$u), $urls), fn($u) => $u !== ''), 0, 8));
    if (empty($urls)) return [];

    $apiPath = '/images/migrate';

    // Prepare candidate request formats.
    $candidates = [];

    // A) XML payload under "payload"
    $xml = '<?xml version="1.0" encoding="UTF-8" ?>'
        . '<Request><Images>'
        . implode('', array_map(fn($u) => '<Url>' . htmlspecialchars($u, ENT_XML1) . '</Url>', $urls))
        . '</Images></Request>';
    $candidates[] = ['payload' => $xml];

    // B) JSON payload under "payload"
    $json = json_encode(['Request' => ['Images' => ['Url' => $urls]]], JSON_UNESCAPED_SLASHES);
    if (is_string($json)) {
        $candidates[] = ['payload' => $json];
    }

    // C) Direct param list (some gateways)
    $candidates[] = ['urls' => json_encode($urls, JSON_UNESCAPED_SLASHES)];

    foreach ($candidates as $extra) {
        $timestamp = (string) round(microtime(true) * 1000);
        $params = array_merge([
            'app_key' => $appKey,
            'sign_method' => 'sha256',
            'timestamp' => $timestamp,
            'access_token' => $accessToken,
        ], $extra);
        $params['sign'] = $client->sign($apiPath, $params, $appSecret);

        $resp = $client->post($region, $apiPath, $params);
        $body = $resp['body'] ?? [];

        $batchId = data_get($body, 'batch_id')
            ?? data_get($body, 'data.batch_id')
            ?? data_get($body, 'data.batchId')
            ?? data_get($body, 'request_id')
            ?? data_get($body, 'data.request_id')
            ?? data_get($body, 'data.requestId');

        LazadaApiLog::safeCreate([
            'pack'            => 'lazada.images.migrate.batch',
            'method'          => 'POST',
            'api_path'        => $apiPath,
            'auth_required'   => true,
            'request_params'  => $params,
            'response_status' => (int) ($resp['status'] ?? 0),
            'ok'              => (bool) $batchId,
            'response_body'   => $body,
            'user_id'         => auth()->id(),
        ]);

        if (!$batchId) {
            continue;
        }

        usleep(650000);

        $apiPath2 = '/image/response/get';

        // Lazada docs often show GET, but in production some gateways are strict.
        // Try POST (form) first, then GET as fallback.
        foreach (['batch_id', 'request_id'] as $idKey) {
            $timestamp2 = (string) round(microtime(true) * 1000);
            $params2 = [
                'app_key' => $appKey,
                'sign_method' => 'sha256',
                'timestamp' => $timestamp2,
                'access_token' => $accessToken,
                $idKey => (string) $batchId,
            ];
            $params2['sign'] = $client->sign($apiPath2, $params2, $appSecret);

            $resp2 = $client->post($region, $apiPath2, $params2);
            $body2 = $resp2['body'] ?? [];

            $code2 = is_array($body2) ? (string)($body2['code'] ?? '') : '';
            if (!($resp2['ok'] ?? false) || ($code2 !== '' && $code2 !== '0')) {
                $params2['sign'] = $client->sign($apiPath2, $params2, $appSecret);
                $resp2 = $client->get($region, $apiPath2, $params2);
                $body2 = $resp2['body'] ?? [];
            }

            $items = data_get($body2, 'data.images')
                ?? data_get($body2, 'data.image')
                ?? data_get($body2, 'data')
                ?? data_get($body2, 'result')
                ?? [];

            if (isset($items['images']) && is_array($items['images'])) $items = $items['images'];
            if (isset($items['Images']) && is_array($items['Images'])) $items = $items['Images'];
            if (isset($items['image']) && is_array($items['image'])) $items = $items['image'];
            if (isset($items['Image']) && is_array($items['Image'])) $items = $items['Image'];

            $migrated = [];
            if (is_array($items)) {
                foreach ($items as $it) {
                    if (is_string($it)) {
                        $migrated[] = $it;
                        continue;
                    }
                    if (!is_array($it)) continue;

                    $migrated[] = $it['url']
                        ?? $it['Url']
                        ?? ($it['image']['url'] ?? null)
                        ?? ($it['image']['Url'] ?? null)
                        ?? $it['image']
                        ?? $it['image_url']
                        ?? null;
                }
            }

            $migrated = array_values(array_filter($migrated, fn($v) => is_string($v) && $v !== ''));

            if (count($migrated) === count($urls)) {
                $map = [];
                foreach ($urls as $i => $orig) {
                    $map[$orig] = $migrated[$i] ?? null;
                }
                return $map;
            }
        }
    }

    return array_fill_keys($urls, null);
}

    public function buildLazadaProductCreatePayload(LazadaProduct $listing, ?object $setting = null, ?LazadaClient $client = null): array
    {
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $product = DB::table($pfx.'product as p')
            ->leftJoin($pfx.'product_description as pd', function ($j) use ($langId) {
                $j->on('p.product_id', '=', 'pd.product_id')
                    ->where('pd.language_id', '=', $langId);
            })
            ->leftJoin($pfx.'manufacturer as m', 'p.manufacturer_id', '=', 'm.manufacturer_id')
            ->where('p.product_id', (int)$listing->product_id)
            ->first([
                'p.product_id','p.sku','p.model','p.image','p.price','p.quantity',
                'p.weight','p.length','p.width','p.height','p.date_modified',
                'p.upc','p.ean','p.jan','p.isbn','p.mpn',
                'pd.name','pd.description','pd.meta_title','pd.meta_description',
                'm.name as manufacturer_name',
            ]);

        // Images are mandatory for Lazada product creation.
        // Lazada checks Product.Images.Image (and sometimes Sku.Images.Image) for main image.
        $imageUrls = $this->getProductImageUrls((int)$listing->product_id, $product);
        $imageUrls = array_values(array_filter(array_map(fn($u) => $this->normalizeImageUrl((string)$u), $imageUrls)));
        if (empty($imageUrls)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'images' => 'At least 1 product image is required. Please upload a main image (and optional additional images) in the core ERP Product page first.'
            ]);
        }

        $attrs = LazadaProductAttribute::query()
            ->where('lazada_product_id', $listing->id)
            ->pluck('value', 'attribute_key')
            ->toArray();

        // Internal/legacy key - do not send to Lazada as it does not satisfy QC.
        unset($attrs['__images__']);

        // Remove present-but-null values early; Lazada treats them as blank (C004).
        $attrs = (array) $this->compactPayloadValue($attrs);

        // Ensure Brand attributes are present.
        // Lazada examples show using brand name ("No Brand") and/or brand_id for registered brands.
        // We keep this logic defensive for sandbox testing where brand libraries may be incomplete.
        $brandOverride = trim((string)($listing->brand_name_override ?? ''));
        $brandId = (int)($listing->brand_id ?? 0);

        $isNoBrand = $brandOverride !== '' && strtolower($brandOverride) === 'no brand';

        if ($isNoBrand) {
            $attrs['brand'] = 'No Brand';
            unset($attrs['brand_id']);
        } elseif ($brandId > 0) {
            $brandName = LazadaBrand::query()
                ->where('region', $this->region())
                ->where('brand_id', $brandId)
                ->value('name');

            // Provide both brand (name) + brand_id to satisfy "brand is mandatory" checks while remaining compatible.
            $attrs['brand'] = $brandName ? (string)$brandName : (string)$brandId;
            $attrs['brand_id'] = $brandId;
        } else {
            // For payload preview and smoother sandbox testing, default to Lazada's "No Brand"
            // when no brand_id is selected and the No-Brand marker isn't set.
            // Lazada commonly treats "No Brand" as a valid brand value in Seller Center.
            $attrs['brand'] = 'No Brand';
            unset($attrs['brand_id']);
        }

        // Base SKU row (no variants) OR per-option-value rows.
        // We also pull option/value names so we can map them to Lazada variation attributes.
        $povRows = DB::table($pfx.'product_option_value as pov')
            ->leftJoin($pfx.'option_description as od', function ($j) use ($langId) {
                $j->on('pov.option_id', '=', 'od.option_id')
                    ->where('od.language_id', '=', $langId);
            })
            ->leftJoin($pfx.'option_value_description as ovd', function ($j) use ($langId) {
                $j->on('pov.option_value_id', '=', 'ovd.option_value_id')
                    ->where('ovd.language_id', '=', $langId);
            })
            ->where('pov.product_id', (int)$listing->product_id)
            ->orderBy('pov.product_option_value_id')
            ->get([
                'pov.product_option_value_id',
                'pov.option_id',
                'pov.option_value_id',
                'pov.sku',
                'pov.quantity',
                'pov.absolute_price',
                'od.name as option_name',
                'ovd.name as option_value_name',
            ]);

        $map = LazadaProductVariant::query()
            ->where('lazada_product_id', $listing->id)
            ->get()
            ->keyBy(function ($v) {
                return $v->product_option_value_id === null ? 'base' : (string)$v->product_option_value_id;
            });

        // Resolve __map: attribute values to actual product field values.
        $attrs = $this->attrs()->resolveMapAttributes($attrs, $product);

        // SellerSku fallback: sku then model.
        $fallbackBaseSku = null;
        if ($product) {
            $fallbackBaseSku = trim((string)($product->sku ?? ''));
            if ($fallbackBaseSku === '') {
                $fallbackBaseSku = trim((string)($product->model ?? ''));
            }
            if ($fallbackBaseSku === '') {
                $fallbackBaseSku = null;
            }
        }

        $skus = [];
        $basePrice = (float)($product->price ?? 0);
        $usedSellerSkus = [];

        // Effective markup for variant price computation
        $effectiveFixed = $listing->markup_fixed;
        $effectivePercent = $listing->markup_percent;
        if ($effectiveFixed === null && $effectivePercent === null) {
            $mkGroup = $listing->groups()->first();
            if ($mkGroup) {
                $effectiveFixed = $mkGroup->markup_fixed;
                $effectivePercent = $mkGroup->markup_percent;
            }
        }

        // If we have ERP option rows, Lazada requires *variation attributes* (aka sale props) per SKU.
        // Otherwise, Lazada will reject multi-SKU products with CHK_SKU_PROPS_DUPLICATE.
        // Multi-option products are stored in product_option_combinations (each combo = one SKU).
        // Single-option products store variant data directly on product_option_value rows.
        // We map ERP option value(s) onto Lazada sale-prop keys for the category.
        $variantKeys = [];
        $combos = collect();
        $comboValuesByCombo = collect();
        if ($povRows->count() > 0) {
            $optionIds = $povRows->pluck('option_id')->filter()->unique()->values();

            $combos = DB::table('product_option_combinations as poc')
                ->where('poc.product_id', (int)$listing->product_id)
                ->orderBy('poc.sort_order')
                ->get();

            if ($combos->isNotEmpty()) {
                $comboIds = $combos->pluck('id')->toArray();
                $comboValuesByCombo = DB::table('product_option_combination_values as pocv')
                    ->join($pfx . 'product_option_value as pov2', 'pocv.product_option_value_id', '=', 'pov2.product_option_value_id')
                    ->join($pfx . 'option_description as od', function ($j) use ($langId) {
                        $j->on('pov2.option_id', '=', 'od.option_id')
                            ->where('od.language_id', '=', $langId);
                    })
                    ->join($pfx . 'option_value_description as ovd', function ($j) use ($langId) {
                        $j->on('pov2.option_value_id', '=', 'ovd.option_value_id')
                            ->where('ovd.language_id', '=', $langId);
                    })
                    ->whereIn('pocv.combination_id', $comboIds)
                    ->select(
                        'pocv.combination_id',
                        'pov2.option_id',
                        'od.name as option_name',
                        'ovd.name as value_name'
                    )
                    ->orderBy('pocv.combination_id')
                    ->orderBy('pov2.option_id')
                    ->get()
                    ->groupBy('combination_id');
            } elseif ($optionIds->count() > 1) {
                // Multi-option product without a combinations row — the saver should have populated them.
                // Bail rather than uploading wrong data.
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'variants' => 'This ERP product has multiple option groups but no combinations stored. Re-save the product to populate combinations, then retry.'
                ]);
            }

            $optionNamesOrdered = $combos->isNotEmpty()
                ? ($comboValuesByCombo->first() ?? collect())->pluck('option_name')->filter()->values()
                : $povRows->pluck('option_name')->filter()->unique()->values();

            // Resolve sale-prop keys, in priority order:
            //   1. Live API (/category/attributes/get) — most accurate
            //   2. Cached category template (lazada_category_templates) — survives rate limits
            //   3. Name-based guess for single-option products only
            //
            // We never invent extra keys beyond what the category actually exposes — Lazada
            // silently ignores unknown sale-prop keys, which collapses multi-SKU payloads
            // into duplicates and triggers CHK_SKU_PROPS_DUPLICATE.
            if ($setting && $client && !empty($listing->primary_category_id)) {
                $variantKeys = $this->attrs()->getLazadaSalePropKeys((int)$listing->primary_category_id, $setting, $client);
            }
            if (empty($variantKeys) && !empty($listing->primary_category_id)) {
                $variantKeys = $this->attrs()->cachedSalePropKeys((int)$listing->primary_category_id);
            }
            if (empty($variantKeys) && !$combos->isNotEmpty()) {
                $variantKeys = $this->attrs()->guessLazadaVariantKeysFromOptionName((string)($povRows->first()->option_name ?? ''));
            }
            $variantKeys = array_values(array_filter(array_unique($variantKeys), fn ($k) => trim((string)$k) !== ''));

            if ($combos->isNotEmpty() && count($variantKeys) < $optionNamesOrdered->count()) {
                $allowed = count($variantKeys);
                $have = $optionNamesOrdered->count();
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'variants' => "Lazada won't accept this product. The selected Lazada category only allows {$allowed} variation per product, but your product has {$have}. Either remove a variation, or move this listing to a Lazada category that supports {$have}."
                ]);
            }
        }
        if ($combos->isNotEmpty()) {
            // Multi-option: each combination row is one Lazada SKU.
            // Map ERP options to Lazada sale-prop slots POSITIONALLY (first ERP option
            // → first Lazada key, etc.) so the ERP variation order is preserved in
            // Lazada's UI. Semantic matching ("Color" → color_family) would put values
            // in correctly-labeled slots but flip the display order whenever Lazada's
            // sale-prop order differs from the ERP option order.
            foreach ($combos as $c) {
                $values = $comboValuesByCombo->get($c->id) ?? collect();

                $candidate = trim((string)($c->sku ?? ''));
                if ($candidate === '') {
                    $valLabel = $values->pluck('value_name')->filter()->implode(' / ');
                    $human = $valLabel !== '' ? ('Variant: ' . $valLabel) : 'Variant';
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'variants.sku' => $human . ' SKU is required before uploading to Lazada.'
                    ]);
                }
                if (isset($usedSellerSkus[$candidate])) {
                    $candidate = $candidate . '-' . (int)$c->id;
                }
                $usedSellerSkus[$candidate] = true;

                $erpPrice = (float)($c->absolute_price ?? $basePrice);
                $finalPrice = self::computeFinalPrice($erpPrice, $effectiveFixed, $effectivePercent);
                $skuRow = [
                    'SellerSku' => $candidate,
                    'price' => $finalPrice,
                    'quantity' => (int)($c->quantity ?? 0),
                ];

                /* The variation's OWN picture rides its SKU (#137, Lazada
                 * half) - the image the product CRUD stores per
                 * combination, as a public URL. ensureLazadaInlinkImages
                 * already migrates SKU images to Lazada inlinks (or drops
                 * what cannot be fetched), so this only has to hand it the
                 * URL. */
                $comboImg = trim((string)($c->image ?? ''));
                if ($comboImg !== '') {
                    $comboUrl = $this->normalizeImageUrl(asset('storage/' . ltrim($comboImg, '/')));
                    if (is_string($comboUrl) && $comboUrl !== '') {
                        $skuRow['Images'] = ['Image' => [$comboUrl]];
                    }
                }

                foreach ($values->values() as $i => $v) {
                    if (!isset($variantKeys[$i])) {
                        continue;
                    }
                    $valName = trim((string)($v->value_name ?? ''));
                    if ($valName !== '') {
                        $skuRow[$variantKeys[$i]] = $valName;
                    }
                }

                $skus[] = $skuRow;
            }
        } elseif ($povRows->count() > 0) {
            foreach ($povRows as $r) {
                $key = (string)$r->product_option_value_id;
                $m = $map->get($key);
                // SellerSku mapping rule (Option A: all variants must have SKU):
                // 1) Listing variant override
                // 2) ERP option SKU (pov.sku) - required
                // 3) (last resort) ERP base SKU/model
                $candidate = trim((string)($m && $m->seller_sku ? $m->seller_sku : ''));
                if ($candidate === '') {
                    $candidate = trim((string)($r->sku ?? ''));
                }
                if ($candidate === '') {
                    $label = trim((string)($r->option_name ?? ''));
                    $valLabel = trim((string)($r->option_value_name ?? ''));
                    $human = $label !== '' ? ($label . ($valLabel !== '' ? (': '.$valLabel) : '')) : 'Variant';
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'variants.sku' => $human . ' SKU is required before uploading to Lazada.'
                    ]);
                }
                if ($candidate === '') {
                    $candidate = $fallbackBaseSku;
                }
                // Ensure uniqueness across variant rows
                if ($candidate !== null && $candidate !== '') {
                    if (isset($usedSellerSkus[$candidate])) {
                        $candidate = $candidate . '-' . (int)$r->product_option_value_id;
                    }
                    $usedSellerSkus[$candidate] = true;
                }

                $erpPrice = (float)($r->absolute_price ?? $basePrice);
                $finalPrice = $m && $m->price !== null ? (float)$m->price : (float)$erpPrice;
                // Apply markup to variant price
                $finalPrice = self::computeFinalPrice($finalPrice, $effectiveFixed, $effectivePercent);
                $skuRow = [
                    'SellerSku' => $candidate,
                    'price' => $finalPrice,
                    'quantity' => $m && $m->quantity !== null ? (int)$m->quantity : (int)$r->quantity,
                ];

                // Variation attribute mapping (1 or 2 keys) so each SKU is distinguishable.
                // Lazada expects the *attribute key* as the XML/JSON key at SKU level (e.g., color_family, size, etc.).
                $optVal = trim((string)($r->option_value_name ?? ''));
                if ($optVal !== '' && !empty($variantKeys)) {
                    // Single option group -> we only need the first key.
                    $skuRow[$variantKeys[0]] = $optVal;
                }

                $skus[] = $skuRow;
            }
        } else {
            // No variants in ERP; create a single base SKU.
            // Use stored total_price (base + markup) if available.
            $singlePrice = $listing->total_price ?? (float)($product->price ?? 0);
            $skus[] = [
                'SellerSku' => $fallbackBaseSku,
                'price' => (float)$singlePrice,
                'quantity' => (int)($product->quantity ?? 0),
            ];
        }

        // Move SKU-level keys out of Attributes into each SKU row.
        // Lazada QC expects these at SKU level; sending them under Attributes can be treated as "blank".
        $skuDefaults = [];
        foreach (array_keys($attrs) as $k) {
            if ($this->attrs()->isSkuLevelRequiredKey($k)) {
                $val = $attrs[$k];
                // Keep only meaningful values.
                if ($val !== null && trim((string)$val) !== '') {
                    $skuDefaults[$k] = $val;
                }
                unset($attrs[$k]);
            }
        }

        // If packaging defaults are still missing, try ERP core fields.
        if (!array_key_exists('package_weight', $skuDefaults) && $product && $product->weight !== null) {
            $w = (string)$product->weight;
            if (trim($w) !== '') $skuDefaults['package_weight'] = $w;
        }
        if (!array_key_exists('package_length', $skuDefaults) && $product && $product->length !== null) {
            $v = (string)$product->length;
            if (trim($v) !== '') $skuDefaults['package_length'] = $v;
        }
        if (!array_key_exists('package_width', $skuDefaults) && $product && $product->width !== null) {
            $v = (string)$product->width;
            if (trim($v) !== '') $skuDefaults['package_width'] = $v;
        }
        if (!array_key_exists('package_height', $skuDefaults) && $product && $product->height !== null) {
            $v = (string)$product->height;
            if (trim($v) !== '') $skuDefaults['package_height'] = $v;
        }

        if (!empty($skuDefaults)) {
            foreach ($skus as $i => $row) {
                foreach ($skuDefaults as $k => $v) {
                    if (!array_key_exists($k, $row) || $row[$k] === null || trim((string)$row[$k]) === '') {
                        $row[$k] = $v;
                    }
                }
                $skus[$i] = $row;
            }
        }

        // What the catalogue can answer, it answers, BEFORE the gate below:
        // this fallback used to sit after it, where it could never fire.
        $attrs = $this->attrs()->autoFillBasics($attrs, $product);

        // Local mandatory checks (attribute + sku level) before calling Lazada.
        $requiredKeys = [];
        $requiredNames = [];
        $template = LazadaCategoryTemplate::query()
            ->where('region', $this->region())
            ->where('primary_category_id', (int)$listing->primary_category_id)
            ->first();
        if ($template && $template->template_body) {
            $templateAttrs = $this->attrs()->extractAttributes($template->template_body);

            foreach ($templateAttrs as $a) {
                if (!empty($a['required'])) {
                    $k = (string)($a['key'] ?? '');
                    if ($k !== '') {
                        $requiredKeys[] = $k;
                        $requiredNames[$k] = (string)($a['name'] ?? $k);
                    }
                }
            }
        }

        $errs = [];
        foreach ($requiredKeys as $k) {
            if ($this->attrs()->isSkuLevelRequiredKey($k)) {
                continue;
            }
            $val = $attrs[$k] ?? null;
            if ($val === null || trim((string)$val) === '') {
                $label = $requiredNames[$k] ?? $k;
                $errs['attributes.'.$k] = $label . ' is mandatory.';
            }
        }
        if (!empty($errs)) {
            throw \Illuminate\Validation\ValidationException::withMessages($errs);
        }

        // SKU-level mandatory
        $needsSellerSku = false;
        $needsPrice = false;
        foreach ($requiredKeys as $k) {
            $lk = strtolower((string)$k);
            if ($lk === 'sellersku' || $lk === 'seller_sku') $needsSellerSku = true;
            if ($lk === 'price') $needsPrice = true;
        }
        if ($needsSellerSku) {
            foreach ($skus as $s) {
                if (empty($s['SellerSku'])) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'variants' => 'SellerSku is mandatory. Please set SKU in ERP or in Variant Mapping overrides.'
                    ]);
                }
            }
        }
        if ($needsPrice) {
            foreach ($skus as $s) {
                if (!isset($s['price']) || (float)$s['price'] <= 0) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'variants' => 'Price is mandatory. Please set product price in ERP (base price) and/or option price adjustments, or fill Variant Mapping overrides.'
                    ]);
                }
            }
        }

        // Lazada payload (best-effort). We'll adjust based on QC responses.
        // IMPORTANT: Do NOT send present-but-null attributes; Lazada treats them as "blank" (C004).
        // (name/description used to be re-filled here, after the gate; that
        // now happens once in autoFillBasics(), before it.)
        $attrFinal = is_array($attrs) ? $attrs : [];

        // Clean null/blank values before sending to Lazada.
        $attrFinal = (array) $this->compactPayloadValue($attrFinal);

        $payload = [
            'Request' => [
                'Product' => [
                    'PrimaryCategory' => (int)$listing->primary_category_id,
                    'Images' => [
                        'Image' => $imageUrls,
                    ],
                    'Attributes' => $attrFinal,
                    'Skus' => [
                        'Sku' => $skus,
                    ],
                ],
            ],
        ];

        // Some categories validate the main image at SKU level. Apply the first image as the main SKU image.
        if (!empty($imageUrls)) {
            foreach ($payload['Request']['Product']['Skus']['Sku'] as $idx => $row) {
                if (!isset($payload['Request']['Product']['Skus']['Sku'][$idx]['Images'])) {
                    $payload['Request']['Product']['Skus']['Sku'][$idx]['Images'] = ['Image' => [$imageUrls[0]]];
                }
            }
        }

        $preview = [
            'listing_id' => $listing->id,
            'primary_category_id' => $listing->primary_category_id,
            'attributes' => $attrs,
            'skus' => $skus,
            'images' => $imageUrls,
            'payload' => $payload,
        ];

        return [$payload, $preview];
    }

    /**
     * Remove null/blank values recursively from an array.
     *
     * Lazada treats present-but-null fields as "blank" and may fail QC (C004).
     * We only remove: null, empty strings, and empty arrays. We keep numeric 0 and string "0".
     *
     *  mixed $value
     *  mixed
     */
    private function compactPayloadValue($value)
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $v2 = $this->compactPayloadValue($v);
                if (is_array($v2) && empty($v2)) {
                    continue;
                }
                if ($v2 === null) {
                    continue;
                }
                if (is_string($v2) && trim($v2) === '') {
                    continue;
                }
                $out[$k] = $v2;
            }
            return $out;
        }
        return $value;
    }

    private function region(): string
    {
        $setting = LazadaSetting::query()->first();
        return (string)($setting->region ?? '');
    }

    // ── Unmatched Items: Sync / Link / Dismiss ─────────────────────

    public function syncUnmatchedItems(LazadaClient $client)
    {
        $setting = LazadaSetting::query()->first()?->decrypted();
        $creds = LazadaSetting::activeCredentials($setting);
        if (!$setting || !$setting->region || !$creds['app_key'] || !$creds['app_secret'] || !$creds['access_token']) {
            return redirect()->route('ext.lazada.products.index')
                ->with('status', 'Missing Lazada settings.');
        }

        // Fetch all Lazada products
        $skuMap = $this->fetchLazadaSkuMap($setting, $client);

        // Build a reverse map: item_id => [[sku, ...]]
        $itemSkus = [];
        foreach ($skuMap as $sku => $itemIds) {
            foreach ($itemIds as $itemId) {
                $itemSkus[$itemId][] = $sku;
            }
        }

        // We also need item names/images. Fetch all products again briefly
        $allProducts = [];
        $offset = 0;
        $limit = 50;
        $maxPages = 60;
        $page = 0;

        do {
            $apiPath = '/products/get';
            $timestamp = (string) round(microtime(true) * 1000);
            $params = [
                'app_key' => (string) $creds['app_key'],
                'sign_method' => 'sha256',
                'timestamp' => $timestamp,
                'access_token' => (string) $creds['access_token'],
                'filter' => 'all',
                'offset' => (string) $offset,
                'limit' => (string) $limit,
            ];
            $params['sign'] = $client->sign($apiPath, $params, (string) $creds['app_secret']);

            if ($page > 0) usleep(500000);
            $result = $client->get((string) $setting->region, $apiPath, $params);
            if (empty($result['ok'])) break;

            $body = $result['body'] ?? [];
            $data = $body['data'] ?? [];
            $products = $data['products'] ?? [];
            $totalProducts = (int) ($data['total_products'] ?? 0);

            foreach ($products as $p) {
                $itemId = $p['item_id'] ?? ($p['itemId'] ?? null);
                if ($itemId === null) continue;
                $allProducts[(string) $itemId] = $p;
            }

            $offset += $limit;
            $page++;
        } while ($offset < $totalProducts && $page < $maxPages);

        // Get all ERP-linked lazada_products item_ids
        $linkedItemIds = LazadaProduct::query()
            ->whereNotNull('lazada_item_id')
            ->where('lazada_item_id', '!=', '')
            ->pluck('lazada_item_id')
            ->map(fn($v) => (string) $v)
            ->unique()
            ->all();

        // Also try to match by SKU to existing lazada_products
        $pfx = (string) config('catalog.prefix');
        $existingSkus = [];
        $lazProducts = LazadaProduct::query()->get(['id', 'product_id']);
        foreach ($lazProducts as $lp) {
            $pid = (int) $lp->product_id;
            // Get main model SKU
            $mainSku = DB::table($pfx . 'product')->where('product_id', $pid)->value('model');
            if ($mainSku) $existingSkus[trim((string) $mainSku)] = (string) ($lp->lazada_item_id ?? '');
            // Get option value SKUs
            $ovSkus = DB::table($pfx . 'product_option_value')
                ->where('product_id', $pid)
                ->whereNotNull('sku')
                ->where('sku', '!=', '')
                ->pluck('sku');
            foreach ($ovSkus as $s) {
                $existingSkus[trim((string) $s)] = (string) ($lp->lazada_item_id ?? '');
            }
        }

        // Clear old unmatched entries
        \Extensions\lazada\Models\LazadaUnmatchedItem::query()->where('status', 'unmatched')->delete();

        $unmatchedCount = 0;
        $matchedCount = 0;

        foreach ($allProducts as $itemId => $product) {
            // Skip if already linked in lazada_products
            if (in_array((string) $itemId, $linkedItemIds, true)) {
                $matchedCount++;
                continue;
            }

            // Check if any SKU for this item matches an existing ERP product
            $skusForItem = $itemSkus[(string) $itemId] ?? [];
            $found = false;
            foreach ($skusForItem as $sku) {
                if (isset($existingSkus[$sku])) {
                    $found = true;
                    break;
                }
            }
            if ($found) {
                $matchedCount++;
                continue;
            }

            // Get product info
            $itemName = $product['attributes']['name'] ?? ($product['name'] ?? 'Unknown');
            $imageUrl = null;
            $images = $product['images'] ?? ($product['skus'][0]['Images'] ?? []);
            if (is_array($images) && !empty($images)) {
                $imageUrl = is_string($images[0] ?? null) ? $images[0] : null;
            }

            // Store each SKU variant as an unmatched entry
            $skus = $product['skus'] ?? [];
            if (empty($skus)) {
                // No SKU info at all
                $unmatchedCount++;
                \Extensions\lazada\Models\LazadaUnmatchedItem::updateOrCreate(
                    ['lazada_item_id' => (string) $itemId, 'sku' => ''],
                    [
                        'lazada_sku_id' => null,
                        'item_name' => $itemName,
                        'image_url' => $imageUrl,
                        'raw_data' => $product,
                        'status' => 'unmatched',
                        'linked_product_id' => null,
                    ]
                );
            } else {
                foreach ($skus as $skuData) {
                    $sellerSku = trim((string) ($skuData['SellerSku'] ?? ($skuData['seller_sku'] ?? ($skuData['SellerSKU'] ?? ''))));
                    $skuId = $skuData['SkuId'] ?? ($skuData['skuId'] ?? ($skuData['ShopSku'] ?? null));
                    $skuImage = $skuData['Images'][0] ?? $imageUrl;

                    $unmatchedCount++;
                    \Extensions\lazada\Models\LazadaUnmatchedItem::updateOrCreate(
                        ['lazada_item_id' => (string) $itemId, 'sku' => $sellerSku],
                        [
                            'lazada_sku_id' => $skuId ? (string) $skuId : null,
                            'item_name' => $itemName . ($sellerSku ? ' [' . $sellerSku . ']' : ''),
                            'image_url' => $skuImage,
                            'raw_data' => array_merge($product, ['_sku' => $skuData]),
                            'status' => 'unmatched',
                            'linked_product_id' => null,
                        ]
                    );
                }
            }
        }

        return redirect()->route('ext.lazada.products.index')
            ->with('status', "Unmatched sync complete. Lazada items: " . count($allProducts) . ". Already matched: {$matchedCount}. Unmatched: {$unmatchedCount}.");
    }

    public function linkUnmatchedItem(Request $request, int $unmatchedId)
    {
        $request->validate(['product_id' => 'required|integer|min:1']);

        $item = \Extensions\lazada\Models\LazadaUnmatchedItem::findOrFail($unmatchedId);
        $productId = (int) $request->input('product_id');

        $pfx = (string) config('catalog.prefix');
        $product = DB::table($pfx . 'product')->where('product_id', $productId)->first(['product_id']);
        if (!$product) {
            return redirect()->route('ext.lazada.products.index')->with('status', 'Catalog product not found.');
        }

        // Check if a LazadaProduct listing already exists for this product
        $listing = LazadaProduct::query()->where('product_id', $productId)->first();
        if ($listing) {
            // Update with the Lazada item_id
            if (empty($listing->lazada_item_id)) {
                $listing->lazada_item_id = $item->lazada_item_id;
                $listing->lazada_deleted_at = null;
                $listing->unlinked_at = null;
                $listing->save();
            }
        } else {
            // Create a new minimal listing
            LazadaProduct::create([
                'product_id' => $productId,
                'lazada_item_id' => $item->lazada_item_id,
            ]);
        }

        $item->update(['status' => 'linked', 'linked_product_id' => $productId]);

        return redirect()->route('ext.lazada.products.index')
            ->with('status', 'Linked "' . ($item->item_name ?? 'Unknown') . '" to product #' . $productId . '.');
    }

    public function dismissUnmatchedItem(int $unmatchedId)
    {
        $item = \Extensions\lazada\Models\LazadaUnmatchedItem::findOrFail($unmatchedId);
        $item->update(['status' => 'dismissed']);

        return redirect()->route('ext.lazada.products.index')
            ->with('status', 'Dismissed "' . ($item->item_name ?? 'Unknown') . '".');
    }

    public function searchCatalogProducts(Request $request)
    {
        $q = trim((string) $request->get('q', ''));
        if ($q === '' || strlen($q) < 2) {
            return response()->json([]);
        }

        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        // Find product IDs matching by option value SKU
        $optionMatchIds = DB::table($pfx . 'product_option_value')
            ->where('sku', 'like', '%' . $q . '%')
            ->whereNotNull('sku')
            ->where('sku', '!=', '')
            ->pluck('product_id')
            ->unique()
            ->toArray();

        $results = DB::table($pfx . 'product as p')
            ->leftJoin($pfx . 'product_description as pd', function ($j) use ($langId) {
                $j->on('p.product_id', '=', 'pd.product_id')
                  ->where('pd.language_id', '=', $langId);
            })
            ->where(function ($sub) use ($q, $optionMatchIds) {
                $sub->where('pd.name', 'like', '%' . $q . '%')
                    ->orWhere('p.model', 'like', '%' . $q . '%')
                    ->orWhere('p.sku', 'like', '%' . $q . '%');
                if (!empty($optionMatchIds)) {
                    $sub->orWhereIn('p.product_id', $optionMatchIds);
                }
            })
            ->limit(20)
            ->get(['p.product_id', 'pd.name', 'p.model as sku', 'p.image']);

        // Fetch option values for these products
        $productIds = $results->pluck('product_id')->map(fn($v) => (int) $v)->all();
        $optionValues = collect();
        if (!empty($productIds)) {
            $optionValues = DB::table($pfx . 'product_option_value as pov')
                ->leftJoin($pfx . 'option_value_description as ovd', function ($j) use ($langId) {
                    $j->on('pov.option_value_id', '=', 'ovd.option_value_id')
                      ->where('ovd.language_id', '=', $langId);
                })
                ->whereIn('pov.product_id', $productIds)
                ->whereNotNull('pov.sku')
                ->where('pov.sku', '!=', '')
                ->get(['pov.product_id', 'pov.sku', 'pov.quantity', 'ovd.name as value_name'])
                ->groupBy('product_id');
        }

        return response()->json($results->map(function ($r) use ($optionValues) {
            $opts = $optionValues->get((int) $r->product_id, collect());
            $optionList = $opts->map(function ($ov) {
                return [
                    'sku' => $ov->sku,
                    'name' => $ov->value_name ?? '',
                    'qty' => (int) $ov->quantity,
                ];
            })->values()->toArray();

            return [
                'product_id' => (int) $r->product_id,
                'name' => html_entity_decode($r->name ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'sku' => $r->sku ?? '',
                'image' => $r->image ? asset('storage/' . ltrim($r->image, '/')) : null,
                'options' => $optionList,
            ];
        }));
    }

    /**
     * Build XML payload for /product/stock/sellable/update.
     * Lazada expects an XML string under the 'payload' param.
     */
    private function buildStockSellableUpdateXml(string $sellerSku, int $sellableQty): string
    {
        $sellerSku = htmlspecialchars($sellerSku, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $sellableQty = max(0, $sellableQty);

        return '<Request>'
            . '<Product><Skus><Sku>'
            . '<SellerSku>' . $sellerSku . '</SellerSku>'
            . '<SellableQuantity>' . $sellableQty . '</SellableQuantity>'
            . '</Sku></Skus></Product>'
            . '</Request>';
    }

    /**
     * Build XML payload for /product/price_quantity/update (quantity only, using SkuId).
     */
    private function buildQuantityUpdateXml(int $skuId, int $quantity): string
    {
        $quantity = max(0, $quantity);

        return '<Request>'
            . '<Product><Skus><Sku>'
            . '<SkuId>' . $skuId . '</SkuId>'
            . '<Quantity>' . $quantity . '</Quantity>'
            . '</Sku></Skus></Product>'
            . '</Request>';
    }

    /**
     * Build XML payload for /product/price_quantity/update (price only, using SkuId).
     */
    private function buildPriceUpdateXml(int $skuId, string $price, ?string $salePrice = null, ?string $saleStart = null, ?string $saleEnd = null): string
    {
        $price = htmlspecialchars($price, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        $sale = '';
        if ($salePrice !== null) {
            // SalePrice 0 clears a special price; with dates it schedules
            // one - Lazada's own "Edit Special Price" wire.
            $sale = '<SalePrice>' . htmlspecialchars($salePrice, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</SalePrice>';
            if ($saleStart !== null) {
                $sale .= '<SaleStartDate>' . htmlspecialchars($saleStart, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</SaleStartDate>';
            }
            if ($saleEnd !== null) {
                $sale .= '<SaleEndDate>' . htmlspecialchars($saleEnd, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</SaleEndDate>';
            }
        }

        return '<Request>'
            . '<Product><Skus><Sku>'
            . '<SkuId>' . $skuId . '</SkuId>'
            . '<Price>' . $price . '</Price>'
            . $sale
            . '</Sku></Skus></Product>'
            . '</Request>';
    }

    /**
     * Build XML payload for /product/remove.
     * Accepts up to 50 seller skus.
     */
    private function buildRemoveProductXml(array $sellerSkus): string
    {
        $sellerSkus = array_values(array_filter(array_map(function ($s) {
            $s = trim((string)$s);
            return $s === '' ? null : $s;
        }, $sellerSkus)));

        $sellerSkus = array_slice($sellerSkus, 0, 50);

        $xml = '<Request><Product><Skus><Sku>';
        foreach ($sellerSkus as $sku) {
            $skuEsc = htmlspecialchars($sku, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $xml .= '<SellerSku>' . $skuEsc . '</SellerSku>';
        }
        $xml .= '</Sku></Skus></Product></Request>';

        return $xml;
    }

    /**
     * Provide a short status line for the UI.
     */
    private function formatLazadaResultMessage(string $actionLabel, array $result): string
    {
        $body = $result['body'] ?? null;
        $code = '';
        $msg = '';

        if (is_array($body)) {
            $code = (string)($body['code'] ?? $body['Code'] ?? $body['error_code'] ?? '');
            $msg = (string)($body['message'] ?? $body['Message'] ?? $body['error_message'] ?? '');
        } elseif (is_string($body)) {
            $msg = $body;
        }

        $status = (int)($result['status'] ?? 0);
        $ok = (bool)($result['ok'] ?? false);

        $suffix = trim(($code !== '' ? $code . ' - ' : '') . $msg);
        if ($suffix === '') {
            $suffix = 'No response body.';
        }

        return $actionLabel . ' request sent. HTTP ' . $status . ' • ' . ($ok ? 'OK' : 'ERROR') . ' • ' . $suffix;
    }


    /**
     * Extract Lazada item_id from /products/get result by verifying exact SKU match.
     *
     * Returns array [$itemIdOrNull, $matchCount].
     * If multiple items match the same SKU, returns [null, >1] to prevent wrong linking.
     */
    private function extractItemIdFromProductsGetResult(array $result, string $sku): array
    {
        $sku = trim($sku);
        if ($sku === '') {
            return [null, 0];
        }

        $body = $result['body'] ?? null;
        if (!is_array($body)) {
            return [null, 0];
        }

        $data = $body['data'] ?? null;
        if (!is_array($data)) {
            return [null, 0];
        }

        $products = $data['products'] ?? null;
        if (!is_array($products) || count($products) === 0) {
            return [null, 0];
        }

        $matchedItemIds = [];

        foreach ($products as $p) {
            if (!is_array($p)) {
                continue;
            }

            $itemId = $p['item_id'] ?? ($p['itemId'] ?? null);
            if ($itemId === null || $itemId === '') {
                continue;
            }

            $skus = $p['skus'] ?? null;
            if (!is_array($skus)) {
                continue;
            }

            foreach ($skus as $s) {
                if (!is_array($s)) {
                    continue;
                }

                $sellerSku = $s['SellerSku'] ?? ($s['seller_sku'] ?? ($s['SellerSKU'] ?? null));
                if ($sellerSku === null) {
                    continue;
                }

                if (trim((string) $sellerSku) === $sku) {
                    $matchedItemIds[(string) $itemId] = true;
                    break; // no need to scan other SKUs for this product
                }
            }
        }

        $ids = array_keys($matchedItemIds);
        $count = count($ids);

        if ($count === 1) {
            return [$ids[0], 1];
        }

        return [null, $count];
    }

    /**
     * Search Lazada for a specific SKU.
     * First tries the 'search' param (fast, 1 API call). If no match, falls back to
     * paginated scan (needed for variant SellerSKUs that 'search' doesn't cover).
     * Returns [$itemId, $matchCount].
     */
    private function findLazadaItemBySku(object $setting, LazadaClient $client, string $sku): array
    {
        $sku = trim($sku);
        if ($sku === '') return [null, 0];

        // Attempt 1: Use 'search' parameter (fast — searches product name + SellerSku)
        $result = $this->lazadaProductsGet($setting, $client, ['search' => $sku, 'limit' => '100', 'offset' => '0']);
        if (!empty($result)) {
            $matched = $this->matchSkuInProducts($result, $sku);
            if ($matched[1] > 0) return $matched;
        }

        // Attempt 2: Use 'sku_seller_list' parameter (may work on some API versions)
        $result = $this->lazadaProductsGet($setting, $client, ['sku_seller_list' => json_encode([$sku], JSON_UNESCAPED_SLASHES), 'limit' => '100', 'offset' => '0']);
        if (!empty($result)) {
            $matched = $this->matchSkuInProducts($result, $sku);
            if ($matched[1] > 0) return $matched;
        }

        // Attempt 3: Paginated full scan (fallback for variant SKUs not covered by search)
        $offset = 0;
        $limit = 50;
        $maxPages = 20;
        $page = 0;

        do {
            $products = $this->lazadaProductsGet($setting, $client, ['limit' => (string) $limit, 'offset' => (string) $offset]);
            if (empty($products)) break;

            $totalProducts = $products['_total'] ?? 0;

            $matched = $this->matchSkuInProducts($products, $sku);
            if ($matched[1] > 0) return $matched;

            $offset += $limit;
            $page++;
        } while ($offset < $totalProducts && $page < $maxPages);

        return [null, 0];
    }

    /**
     * Call /products/get with given extra params. Returns ['products' => [...], '_total' => N] or empty array.
     */
    private function lazadaProductsGet(object $setting, LazadaClient $client, array $extra = []): array
    {
        $creds = LazadaSetting::activeCredentials($setting);

        $apiPath = '/products/get';
        $timestamp = (string) round(microtime(true) * 1000);
        $params = array_merge([
            'app_key' => (string) $creds['app_key'],
            'sign_method' => 'sha256',
            'timestamp' => $timestamp,
            'access_token' => (string) $creds['access_token'],
            'filter' => 'all',
        ], $extra);
        $params['sign'] = $client->sign($apiPath, $params, (string) $creds['app_secret']);

        $result = $client->get((string) $setting->region, $apiPath, $params);
        if (empty($result['ok'])) return [];

        $body = $result['body'] ?? [];
        $data = $body['data'] ?? [];
        return [
            'products' => $data['products'] ?? [],
            '_total' => (int) ($data['total_products'] ?? 0),
        ];
    }

    /**
     * Scan products array for exact SellerSku match. Returns [$itemId, $matchCount].
     */
    private function matchSkuInProducts(array $data, string $sku): array
    {
        $matchedItemIds = [];
        foreach ($data['products'] ?? [] as $p) {
            $itemId = $p['item_id'] ?? ($p['itemId'] ?? null);
            if ($itemId === null || $itemId === '') continue;

            foreach ($p['skus'] ?? [] as $s) {
                $sellerSku = $s['SellerSku'] ?? ($s['seller_sku'] ?? ($s['SellerSKU'] ?? null));
                if ($sellerSku !== null && strcasecmp(trim((string) $sellerSku), $sku) === 0) {
                    $matchedItemIds[(string) $itemId] = true;
                }
            }
        }

        $ids = array_keys($matchedItemIds);
        if (count($ids) === 1) return [$ids[0], 1];
        if (count($ids) > 1) return [null, count($ids)];
        return [null, 0];
    }

    /**
     * Fetch ALL Lazada products (paginated) and build a SellerSku → [item_id, ...] map.
     * Used by bulk sync to avoid per-product API calls and work around unreliable seller_sku filter.
     */
    private function fetchLazadaSkuMap(object $setting, LazadaClient $client): array
    {
        $creds = LazadaSetting::activeCredentials($setting);

        $skuMap = []; // SellerSku => [item_id, ...]
        $offset = 0;
        $limit = 50;
        $maxPages = 60; // safety: 60 pages × 50 = 3,000 products max
        $page = 0;

        do {
            $apiPath = '/products/get';
            $timestamp = (string) round(microtime(true) * 1000);
            $params = [
                'app_key' => (string) $creds['app_key'],
                'sign_method' => 'sha256',
                'timestamp' => $timestamp,
                'access_token' => (string) $creds['access_token'],
                'filter' => 'all',
                'offset' => (string) $offset,
                'limit' => (string) $limit,
            ];
            $params['sign'] = $client->sign($apiPath, $params, (string) $creds['app_secret']);

            // Retry up to 3 times with increasing delay for rate limiting
            $result = null;
            for ($attempt = 1; $attempt <= 3; $attempt++) {
                if ($page > 0 || $attempt > 1) {
                    usleep(500000); // 0.5s delay between pages / retries
                }
                $timestamp = (string) round(microtime(true) * 1000);
                $params['timestamp'] = $timestamp;
                $params['sign'] = $client->sign($apiPath, $params, (string) $creds['app_secret']);
                $result = $client->get((string) $setting->region, $apiPath, $params);
                if (!empty($result['ok'])) break;
                Log::warning('Lazada fetchLazadaSkuMap API error', ['offset' => $offset, 'attempt' => $attempt, 'result' => $result['body'] ?? '']);
                if ($attempt < 3) usleep($attempt * 1000000); // 1s, 2s backoff
            }

            if (empty($result['ok'])) {
                break;
            }

            $body = $result['body'] ?? [];
            $data = $body['data'] ?? [];
            $products = $data['products'] ?? [];
            $totalProducts = (int) ($data['total_products'] ?? 0);

            foreach ($products as $p) {
                $itemId = $p['item_id'] ?? ($p['itemId'] ?? null);
                if ($itemId === null || $itemId === '') continue;

                $skus = $p['skus'] ?? [];
                foreach ($skus as $s) {
                    $sellerSku = $s['SellerSku'] ?? ($s['seller_sku'] ?? ($s['SellerSKU'] ?? null));
                    if ($sellerSku === null) continue;
                    $sellerSku = trim((string) $sellerSku);
                    if ($sellerSku === '') continue;

                    $skuMap[$sellerSku][] = (string) $itemId;
                }
            }

            $offset += $limit;
            $page++;
        } while ($offset < $totalProducts && $page < $maxPages);

        // Deduplicate item IDs per SKU
        foreach ($skuMap as $sku => $itemIds) {
            $skuMap[$sku] = array_values(array_unique($itemIds));
        }

        LazadaApiLog::safeCreate([
            'pack' => 'lazada.products.get.sku_map',
            'method' => 'GET',
            'api_path' => '/products/get',
            'auth_required' => true,
            'request_params' => ['pages_fetched' => $page, 'total_products' => $totalProducts ?? 0],
            'response_status' => 200,
            'ok' => true,
            'response_body' => ['skus_mapped' => count($skuMap)],
            'user_id' => auth()->id(),
        ]);

        return $skuMap;
    }

    /**
     * For ERP products with options, return variant SellerSku + quantity from product_option_value.
     * If a row has empty sku, it is ignored (can't sync to Lazada without SellerSku).
     */
    public function getErpVariantStockByProductId(int $productId): array
    {
        $pfx = (string) config('catalog.prefix');

        $rows = DB::table($pfx.'product_option_value as pov')
            ->where('pov.product_id', $productId)
            ->whereNotNull('pov.sku')
            ->where('pov.sku', '!=', '')
            ->orderBy('pov.product_option_value_id')
            ->get(['pov.sku', 'pov.quantity']);

        $out = [];
        foreach ($rows as $r) {
            $sku = trim((string) ($r->sku ?? ''));
            if ($sku === '') {
                continue;
            }
            $out[] = [
                'seller_sku' => $sku,
                'quantity' => max(0, (int) ($r->quantity ?? 0)),
            ];
        }
        return $out;
    }

    /**
     * For ERP products with options, get variant prices from absolute_price column.
     */
    private function getErpVariantPricesByProductId(int $productId, float $basePrice): array
    {
        $pfx = (string) config('catalog.prefix');

        $rows = DB::table($pfx.'product_option_value as pov')
            ->where('pov.product_id', $productId)
            ->whereNotNull('pov.sku')
            ->where('pov.sku', '!=', '')
            ->orderBy('pov.product_option_value_id')
            ->get(['pov.sku', 'pov.absolute_price']);

        $out = [];
        foreach ($rows as $r) {
            $sku = trim((string) ($r->sku ?? ''));
            if ($sku === '') {
                continue;
            }

            $out[] = [
                'seller_sku' => $sku,
                'price' => max(0, (float) ($r->absolute_price ?? $basePrice)),
            ];
        }

        return $out;
    }

}
