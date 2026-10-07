<?php

namespace App\Integrations;

use App\Extensions\ExtensionManager;
use App\Models\User;

class IntegrationRegistry
{
    protected array $providers = [];

    public function register(IntegrationProvider $provider): void
    {
        $this->providers[$provider->integrationId()] = $provider;
    }

    public function all(): array
    {
        return $this->providers;
    }

    public function visibleCards(?User $user): array
    {
        $cards = [];

        foreach ($this->providers as $id => $provider) {
            if (!$this->passesGates($id, $user)) {
                continue;
            }

            foreach ($provider->integrationCards() as $card) {
                if (!$card instanceof IntegrationCard) {
                    continue;
                }
                if ($card->permission && (!$user || !$user->hasPermission($card->permission))) {
                    continue;
                }
                $cards[] = $card;
            }
        }

        return $this->sortByDisplayOrder($cards);
    }

    public function cardsForAuthorisedRequest(?User $user): array
    {
        $cards = [];

        foreach ($this->providers as $id => $provider) {
            if (!$this->passesGates($id, $user)) {
                continue;
            }

            foreach ($provider->integrationCards() as $card) {
                if (!$card instanceof IntegrationCard) {
                    continue;
                }
                $cards[] = $card;
            }
        }

        return $this->sortByDisplayOrder($cards);
    }

    protected function sortByDisplayOrder(array $cards): array
    {
        $priority = [
            'lazada'    => 1,
            'shopee'    => 2,
            'tiktok'    => 3,
            'shopify'   => 4,
            'ventacart'     => 5,
            'opencart'  => 6,
            'pedallion' => 7,
        ];
        $rankFor = function ($id) use ($priority) {
            $base = str_contains((string) $id, ':') ? strstr((string) $id, ':', true) : (string) $id;
            return $priority[$base] ?? 999;
        };

        $indexed = [];
        foreach ($cards as $i => $card) {
            $indexed[] = [$rankFor($card->id), $i, $card];
        }
        usort($indexed, function ($a, $b) {
            return $a[0] === $b[0] ? $a[1] <=> $b[1] : $a[0] <=> $b[0];
        });
        return array_map(fn($t) => $t[2], $indexed);
    }

    public function skuResolvers(): array
    {
        return $this->providersImplementing(\App\Integrations\Contracts\SkuResolver::class);
    }

    public function skuSyncContributors(): array
    {
        return $this->providersImplementing(\App\Integrations\Contracts\SkuSyncContributor::class);
    }

    public function credentialRevealer(string $channelId): ?\App\Integrations\Contracts\CredentialRevealer
    {
        $provider = $this->providers[$channelId] ?? null;

        return $provider instanceof \App\Integrations\Contracts\CredentialRevealer
            ? $provider
            : null;
    }

    public function dashboardContributors(): array
    {
        return $this->providersImplementing(\App\Integrations\Contracts\DashboardContributor::class);
    }

    public function orderImagesContributors(): array
    {
        return $this->providersImplementing(\App\Integrations\Contracts\OrderImagesContributor::class);
    }

    public function orderFeesContributors(): array
    {
        return $this->providersImplementing(\App\Integrations\Contracts\OrderFeesContributor::class);
    }

    public function marketplaceFeeSources(): array
    {
        return $this->providersImplementing(\App\Integrations\Contracts\MarketplaceFeeSource::class);
    }

    public function saleLinesSources(): array
    {
        return $this->providersImplementing(\App\Integrations\Contracts\SaleLinesSource::class);
    }

    public function mobileProviderFor(string $platform): ?\App\Integrations\Contracts\MobileMarketplaceProvider
    {
        foreach ($this->providersImplementing(\App\Integrations\Contracts\MobileMarketplaceProvider::class) as $provider) {
            if ($provider->mobilePlatformSlug() === $platform) {
                return $provider;
            }
        }
        return null;
    }

    public function layoutBannerContributors(): array
    {
        return $this->providersImplementing(\App\Integrations\Contracts\LayoutBannerContributor::class);
    }

    public function layoutBannerContributorsById(): array
    {
        $out = [];

        foreach ($this->providers as $id => $provider) {
            if ($provider instanceof \App\Integrations\Contracts\LayoutBannerContributor
                && $this->passesGates($id, null)) {
                $out[$id] = $provider;
            }
        }

        return $out;
    }

    public function productInventoryDetailContributors(): array
    {
        return $this->providersImplementing(\App\Integrations\Contracts\ProductInventoryDetailContributor::class);
    }

    public function resolveRootRouteResponse(\Illuminate\Http\Request $request): mixed
    {
        foreach ($this->providersImplementing(\App\Integrations\Contracts\RootRouteHandler::class) as $handler) {
            $response = $handler->handleRootRoute($request);
            if ($response !== null) {
                return $response;
            }
        }
        return null;
    }

    public function availableMarketplaceSourceOptions(): array
    {
        $options = [];
        foreach ($this->providersImplementing(\App\Integrations\Contracts\MarketplaceSourceOptionsProvider::class) as $provider) {
            foreach ($provider->availableSourceOptions() as $opt) {
                if (is_array($opt) && isset($opt['value'], $opt['label'])) {
                    $options[] = $opt;
                }
            }
        }
        return $options;
    }

    public function productActions(int $productId): array
    {
        $actions = [];
        foreach ($this->providersImplementing(\App\Integrations\Contracts\ProductActionContributor::class) as $provider) {
            foreach ($provider->productActions($productId) as $action) {
                if (is_array($action) && isset($action['label'], $action['url'])) {
                    $actions[] = $action;
                }
            }
        }
        return $actions;
    }

    public function resolveMarketplaceSourceLabel(string $source): ?string
    {
        foreach ($this->providersImplementing(\App\Integrations\Contracts\MarketplaceSourceLabelResolver::class) as $resolver) {
            $label = $resolver->resolveSourceLabel($source);
            if ($label !== null) {
                return $label;
            }
        }
        return null;
    }

    public function resolveOrderRef(string $marketplaceSource, string $marketplaceOrderId): ?array
    {
        if ($marketplaceSource === '' || $marketplaceOrderId === '') {
            return null;
        }
        foreach ($this->providersImplementing(\App\Integrations\Contracts\MarketplaceOrderRefRenderer::class) as $renderer) {
            $ref = $renderer->renderOrderRef($marketplaceSource, $marketplaceOrderId);
            if ($ref !== null) {
                return $ref;
            }
        }
        return null;
    }

    protected function providersImplementing(string $contract): array
    {
        $matches = [];

        foreach ($this->providers as $id => $provider) {
            if (!$provider instanceof $contract) {
                continue;
            }
            if (!$this->passesGates($id, null)) {
                continue;
            }
            $matches[] = $provider;
        }

        return $matches;
    }

    public function visibleOrderTabs(?User $user): array
    {
        $tabs = [];

        foreach ($this->providers as $id => $provider) {
            if (!$this->passesGates($id, $user)) {
                continue;
            }

            foreach ($provider->orderTabs() as $tab) {
                if (!$tab instanceof OrderTab) {
                    continue;
                }
                if ($tab->permission && (!$user || !$user->hasPermission($tab->permission))) {
                    continue;
                }
                $tabs[] = $tab;
            }
        }

        return $this->sortByDisplayOrder($tabs);
    }

    public function storeOptions(): array
    {
        $out = [['key' => \App\Support\StoreKey::MANUAL, 'label' => 'Manual orders', 'channel' => 'VentaSync', 'source' => '', 'store_id' => null]];

        foreach ($this->providers as $id => $provider) {
            if (! $provider instanceof \App\Integrations\Contracts\StoreOptionsProvider || ! $this->passesGates($id, null)) {
                continue;
            }
            foreach ($provider->availableStoreOptions() as $opt) {
                unset($opt['permission']);
                $out[] = $opt;
            }
        }

        return $out;
    }

    public function unlistedStoreOptions(?User $user, ?string $from = null, ?string $to = null): array
    {
        $live = [];
        $channels = [];
        foreach ($this->storeOptions() as $opt) {
            $live[$opt['key']] = true;
            if ($opt['source'] !== '') {
                $channels[strstr($opt['source'], ':', true) ?: $opt['source']] ??= $opt['channel'];
            }
        }

        $query = \Illuminate\Support\Facades\DB::table((string) config('catalog.prefix') . 'order')
            ->where('marketplace_source', '<>', '')
            ->whereNotNull('marketplace_source')
            ->groupByRaw("marketplace_source, store_id, DATE_FORMAT(date_added, '%Y-%m')")
            ->selectRaw("marketplace_source, store_id, DATE_FORMAT(date_added, '%Y-%m') AS month, MAX(order_id) AS latest");
        if ($from) {
            $query->where('date_added', '>=', $from . ' 00:00:00');
        }
        if ($to) {
            $query->where('date_added', '<=', $to . ' 23:59:59');
        }

        $rows = [];
        foreach ($query->get() as $r) {
            $id = $r->marketplace_source . '|' . $r->store_id;
            $rows[$id] ??= (object) ['marketplace_source' => $r->marketplace_source, 'store_id' => $r->store_id, 'latest' => 0, 'months' => []];
            $rows[$id]->latest = max($rows[$id]->latest, (int) $r->latest);
            $rows[$id]->months[] = (string) $r->month;
        }
        $rows = collect(array_values($rows));

        $names = $rows->isEmpty() ? collect() : \Illuminate\Support\Facades\DB::table((string) config('catalog.prefix') . 'order')
            ->whereIn('order_id', $rows->pluck('latest'))->pluck('store_name', 'order_id');

        $extensions = app(ExtensionManager::class);
        $out = [];
        foreach ($rows as $row) {
            $source = (string) $row->marketplace_source;
            $channelId = strstr($source, ':', true) ?: $source;
            $storeId = str_contains($source, ':') ? null : (int) $row->store_id;
            if ($storeId === 0) {
                continue;
            }
            $key = \App\Support\StoreKey::of($source, (int) $row->store_id);
            if (isset($live[$key]) || isset($out[$key])) {
                continue;
            }

            $provider = $this->providers[$channelId] ?? null;
            $on = $provider instanceof \App\Integrations\Contracts\StoreOptionsProvider && $extensions->isEnabled($channelId);
            if ($on) {
                $channel = $channels[$channelId] ?? (string) ($extensions->getManifest($channelId)['name'] ?? \Illuminate\Support\Str::headline($channelId));
            } else {
                $manifest = $extensions->getManifest($channelId);
                if ($manifest === null) {
                    continue;
                }
                $channel = (string) ($manifest['name'] ?? \Illuminate\Support\Str::headline($channelId));
            }
            if ($user && ! $user->hasPermission($on ? 'view_' . $channelId . '/order' : 'view_sales/order')) {
                continue;
            }

            $name = trim((string) ($names[$row->latest] ?? ''));
            if (str_starts_with(mb_strtolower($name), mb_strtolower($channel) . ':')) {
                $name = trim(mb_substr($name, mb_strlen($channel) + 1));
            }

            $out[$key] = [
                'key' => $key,
                'label' => $name !== '' ? $name : 'Store #' . ($storeId ?? substr($source, strlen($channelId) + 1)),
                'channel' => $channel,
                'source' => $source,
                'store_id' => $storeId,
                'deleted' => $on,
                'channel_off' => ! $on,
                'months' => array_values(array_unique($row->months)),
            ];
        }

        $out = array_values($out);
        usort($out, fn ($a, $b) => [$a['channel'], mb_strtolower($a['label'])] <=> [$b['channel'], mb_strtolower($b['label'])]);

        return $out;
    }

    public function reportStoreOptions(?User $user, ?string $from = null, ?string $to = null, array $selected = []): array
    {
        $fromMonth = $from ? substr($from, 0, 7) : null;
        $toMonth = $to ? substr($to, 0, 7) : null;
        $selected = array_map('strval', $selected);

        $unlisted = array_map(function (array $opt) use ($fromMonth, $toMonth, $selected) {
            $sold = array_filter($opt['months'], fn (string $m) => ($fromMonth === null || $m >= $fromMonth) && ($toMonth === null || $m <= $toMonth));
            $opt['in_period'] = $sold !== [] || in_array($opt['key'], $selected, true);

            return $opt;
        }, $this->unlistedStoreOptions($user));

        return array_merge($this->visibleStoreOptions($user), $unlisted);
    }

    public function visibleStoreOptions(?User $user): array
    {
        $out = [];

        if ($user && ($user->hasPermission('view_sales/order') || $user->hasPermission('manage_sales/order'))) {
            $out[] = ['key' => \App\Support\StoreKey::MANUAL, 'label' => 'Manual orders', 'channel' => 'VentaSync', 'source' => '', 'store_id' => null];
        }

        foreach ($this->providers as $id => $provider) {
            if (! $provider instanceof \App\Integrations\Contracts\StoreOptionsProvider || ! $this->passesGates($id, $user)) {
                continue;
            }

            foreach ($provider->availableStoreOptions() as $opt) {
                if (($opt['permission'] ?? '') !== '' && (! $user || ! $user->hasPermission($opt['permission']))) {
                    continue;
                }
                unset($opt['permission']);
                $out[] = $opt;
            }
        }

        return $out;
    }

    protected function passesGates(string $id, ?User $user): bool
    {
        if (str_starts_with($id, 'core:')) {
            return true;
        }

        $baseId = str_contains($id, ':') ? strstr($id, ':', true) : $id;

        return app(ExtensionManager::class)->isEnabled($baseId);
    }

    public function listingStateSources(): array
    {
        return $this->providersImplementing(\App\Integrations\Contracts\ListingStateSource::class);
    }

    public function productRemovers(): array
    {
        return $this->providersImplementing(\App\Integrations\Contracts\ProductRemover::class);
    }

    public function variationForgetters(): array
    {
        return $this->providersImplementing(\App\Integrations\Contracts\VariationForgetter::class);
    }

    public function webhookReceiverFor(string $segment): ?\App\Integrations\Contracts\WebhookReceiver
    {
        foreach ($this->providersImplementing(\App\Integrations\Contracts\WebhookReceiver::class) as $receiver) {
            if ($receiver->webhookChannel() === $segment) {
                return $receiver;
            }
        }

        return null;
    }
}
