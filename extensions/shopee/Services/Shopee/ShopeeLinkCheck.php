<?php

namespace Extensions\shopee\Services\Shopee;

use Extensions\shopee\Models\ShopeeApiLog;
use Extensions\shopee\Models\ShopeeListing;
use Extensions\shopee\Models\ShopeeProductLink;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ask Shopee about these products and make the ERP agree (Phase F,
 * #184). Extracted whole from the group page - the SKU index, the
 * existence memo, the reconcile and the batch sweep - so the workbench
 * runs the same check without owning a copy. Memos live for the life of
 * the instance, which is one request: the group page's send and orphan
 * views share them by sharing the instance.
 *
 * Reads only. It confirms, adopts, repoints or drops LINKS; it writes
 * nothing to Shopee.
 */
class ShopeeLinkCheck
{
    /** Memoised shop-wide SKU index; see skuIndex(). */
    /* MEMOISED PER SHOP, never per process.
     *
     * One instance serves a whole batch so the shop is paged once instead
     * of once per product - and on 2026-09-09 that instance became scoped
     * to the request, which made it serve SEVERAL SHOPS. The scheduled
     * refresh walks every enabled store in one process, so store two was
     * searched against store one's index, found nothing, judged the shop
     * completely read, and DROPPED EVERY GOOD LINK IT HAD. The owner watched
     * two links vanish with nothing touched.
     *
     * Keyed by shop id, both of them, so no answer about one shop can ever
     * be read as an answer about another. */
    private array $shopeeSkuIndex = [];

    /** item_id => 'yes'|'no', filled by the batch prefill and single checks. */
    /** @var array<int, array<int, string>> shop id => item id => yes|no */
    private array $shopeeItemExistence = [];

    /**
     * The next page of this store's links, checked (the owner, 2026-09-07).
     * Both the Refresh button and the scheduled refresh call this, which is
     * what stops them from drifting apart: the cron used to re-read statuses
     * and never re-run the check, so a failed check could not clear itself
     * and a row kept a six-month-old refusal until somebody pressed the
     * button. The cursor caps a run at two hundred and continues where the
     * last one stopped.
     *
     * @return ?array the check's own result, or null when nothing is linked
     */
    public function runNext(ShopeeClient $client, array $auth, int $storeId): ?array
    {
        return ($ids = $this->storeProductIds($storeId)) !== []
            ? $this->run($client, $auth, \App\Integrations\Support\LinkCheckCursor::rotate('shopee:' . $storeId, $ids))
            : null;
    }

    /**
     * EVERY product this store carries, not only the ones already linked.
     *
     * The sweep walked the linked rows alone, which meant it could confirm
     * a link and could never find one. An item created on Shopee whose
     * link was then lost - a store deleted here, a database restored, a
     * delete that answered ok while the item stayed up - was invisible to
     * every automatic path, and the untargeted Check answered "Nothing is
     * linked to Shopee yet, so there is nothing to check" over a shop
     * holding thirty-two items (the owner's QA, 2026-09-09). The product is
     * the unit of the question, so the product list is what it walks; a
     * product genuinely not on Shopee reads 'new' and nothing is written.
     *
     * @return list<int>
     */
    public function storeProductIds(int $storeId): array
    {
        $listings = \Extensions\shopee\Models\ShopeeListing::query()
            ->withoutGlobalScope('shopeeStore')->where('shopee_setting_id', $storeId)
            ->pluck('product_id');

        return \Extensions\shopee\Models\ShopeeProductLink::query()
            ->withoutGlobalScope('shopeeStore')->where('shopee_setting_id', $storeId)
            ->whereNotNull('shopee_item_id')->pluck('product_id')
            ->merge($listings)
            ->map(fn ($v) => (int) $v)->filter()->unique()->sort()->values()->all();
    }

    /** @return array{tone: 'status'|'warning'|'error', summary: string} */
    public function run(ShopeeClient $client, array $auth, array $productIds): array
    {
        // A ceiling, reported rather than applied quietly: a check that looked
        // at the first 200 of 500 and said nothing would leave the other 300
        // appearing confirmed.
        $limit = 200;
        $skippedForSize = max(0, count($productIds) - $limit);
        $productIds = array_slice($productIds, 0, $limit);

        /* THE BATCH PREFILL (2026-08-24). This used to ask Shopee about one
         * item per call, sequentially - checking a full group meant up to
         * two hundred API round-trips inside one web request, which
         * php-fpm's execution limit kills long before the answer, leaving
         * the operator's overlay spinning forever ("Check against Shopee
         * hangs", the owner, QAS). get_item_base_info answers fifty at a time:
         * ask once per fifty, remember, and the per-item check below
         * becomes a memo read. Logged per chunk, so the check finally
         * leaves a trace in the API logs. */
        $knownItemIds = ShopeeProductLink::query()
            ->whereIn('product_id', $productIds)
            ->whereNotNull('shopee_item_id')
            ->pluck('shopee_item_id')
            ->map(fn ($v) => (int) $v)->filter()->unique()->values()->all();

        foreach (array_chunk($knownItemIds, 50) as $chunk) {
            try {
                $res = $client->shopGet(
                    $auth['mode'], (int) $auth['partner_id'], (string) $auth['partner_key'],
                    (string) $auth['access_token'], (int) $auth['shop_id'],
                    '/api/v2/product/get_item_base_info',
                    ['item_id_list' => implode(',', $chunk)]
                );
            } catch (\Throwable $e) {
                continue; // these ids fall back to the per-item check
            }

            ShopeeApiLog::safeCreate([
                'pack' => 'shopee.groups.check', 'method' => 'GET',
                'api_path' => '/api/v2/product/get_item_base_info', 'auth_required' => true,
                'request_params' => ['item_id_list' => implode(',', $chunk)],
                'response_status' => $res['status'] ?? null,
                'ok' => (bool) ($res['ok'] ?? false),
                'response_body' => $res['body'] ?? null, 'user_id' => auth()->id(),
            ]);

            $body = is_array($res['body'] ?? null) ? $res['body'] : [];
            if (!($res['ok'] ?? false) || (string) ($body['error'] ?? '') !== '') {
                continue;
            }

            $answered = [];
            foreach (($body['response']['item_list'] ?? []) as $item) {
                $iid = (int) ($item['item_id'] ?? 0);
                if ($iid > 0) {
                    $this->shopeeItemExistence[(int) $auth['shop_id']][$iid]
                        = strtoupper((string) ($item['item_status'] ?? '')) === 'DELETED' ? 'no' : 'yes';
                    $answered[$iid] = true;
                }
            }
            // Absent from a successful answer = the shop holds no such item,
            // exactly as the single-item check reads it.
            foreach ($chunk as $iid) {
                if (!isset($answered[(int) $iid])) {
                    $this->shopeeItemExistence[(int) $auth['shop_id']][(int) $iid] = 'no';
                }
            }
        }

        $pfx = (string) config('catalog.prefix');
        $verdicts = [];
        $problems = [];

        foreach ($productIds as $productId) {
            $verdict = $this->reconcile($client, $auth, (int) $productId, $pfx);
            $state = $verdict['state'];
            $verdicts[$state] = ($verdicts[$state] ?? 0) + 1;

            if ($state === 'unreachable') {
                // Nothing was learned, so nothing is written. The row keeps
                // what it said before, which is honest: it was a belief then
                // and it is still a belief now.
                $problems[] = "#{$productId}: {$verdict['message']}";
                continue;
            }

            /* Shopee answered about this PRODUCT, so the checked-at stamp is
             * the product's (stage 2 of the one-truth repointing,
             * 2026-08-25): it lives on the listing record and shows on
             * every page - a check run from one group used to stamp only
             * that group's pivot, so the same product read "checked" here
             * and "not checked since the push" one group over. */
            ShopeeListing::query()->updateOrCreate(
                ['product_id' => (int) $productId],
                ['last_checked_at' => now()]
            );

            if ($state === 'taken') {
                $problems[] = "#{$productId}: {$verdict['message']}";
                $this->writeProductStatus((int) $productId, [
                    'sync_status' => 'error',
                    'push_error' => Str::limit((string) $verdict['message'], 480),
                ]);
                continue;
            }

            if ($state === 'lost' || $state === 'new') {
                // reconcileShopeeLink has already dropped a dead link and reset
                // the row; this only stamps the time it was established.
                $this->writeProductStatus((int) $productId, [
                    'sync_status' => 'pending',
                    'push_error' => null,
                ]);
                continue;
            }

            $this->writeProductStatus((int) $productId, [
                'sync_status' => 'pushed',
                'shopee_item_id' => $verdict['item_id'] ? (string) $verdict['item_id'] : null,
                'push_error' => null,
            ]);
        }

        $checked = count($productIds) - ($verdicts['unreachable'] ?? 0);

        // Findings, not outcomes. Nothing here wrote to Shopee, so nothing may
        // suggest anything was fixed there.
        $parts = [];
        if (! empty($verdicts['confirmed'])) {
            $parts[] = $verdicts['confirmed'].' still on Shopee';
        }
        if (! empty($verdicts['adopted'])) {
            $parts[] = $verdicts['adopted'].' found and linked';
        }
        if (! empty($verdicts['repointed'])) {
            $parts[] = $verdicts['repointed'].' pointed at the wrong item and corrected';
        }
        if (! empty($verdicts['lost'])) {
            $parts[] = $verdicts['lost'].' no longer on Shopee, now marked not pushed';
        }
        if (! empty($verdicts['new'])) {
            $parts[] = $verdicts['new'].' not pushed yet';
        }
        if (! empty($verdicts['taken'])) {
            $parts[] = $verdicts['taken'].' blocked by another product holding the same item';
        }
        if (! empty($verdicts['unreachable'])) {
            $parts[] = $verdicts['unreachable'].' could not be checked';
        }

        $summary = "Checked {$checked} against Shopee. ".($parts ? implode('. ', $parts).'.' : 'Nothing to report.');

        if ($skippedForSize > 0) {
            $summary .= " {$skippedForSize} further "
                .($skippedForSize === 1 ? 'product was' : 'products were')
                .' not checked this time. Run it again to continue.';
        }

        if ($problems) {
            $summary .= ' '.implode('; ', array_slice($problems, 0, 2));
            if (count($problems) > 2) {
                $summary .= ' (and '.(count($problems) - 2).' more)';
            }
        }

        $failed = count($problems);
        $tone = $failed === 0 ? 'status' : ($checked > 0 ? 'warning' : 'error');



        return ['tone' => $tone, 'summary' => $summary];
    }

    /**
     * The SKUs this product could be known by on Shopee, lowercased.
     *
     * Includes variation SKUs, because Shopee stores a SKU on the item AND on
     * every model under it, and a product is just as findable by one of its
     * variations as by its own code.
     */
    private function shopeeCandidateSkus(int $productId, string $pfx): array
    {
        $product = DB::table($pfx.'product')->where('product_id', $productId)->first(['sku', 'model']);

        if (! $product) {
            return [];
        }

        $skus = [];
        foreach ([$product->sku ?? '', $product->model ?? ''] as $candidate) {
            $candidate = strtolower(trim((string) $candidate));
            if ($candidate !== '') {
                $skus[] = $candidate;
            }
        }

        /* THE VARIATION SKUs ARE CANDIDATES TOO, from BOTH tables.
         *
         * This read combinations' older sibling only, so a product whose
         * variations live in product_option_combinations - which is what
         * every push here builds its models from - offered nothing to
         * search by unless the parent carried a SKU of its own. Shopee
         * needs no parent SKU, so a product could be pushed, lose its
         * link, and then be unfindable forever: the check answered "this
         * product has no SKU" and the next push made a second item beside
         * the first (the owner, 2026-09-09: "Ive been pushing the same product
         * to shopee without issue because it has no parent").
         *
         * Lazada's and TikTok's link checks already read both tables.
         * Shopee's was the one that did not, and Shopee is the channel
         * whose recovery depends on it. The marketplace half was always
         * ready: skuIndex() maps every model_sku in the shop to its item.
         */
        $optSkus = DB::table($pfx.'product_option_value')
            ->where('product_id', $productId)
            ->whereNotNull('sku')->where('sku', '!=', '')
            ->pluck('sku')->map(fn ($v) => strtolower(trim((string) $v)))->all();

        $comboSkus = DB::table('product_option_combinations')
            ->where('product_id', $productId)
            ->whereNotNull('sku')->where('sku', '!=', '')
            ->pluck('sku')->map(fn ($v) => strtolower(trim((string) $v)))->all();

        return array_values(array_unique(array_filter(array_merge($skus, $optSkus, $comboSkus))));
    }

    /**
     * Does Shopee still have this item?
     *
     * @return string 'yes' | 'no' | 'unknown'
     */
    private function shopeeItemStillExists(ShopeeClient $client, array $auth, int $itemId): string
    {
        // Answered already by the batch prefill: fifty items a call instead
        // of one. Only definitive answers are cached - 'unknown' is retried.
        $shopId = (int) ($auth['shop_id'] ?? 0);
        if (isset($this->shopeeItemExistence[$shopId][$itemId])) {
            return $this->shopeeItemExistence[$shopId][$itemId];
        }

        try {
            $res = $client->shopGet(
                $auth['mode'], (int) $auth['partner_id'], (string) $auth['partner_key'],
                (string) $auth['access_token'], (int) $auth['shop_id'],
                '/api/v2/product/get_item_base_info',
                ['item_id_list' => (string) $itemId]
            );
        } catch (\Throwable $e) {
            // The client retries twice and then throws. A throw is the network
            // failing, never Shopee answering, so it can prove nothing.
            return 'unknown';
        }

        $body = is_array($res['body'] ?? null) ? $res['body'] : [];
        $err = strtolower((string) ($body['error'] ?? ''));

        if ($err !== '') {
            // Shopee replies 200 with an error field, so the HTTP status says
            // nothing. Only an error that NAMES the item as missing counts as
            // an answer; auth failures, rate limits and the rest are silence.
            return str_contains($err, 'not_found') || str_contains($err, 'not_exist') ? 'no' : 'unknown';
        }

        if (! ($res['ok'] ?? false)) {
            return 'unknown';
        }

        $items = $body['response']['item_list'] ?? [];

        foreach ($items as $item) {
            if ((int) ($item['item_id'] ?? 0) !== $itemId) {
                continue;
            }

            // DELETED is the only status that means gone. UNLIST and BANNED
            // items are still there - treating them as missing would delete a
            // good link and then create a duplicate beside the original.
            return $this->shopeeItemExistence[$shopId][$itemId]
                = strtoupper((string) ($item['item_status'] ?? '')) === 'DELETED' ? 'no' : 'yes';
        }

        return $this->shopeeItemExistence[$shopId][$itemId] = 'no';
    }

    /**
     * Every SKU in the shop, mapped to the item that carries it.
     *
     * Built ONCE per request and reused. Shopee has no "get item by SKU"
     * endpoint, so finding a product by its code means paging the whole shop
     * and fetching details in batches of fifty - about forty calls. Per
     * product that would be ruinous on a group of any size; shared across the
     * batch it is a fixed cost paid only when something actually needs
     * looking up.
     *
     * @return array{skus: array<string,int>, complete: bool}
     */
    public function skuIndex(ShopeeClient $client, array $auth): array
    {
        $shopId = (int) ($auth['shop_id'] ?? 0);
        if (isset($this->shopeeSkuIndex[$shopId])) {
            return $this->shopeeSkuIndex[$shopId];
        }

        $map = [];
        $complete = true;

        // UNLIST and BANNED included deliberately. An item hidden from the
        // storefront still occupies its SKU, and a search that ignored them
        // would report "not on Shopee" and create a second listing.
        foreach (['NORMAL', 'UNLIST', 'BANNED'] as $status) {
            $offset = 0;

            for ($page = 0; $page < 20; $page++) {
                try {
                    $res = $client->shopGet(
                        $auth['mode'], (int) $auth['partner_id'], (string) $auth['partner_key'],
                        (string) $auth['access_token'], (int) $auth['shop_id'],
                        '/api/v2/product/get_item_list',
                        ['offset' => $offset, 'page_size' => 50, 'item_status' => $status]
                    );
                } catch (\Throwable $e) {
                    $complete = false;
                    break;
                }

                /* A REFUSAL IS NOT AN EMPTY SHOP.
                 *
                 * Only the item list was read here, so a call that failed -
                 * an expired token, a rate limit, a 500 - produced no items
                 * and was indistinguishable from a shop holding none. The
                 * index then called itself complete, the caller concluded
                 * "not on Shopee", and a push created a listing beside the
                 * one already there. The owner's QA log is full of
                 * invalid_acceess_token on exactly these days. */
                if (! ($res['ok'] ?? false) || (string) ($res['body']['error'] ?? '') !== '') {
                    $complete = false;
                    break;
                }

                $items = $res['body']['response']['item'] ?? [];
                if (empty($items)) {
                    break;
                }

                $itemIds = array_filter(array_map(fn ($i) => $i['item_id'] ?? null, $items));
                if (empty($itemIds)) {
                    break;
                }

                try {
                    $detail = $client->shopGet(
                        $auth['mode'], (int) $auth['partner_id'], (string) $auth['partner_key'],
                        (string) $auth['access_token'], (int) $auth['shop_id'],
                        '/api/v2/product/get_item_base_info',
                        ['item_id_list' => implode(',', $itemIds)]
                    );
                } catch (\Throwable $e) {
                    $complete = false;
                    break;
                }

                if (! ($detail['ok'] ?? false) || (string) ($detail['body']['error'] ?? '') !== '') {
                    $complete = false;
                    break;
                }

                foreach ($detail['body']['response']['item_list'] ?? [] as $item) {
                    $itemId = (int) ($item['item_id'] ?? 0);
                    if ($itemId <= 0) {
                        continue;
                    }

                    $itemSku = strtolower(trim((string) ($item['item_sku'] ?? '')));
                    if ($itemSku !== '' && ! isset($map[$itemSku])) {
                        $map[$itemSku] = $itemId;
                    }

                    /* A MODEL'S SKU IS ITS OWN CALL.
                     *
                     * This used to read a model list off the base info, a
                     * field Shopee does not put there: models come
                     * from get_model_list, one item at a time, which is how
                     * every other model read in this application already
                     * does it. So the index has never held a single model
                     * SKU - and a product with NO parent SKU contributes
                     * nothing to it at all, which is exactly the product
                     * that cannot be found any other way. The owner pushed one
                     * on 2026-09-09, unlinked it, pushed again, and got a
                     * duplicate with the guard in place.
                     *
                     * Paid only for an item that HAS models, which the base
                     * info does tell us. */
                    if (! empty($item['has_model'])) {
                        foreach ($this->modelSkus($client, $auth, $itemId, $complete) as $modelSku) {
                            if (! isset($map[$modelSku])) {
                                $map[$modelSku] = $itemId;
                            }
                        }
                    }
                }

                if (! ($res['body']['response']['has_next_page'] ?? false)) {
                    break;
                }

                $offset += 50;

                if ($page === 19) {
                    // Reported, never swallowed: a shop bigger than the ceiling
                    // would otherwise have its tail read as "not on Shopee".
                    $complete = false;
                }
            }
        }

        return $this->shopeeSkuIndex[$shopId] = ['skus' => $map, 'complete' => $complete];
    }

    /**
     * One item's model SKUs, lowercased.
     *
     * A read that fails marks the whole index incomplete: an item whose
     * models could not be read is an item whose SKUs are missing from the
     * map, and concluding "not on Shopee" from a map with holes in it is
     * how a duplicate gets created.
     *
     * @return list<string>
     */
    private function modelSkus(ShopeeClient $client, array $auth, int $itemId, bool &$complete): array
    {
        try {
            $res = $client->shopGet(
                $auth['mode'], (int) $auth['partner_id'], (string) $auth['partner_key'],
                (string) $auth['access_token'], (int) $auth['shop_id'],
                '/api/v2/product/get_model_list',
                ['item_id' => $itemId]
            );
        } catch (\Throwable $e) {
            $complete = false;

            return [];
        }

        $body = is_array($res['body'] ?? null) ? $res['body'] : [];
        if (! ($res['ok'] ?? false) || (string) ($body['error'] ?? '') !== '') {
            $complete = false;

            return [];
        }

        $out = [];
        foreach (($body['response']['model'] ?? []) as $model) {
            $sku = strtolower(trim((string) ($model['model_sku'] ?? '')));
            if ($sku !== '') {
                $out[] = $sku;
            }
        }

        return $out;
    }

    /**
     * Ask Shopee what is actually true, and make the local link agree.
     *
     * The same reconcile Venta got, for the same reason: every action on a row
     * decided what to do from a link row, which is the ERP's own memory and
     * can be wrong in both directions. Delete an item in Seller Centre and the
     * ERP went on believing it was live, offering Update - which can only
     * answer item_not_found - while Push was skipped because a link existed.
     *
     * The order here is chosen around what each question costs. Confirming an
     * item we already know the id of is one call. Searching by SKU means
     * paging the shop, so it only happens when the cheap question came back
     * negative or there was no id to ask about.
     *
     * Returns confirmed, adopted, repointed, lost, new, taken or unreachable.
     *
     * @return array{state: string, item_id: int|null, message: string|null}
     */
    public function reconcile(ShopeeClient $client, array $auth, int $productId, string $pfx): array
    {
        $link = ShopeeProductLink::query()
            ->where('product_id', $productId)
            ->whereNotNull('shopee_item_id')
            ->first();

        $knownId = $link ? (int) $link->shopee_item_id : 0;

        if ($knownId > 0) {
            $verdict = $this->shopeeItemStillExists($client, $auth, $knownId);

            if ($verdict === 'unknown') {
                return [
                    'state' => 'unreachable',
                    'item_id' => $knownId,
                    'message' => 'Shopee did not answer, so nothing was checked or changed.',
                ];
            }

            if ($verdict === 'yes') {
                return ['state' => 'confirmed', 'item_id' => $knownId, 'message' => null];
            }
        }

        $skus = $this->shopeeCandidateSkus($productId, $pfx);

        if (empty($skus)) {
            // Nothing to search by. Say so rather than concluding absence: a
            // product with no SKU is unfindable, not missing.
            return [
                'state' => 'unreachable',
                'item_id' => null,
                'message' => 'This product has no SKU, so it cannot be matched against Shopee.',
            ];
        }

        $index = $this->skuIndex($client, $auth);

        $found = null;
        foreach ($skus as $sku) {
            if (isset($index['skus'][$sku])) {
                $found = (int) $index['skus'][$sku];
                break;
            }
        }

        if ($found === null) {
            if (! $index['complete']) {
                return [
                    'state' => 'unreachable',
                    'item_id' => $knownId ?: null,
                    'message' => 'The shop could not be read all the way through, so this product was left alone.',
                ];
            }

            if ($knownId > 0) {
                /* DROPPING A LINK IS DESTRUCTIVE, so it happens only on an
                 * answer about THIS shop. The index and the existence memo
                 * are keyed by shop id for the same reason: on 2026-09-09
                 * one shared instance served every store in the scheduled
                 * refresh, so the second store was judged against the
                 * first's shop and had every good link dropped. */
                // Gone from Shopee. Dropping the link is what lets Send create
                // instead of sending an update that can only answer
                // item_not_found. This also clears the item cache, which is
                // the other half of the same memory: a cached SKU to item_id
                // row would otherwise hand the dead id straight back.
                /* AND IT IS WRITTEN DOWN. Dropping a link is destructive
                 * and left no trace at all, so an operator watching links
                 * vanish had nothing to look at and no way to tell a
                 * correct drop from a wrong one (the owner, 2026-09-09: "i
                 * didnt do anything for a few mins... and the products are
                 * unlinked"). Every fact the decision rested on goes in the
                 * activity log: which item, which SHOP was asked, what it
                 * was searched by, and that the shop was read to the end. */
                \App\Integrations\Listings\DroppedLink::write(
                    'Shopee', 'Shopee Product', $productId, $knownId,
                    (int) ($auth['shop_id'] ?? 0), $skus, count($index['skus'])
                );

                ShopeeProductLink::unlinkProduct($productId);

                // unlinkProduct leaves the row saying 'unlinked', which is the
                // word for a deliberate act by the operator. Nobody unlinked
                // this - Shopee lost it - and the row needs to read as not
                // pushed so that Send offers to create it again.
                $this->writeProductStatus($productId, [
                    'sync_status' => 'pending',
                    'push_error' => null,
                ]);

                return ['state' => 'lost', 'item_id' => null, 'message' => null];
            }

            return ['state' => 'new', 'item_id' => null, 'message' => null];
        }

        $otherHolder = ShopeeProductLink::query()
            ->where('shopee_item_id', $found)
            ->where('product_id', '!=', $productId)
            ->value('product_id');

        if ($otherHolder) {
            // Not taken over. That link may be perfectly good, and stealing it
            // would leave the other product pointing at nothing.
            return [
                'state' => 'taken',
                'item_id' => $found,
                'message' => "Shopee item {$found} is already linked to catalog product #{$otherHolder}. "
                    .'Unlink that product first, then send this one again.',
            ];
        }

        ShopeeProductLink::updateOrCreate(
            ['product_id' => $productId, 'shopee_item_id' => $found, 'shopee_model_id' => null],
            ['sku' => $skus[0] ?? null]
        );

        return [
            'state' => $knownId > 0 ? 'repointed' : 'adopted',
            'item_id' => $found,
            'message' => null,
        ];
    }

    private function writeProductStatus(int $productId, array $attributes): void
    {
        \App\Support\ChannelProductStatus::write(
            'shopee_product_group_products',
            'shopee_product_group_id',
            null,
            $productId,
            $attributes
        );
    }
}
