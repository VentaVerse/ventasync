<?php

namespace Extensions\venta;

use App\Extensions\ExtensionProvider;

class VentaExtension extends ExtensionProvider
{
    protected string $id = 'venta';

    public function boot(): void
    {
        parent::boot();

        if ($this->app->runningInConsole()) {
            $this->commands([
                \Extensions\venta\Commands\VentaSync::class,
                \Extensions\venta\Commands\VentaPushStock::class,
                \Extensions\venta\Commands\VentaPushReviews::class,
            ]);
        }
    }
}
