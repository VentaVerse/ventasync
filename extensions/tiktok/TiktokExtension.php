<?php

namespace Extensions\tiktok;

use App\Extensions\ExtensionProvider;
use App\Integrations\IntegrationCard;
use App\Integrations\IntegrationProvider;
use App\Integrations\IntegrationRegistry;
use App\Integrations\MenuItem;
use App\Integrations\OrderTab;
use Extensions\tiktok\Models\TikTokOrder;
use Illuminate\Support\Facades\Schema;

class TiktokExtension extends ExtensionProvider implements IntegrationProvider
{
    protected string $id = 'tiktok';

    public function boot(): void
    {
        parent::boot();

        $this->app->make(IntegrationRegistry::class)->register($this);
    }

    public function integrationId(): string
    {
        return $this->id;
    }

    public function integrationCard(): ?IntegrationCard
    {
        return new IntegrationCard(
            id: $this->id,
            name: 'TikTok Shop',
            tagline: 'TikTok Shop integration — OAuth, product sync, orders.',
            icon: 'video',
            accent: '#fe2c55',
            permission: 'manage_tiktok',
            menu: [
                new MenuItem('Settings', 'ext.tiktok.index', 'manage_tiktok'),
                new MenuItem('Product Groups', 'ext.tiktok.product-groups.index', 'manage_tiktok'),
                new MenuItem('Orders', 'ext.tiktok.orders.index', 'manage_tiktok_orders'),
            ],
        );
    }

    public function orderTab(): ?OrderTab
    {
        return new OrderTab(
            id: $this->id,
            label: 'TikTok',
            icon: 'video',
            accent: '#fe2c55',
            routeName: 'ext.tiktok.orders.index',
            permission: 'manage_tiktok_orders',
            unprocessedCounter: function () {
                if (!Schema::hasTable('tiktok_orders')) {
                    return 0;
                }
                return TikTokOrder::query()->where('status', 'AWAITING_SHIPMENT')->count();
            },
        );
    }
}
