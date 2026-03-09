<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\View;

class ThemeServiceProvider extends ServiceProvider
{
    protected array $modernViews = [
        'dashboard'                   => 'modern.dashboard',
        'lazada.orders.index'         => 'modern.lazada.orders.index',
        'lazada.products.index'       => 'modern.lazada.products.index',
        'shopee.orders.index'         => 'modern.shopee.orders.index',
        'shopee.products.index'       => 'modern.shopee.products.index',
        'products.index'              => 'modern.products.index',
        'products.create'             => 'modern.products.form',
        'products.edit'               => 'modern.products.form',
    ];

    public function boot(): void
    {
        View::composer('*', function ($view) {
            if (session('theme') !== 'modern') {
                return;
            }
            $name = $view->getName();
            if (isset($this->modernViews[$name])) {
                $modern = $this->modernViews[$name];
                if (view()->exists($modern)) {
                    $view->setPath(view($modern)->getPath());
                }
            }
        });
    }
}
