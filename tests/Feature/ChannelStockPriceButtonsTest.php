<?php

namespace Tests\Feature;

use Tests\TestCase;

class ChannelStockPriceButtonsTest extends TestCase
{
    public function test_the_row_menu_draws_sync_stock_and_sync_price_after_send(): void
    {
        $html = view('partials.channel-row-menu', ['menu' => [
            'update' => ['action' => 'https://example.test/send', 'label' => 'Send to Shop'],
            'stock' => ['action' => 'https://example.test/stock', 'label' => 'Sync stock'],
            'price' => ['action' => 'https://example.test/price', 'label' => 'Sync price'],
            'check' => ['action' => 'https://example.test/check', 'label' => 'Check against Shop'],
        ]])->render();

        $this->assertStringContainsString('https://example.test/stock', $html);
        $this->assertStringContainsString('https://example.test/price', $html);
        $this->assertTrue(
            strpos($html, 'Send to Shop') < strpos($html, 'Sync stock')
            && strpos($html, 'Sync stock') < strpos($html, 'Sync price')
            && strpos($html, 'Sync price') < strpos($html, 'Check against Shop')
        );
    }

    public function test_the_selection_bar_draws_them_after_send_with_a_confirm(): void
    {
        $html = view('partials.channel-bulk-bar', ['bulk' => [
            'push' => ['action' => 'https://example.test/send', 'label' => 'Send to Shop'],
            'stock' => ['action' => 'https://example.test/stock', 'confirm' => 'Send the stock?'],
            'price' => ['action' => 'https://example.test/price', 'confirm' => 'Send the price?'],
        ]])->render();

        $this->assertStringContainsString('data-bulk-confirm="Send the stock?"', $html);
        $this->assertStringContainsString('data-bulk-confirm="Send the price?"', $html);
        $this->assertTrue(
            strpos($html, 'Send to Shop') < strpos($html, 'Sync stock')
            && strpos($html, 'Sync stock') < strpos($html, 'Sync price')
        );
    }

    public function test_every_channel_with_the_one_row_menu_wires_them_to_a_route_that_exists(): void
    {
        foreach ([
            'shopee/views/products/index.blade.php',
            'lazada/views/products/index.blade.php',
            'tiktok/views/products/index.blade.php',
            'ventacart/views/listings/index.blade.php',
            'woocommerce/views/listings/index.blade.php',
        ] as $view) {
            $path = base_path('extensions/' . $view);
            $this->assertFileExists($path);
            $src = (string) file_get_contents($path);

            foreach (["'stock' => ['action' => route('", "'price' => ['action' => route('", "\$menu['stock'] = ['action' => route('", "\$menu['price'] = ['action' => route('"] as $wire) {
                $this->assertStringContainsString($wire, $src, "{$view} lacks {$wire}");
                preg_match('/' . preg_quote($wire, '/') . '([a-z0-9_.-]+)/', $src, $m);
                $routes = (string) file_get_contents(base_path('extensions/' . strtok($view, '/') . '/routes/web.php'));
                $this->assertStringContainsString("->name('{$m[1]}')", $routes, "{$view} points at missing route {$m[1]}");
            }
        }
    }
}
