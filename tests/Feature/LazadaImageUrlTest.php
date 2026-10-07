<?php

namespace Tests\Feature;

use Extensions\lazada\Services\Lazada\LazadaImages;
use Tests\TestCase;

class LazadaImageUrlTest extends TestCase
{
    private function display(?string $path): ?string
    {
        return app(LazadaImages::class)->toDisplayImageUrl($path);
    }

    private function payload(?string $path): ?string
    {
        return app(LazadaImages::class)->toPublicImageUrl($path);
    }

    public function test_a_display_url_carries_no_host(): void
    {
        config(['catalog.public_url' => 'http://192.168.122.8:8000']);

        $url = $this->display('catalog/Products/thing.jpg');

        $this->assertNotNull($url);
        $this->assertStringStartsWith('/', $url, 'A display url is not root-relative.');
        $this->assertStringNotContainsString('192.168.122.8', $url,
            'The listing builds image urls against a configured host, so it breaks whenever the '
            .'operator reaches the ERP at a different address.');
        $this->assertStringNotContainsString('http', $url);
    }

    public function test_a_display_url_does_not_follow_the_configured_storefront(): void
    {
        config(['catalog.public_url' => 'https://shop.example.com']);
        $a = $this->display('catalog/thing.jpg');

        config(['catalog.public_url' => 'https://somewhere-else.example.net']);
        $b = $this->display('catalog/thing.jpg');

        $this->assertSame($a, $b, 'The admin listing image url moved when the storefront url changed.');
    }

    public function test_a_payload_url_still_carries_a_host_for_lazada(): void
    {
        config(['catalog.public_url' => 'https://shop.example.com']);

        $this->assertStringStartsWith('https://shop.example.com/', (string) $this->payload('catalog/thing.jpg'),
            'The Lazada payload lost its absolute url, so the marketplace cannot fetch the image.');
    }

    public function test_a_remote_url_is_left_alone(): void
    {
        $url = 'https://lzd-img-global.slatic.net/g/p/abc.jpg';

        $this->assertSame($url, $this->display($url));
    }

    public function test_a_loopback_url_is_rewritten_to_a_path(): void
    {
        $this->assertSame('/storage/catalog/thing.jpg', $this->display('http://localhost/storage/catalog/thing.jpg'));
        $this->assertSame('/storage/catalog/thing.jpg', $this->display('http://127.0.0.1:8000/storage/catalog/thing.jpg'));
    }

    public function test_nothing_stored_yields_nothing(): void
    {
        foreach ([null, '', '   '] as $empty) {
            $this->assertNull($this->display($empty));
        }
    }

    public function test_traversal_is_refused(): void
    {
        $this->assertNull($this->display('../../.env'));
        $this->assertNull($this->display('http://localhost/../../.env'));
        $this->assertNull($this->payload('../../.env'));
    }

    public function test_a_filename_with_spaces_is_encoded(): void
    {
        $url = $this->display('catalog/Empress effects/DRIV-Front-Web.png');

        $this->assertStringContainsString('Empress%20effects', (string) $url);
        $this->assertStringNotContainsString(' ', (string) $url);
    }

    public function test_an_already_prefixed_path_is_not_prefixed_twice(): void
    {
        $this->assertSame('/storage/catalog/thing.jpg', $this->display('storage/catalog/thing.jpg'));
        $this->assertSame('/image/catalog/thing.jpg', $this->display('image/catalog/thing.jpg'));
    }
}
