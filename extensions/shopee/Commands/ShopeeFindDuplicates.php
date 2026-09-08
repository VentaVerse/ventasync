<?php

namespace Extensions\shopee\Commands;

use Extensions\shopee\Models\ShopeeProductLink;
use Extensions\shopee\Models\ShopeeSetting;
use Extensions\shopee\Services\Shopee\ShopeeClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * READ ONLY. What is actually in the Shopee shop, and where it disagrees
 * with what this ERP believes.
 *
 * Written for the owner's 2026-09-09 report: the same product had been pushed
 * to Shopee over and over, and nothing caught it. The ERP's only guard
 * against a second create is its own link row, and its only way back from
 * a lost link is a SKU match - so a product with no parent SKU whose item
 * lost its link can be created again, and again, and Shopee does not
 * enforce unique SKUs either.
 *
 * This command answers, for each enabled store, the four questions that
 * tell the whole story:
 *
 *   1. which SKUs sit on more than one item        (duplicates, findable)
 *   2. which item NAMES sit on more than one item  (duplicates with no SKU
 *                                                   to find them by - the
 *                                                   ones that stay hidden)
 *   3. which items this ERP holds no link for      (created, then orphaned)
 *   4. which of this store's products have no SKU  (the unadoptable set)
 *
 * It writes nothing, calls no write endpoint, and can be run on a live
 * shop. Paging is bounded exactly the way ShopeeLinkCheck::skuIndex bounds
 * it, and a shop it could not read all the way through says so rather than
 * reporting a short answer as a complete one.
 */
class ShopeeFindDuplicates extends Command
{
    protected $signature = 'shopee:find-duplicates {--store= : one store id, default every enabled store}';

    protected $description = 'Report duplicate Shopee items, items with no ERP link, and products with no SKU (read only)';

    private const PAGES = 40;

    public function handle(ShopeeClient $client): int
    {
        $stores = ShopeeSetting::query()->where('enabled', true)
            ->when($this->option('store'), fn ($q) => $q->whereKey((int) $this->option('store')))
            ->orderBy('id')->get();

        if ($stores->isEmpty()) {
            $this->error('No enabled Shopee store.');

            return self::FAILURE;
        }

        foreach ($stores as $store) {
            $label = $store->store_name ?: ('#' . $store->id);
            $auth = ShopeeSetting::activeAuth($store->decrypted());
            if (! $auth['complete']) {
                $this->error("Store {$label}: missing credentials.");
                continue;
            }

            $this->line('');
            $this->info("=== Store {$label} ===");

            $read = $this->readShop($client, $auth);
            if (! $read['complete']) {
                $this->warn('The shop could not be read all the way through. Everything below is a floor, not a total.');
            }
            $items = $read['items'];
            $this->line(count($items) . ' item(s) read from Shopee.');

            $this->reportRepeats($items, 'SKU', fn ($i) => $i['skus']);
            $this->reportRepeats($items, 'name', fn ($i) => $i['name'] !== '' ? [$i['name']] : []);
            $this->reportOrphans($items, (int) $store->id);
            $this->reportSkuless((int) $store->id);
        }

        return self::SUCCESS;
    }

    /**
     * Every item in the shop, with the SKUs it carries.
     *
     * @return array{items: array<int, array{id: int, name: string, status: string, skus: list<string>}>, complete: bool}
     */
    private function readShop(ShopeeClient $client, array $auth): array
    {
        $items = [];
        $complete = true;

        foreach (['NORMAL', 'UNLIST', 'BANNED'] as $status) {
            $offset = 0;

            for ($page = 0; $page < self::PAGES; $page++) {
                $list = $this->get($client, $auth, '/api/v2/product/get_item_list', [
                    'offset' => $offset, 'page_size' => 50, 'item_status' => $status,
                ]);
                if ($list === null) {
                    $complete = false;
                    break;
                }

                $rows = $list['response']['item'] ?? [];
                if (empty($rows)) {
                    break;
                }

                $ids = array_values(array_filter(array_map(fn ($i) => (int) ($i['item_id'] ?? 0), $rows)));
                if ($ids === []) {
                    break;
                }

                $detail = $this->get($client, $auth, '/api/v2/product/get_item_base_info', [
                    'item_id_list' => implode(',', $ids),
                ]);
                if ($detail === null) {
                    $complete = false;
                    break;
                }

                foreach ($detail['response']['item_list'] ?? [] as $item) {
                    $id = (int) ($item['item_id'] ?? 0);
                    if ($id <= 0) {
                        continue;
                    }

                    $skus = [];
                    $itemSku = strtolower(trim((string) ($item['item_sku'] ?? '')));
                    if ($itemSku !== '') {
                        $skus[] = $itemSku;
                    }
                    foreach ($item['model_list'] ?? [] as $model) {
                        $modelSku = strtolower(trim((string) ($model['model_sku'] ?? '')));
                        if ($modelSku !== '') {
                            $skus[] = $modelSku;
                        }
                    }

                    $items[$id] = [
                        'id' => $id,
                        'name' => trim((string) ($item['item_name'] ?? '')),
                        'status' => strtoupper((string) ($item['item_status'] ?? $status)),
                        'skus' => array_values(array_unique($skus)),
                    ];
                }

                if (! ($list['response']['has_next_page'] ?? false)) {
                    break;
                }
                $offset += 50;
                if ($page === self::PAGES - 1) {
                    $complete = false;
                }
            }
        }

        return ['items' => $items, 'complete' => $complete];
    }

    /** One GET, or null when Shopee did not answer with a body. */
    private function get(ShopeeClient $client, array $auth, string $path, array $query): ?array
    {
        try {
            $res = $client->shopGet(
                $auth['mode'], (int) $auth['partner_id'], (string) $auth['partner_key'],
                (string) $auth['access_token'], (int) $auth['shop_id'], $path, $query
            );
        } catch (\Throwable $e) {
            $this->warn('  ' . $path . ': ' . $e->getMessage());

            return null;
        }

        $body = is_array($res['body'] ?? null) ? $res['body'] : [];
        $err = (string) ($body['error'] ?? '');
        if ($err !== '' || ! ($res['ok'] ?? false)) {
            $this->warn('  ' . $path . ': ' . ($body['message'] ?? $err ?: 'no answer'));

            return null;
        }

        return $body;
    }

    /** Any key that more than one item carries. */
    private function reportRepeats(array $items, string $what, callable $keysOf): void
    {
        $byKey = [];
        foreach ($items as $item) {
            foreach ($keysOf($item) as $key) {
                $byKey[$key][] = $item;
            }
        }
        $repeats = array_filter($byKey, fn ($rows) => count($rows) > 1);

        $this->line('');
        if ($repeats === []) {
            $this->line("No {$what} sits on more than one item.");

            return;
        }

        $this->warn(count($repeats) . " {$what}(s) sit on more than one item:");
        foreach ($repeats as $key => $rows) {
            $this->line('  ' . $key);
            foreach ($rows as $row) {
                $this->line(sprintf('      item %d  %s  %s', $row['id'], str_pad($row['status'], 8), $row['name']));
            }
        }
    }

    /** Items in the shop this ERP holds no link for. */
    private function reportOrphans(array $items, int $storeId): void
    {
        $linked = ShopeeProductLink::query()
            ->when($this->linksAreScoped(), fn ($q) => $q->where('shopee_setting_id', $storeId))
            ->pluck('shopee_item_id')->map(fn ($v) => (int) $v)->flip();

        $orphans = array_filter($items, fn ($i) => ! $linked->has($i['id']));

        $this->line('');
        if ($orphans === []) {
            $this->line('Every item in the shop has a link in this ERP.');

            return;
        }

        $this->warn(count($orphans) . ' item(s) in the shop have NO link in this ERP:');
        foreach ($orphans as $item) {
            $this->line(sprintf(
                '  item %d  %s  %s  [%s]',
                $item['id'], str_pad($item['status'], 8), $item['name'],
                $item['skus'] === [] ? 'no SKU' : implode(', ', $item['skus'])
            ));
        }
    }

    /** This store's products that carry no SKU and no model: unadoptable. */
    private function reportSkuless(int $storeId): void
    {
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $rows = DB::table($pfx . 'product as p')
            ->leftJoin($pfx . 'product_description as pd', function ($j) use ($langId) {
                $j->on('p.product_id', '=', 'pd.product_id')->where('pd.language_id', '=', $langId);
            })
            ->whereIn('p.product_id', \Extensions\shopee\Models\ShopeeStoreProducts::query())
            ->where(fn ($q) => $q->whereNull('p.sku')->orWhere('p.sku', ''))
            ->where(fn ($q) => $q->whereNull('p.model')->orWhere('p.model', ''))
            ->orderBy('p.product_id')
            ->get(['p.product_id', 'pd.name']);

        $this->line('');
        if ($rows->isEmpty()) {
            $this->line('Every product on this store carries a SKU or a model.');

            return;
        }

        $this->warn($rows->count() . ' product(s) on this store carry neither a SKU nor a model.');
        $this->line('A push of one of these cannot be matched back to its Shopee item, so a lost link');
        $this->line('can never be recovered and the next push creates a second item.');
        foreach ($rows as $row) {
            $this->line(sprintf('  #%d  %s', $row->product_id, html_entity_decode((string) $row->name)));
        }
    }

    /** Multi-store links carry the store; older shops did not. */
    private function linksAreScoped(): bool
    {
        return \Illuminate\Support\Facades\Schema::hasColumn(
            (new ShopeeProductLink())->getTable(), 'shopee_setting_id'
        );
    }
}
