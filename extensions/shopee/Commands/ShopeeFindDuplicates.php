<?php

namespace Extensions\shopee\Commands;

use Extensions\shopee\Models\ShopeeProductLink;
use Extensions\shopee\Models\ShopeeSetting;
use Extensions\shopee\Services\Shopee\ShopeeClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

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
            $this->reportLinks((int) $store->id);
            $this->reportSkuless((int) $store->id);
            $this->reportPushLog((int) $store->id);
        }

        return self::SUCCESS;
    }

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
                    if (! empty($item['has_model'])) {
                        $models = $this->get($client, $auth, '/api/v2/product/get_model_list', ['item_id' => $id]);
                        if ($models === null) {
                            $complete = false;
                        }
                        foreach (($models['response']['model'] ?? []) as $model) {
                            $modelSku = strtolower(trim((string) ($model['model_sku'] ?? '')));
                            if ($modelSku !== '') {
                                $skus[] = $modelSku;
                            }
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

    private function reportLinks(int $storeId): void
    {
        $rows = ShopeeProductLink::query()
            ->withoutGlobalScope('shopeeStore')
            ->orderBy('product_id')
            ->get(['product_id', 'shopee_item_id', 'shopee_model_id', 'shopee_setting_id', 'sku', 'live_status']);

        $this->line('');
        $this->line('Link rows in this ERP (every store): ' . $rows->count());

        $byStore = $rows->groupBy(fn ($r) => (string) ($r->shopee_setting_id ?? 'NULL'));
        foreach ($byStore as $sid => $group) {
            $mark = (string) $sid === (string) $storeId ? '' : '   <- NOT this store';
            $this->line(sprintf('  store %s: %d row(s)%s', $sid, $group->count(), $mark));
        }

        foreach ($rows as $row) {
            $this->line(sprintf(
                '  product #%d -> item %s%s  sku=%s  status=%s  store=%s',
                $row->product_id,
                $row->shopee_item_id ?: 'none',
                $row->shopee_model_id ? ' model ' . $row->shopee_model_id : '',
                $row->sku === '' || $row->sku === null ? 'none' : $row->sku,
                $row->live_status ?: 'not checked',
                $row->shopee_setting_id ?? 'NULL'
            ));
        }
    }

    private function reportPushLog(int $storeId): void
    {
        $packs = [
            'shopee.products.add_item.direct',
            'shopee.products.add_item.group',
            'shopee.products.init_tier_variation.group',
            'shopee.products.delete_item.undo_half_create',
            'shopee.product.delete_item',
            'shopee.product.delete_item.bulk',
        ];

        $rows = \Extensions\shopee\Models\ShopeeApiLog::query()
            ->whereIn('pack', $packs)
            ->orderByDesc('id')
            ->limit(60)
            ->get(['id', 'pack', 'ok', 'response_status', 'request_params', 'response_body', 'created_at']);

        $this->line('');
        if ($rows->isEmpty()) {
            $this->line('No create, variation or delete calls in the API log.');

            return;
        }

        $this->line('The last ' . $rows->count() . ' create / variation / delete call(s), newest first:');
        foreach ($rows as $row) {
            $req = is_array($row->request_params) ? $row->request_params : [];
            $body = is_array($row->response_body) ? $row->response_body : [];
            $err = trim((string) ($body['error'] ?? ''));
            $msg = trim((string) ($body['message'] ?? ''));
            $itemId = $body['response']['item_id'] ?? ($req['item_id'] ?? null);

            $this->line(sprintf(
                '  %s  %-44s %-3s item=%-12s %s',
                $row->created_at?->format('Y-m-d H:i:s') ?? '',
                str_replace('shopee.product', '', (string) $row->pack),
                $row->ok ? 'ok' : 'ERR',
                $itemId !== null ? (string) $itemId : 'none',
                $err !== '' || $msg !== '' ? trim($err . ' ' . $msg) : ''
            ));
        }
    }

    private function reportSkuless(int $storeId): void
    {
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $rows = DB::table($pfx . 'product as p')
            ->leftJoin($pfx . 'product_description as pd', function ($j) use ($langId) {
                $j->on('p.product_id', '=', 'pd.product_id')->where('pd.language_id', '=', $langId);
            })
            ->whereIn('p.product_id', \Extensions\shopee\Services\ShopeeStoreProducts::query())
            ->where(fn ($q) => $q->whereNull('p.sku')->orWhere('p.sku', ''))
            ->where(fn ($q) => $q->whereNull('p.model')->orWhere('p.model', ''))
            ->orderBy('p.product_id')
            ->get(['p.product_id', 'pd.name']);

        $this->line('');
        if ($rows->isEmpty()) {
            $this->line('Every product on this store carries an SKU or a model.');

            return;
        }

        $this->warn($rows->count() . ' product(s) on this store carry neither an SKU nor a model.');
        $this->line('A push of one of these cannot be matched back to its Shopee item, so a lost link');
        $this->line('can never be recovered and the next push creates a second item.');
        foreach ($rows as $row) {
            $this->line(sprintf('  #%d  %s', $row->product_id, html_entity_decode((string) $row->name)));
        }
    }

    private function linksAreScoped(): bool
    {
        return \Illuminate\Support\Facades\Schema::hasColumn(
            (new ShopeeProductLink())->getTable(), 'shopee_setting_id'
        );
    }
}
