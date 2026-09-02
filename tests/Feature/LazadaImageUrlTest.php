<?php

namespace Tests\Feature;

use Extensions\lazada\Services\Lazada\LazadaImages;
use Tests\TestCase;

/**
 * Two audiences for one image, and they need opposite urls.
 *
 * Lazada's servers fetch product images over the internet, so the payload
 * needs an ABSOLUTE url with a host, and that host comes from
 * catalog.public_url. The admin listing page is fetched by the operator's own
 * browser, which is already on the right host and needs nothing but a path.
 *
 * One resolver served both, so the listing asked a config setting a question
 * it already knew the answer to. catalog.public_url was pointing at
 * 192.168.122.8:8000, an address from an earlier network, and every thumbnail
 * on the page was requested from a host the browser could not reach: 2,074
 * products with images on disk rendered as empty frames.
 *
 * Correcting the setting would have fixed it that day and broken it again on
 * the next address change. These tests pin the shape instead: a display url
 * carries no host, so it cannot drift from the address the operator used.
 */
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

    /** The defect itself: a host the operator's browser may not be able to reach. */
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

    /** Changing where the storefront lives must not move the admin page's images. */
    public function test_a_display_url_does_not_follow_the_configured_storefront(): void
    {
        config(['catalog.public_url' => 'https://shop.example.com']);
        $a = $this->display('catalog/thing.jpg');

        config(['catalog.public_url' => 'https://somewhere-else.example.net']);
        $b = $this->display('catalog/thing.jpg');

        $this->assertSame($a, $b, 'The admin listing image url moved when the storefront url changed.');
    }

    /** The other half of the split must keep its host, or the marketplace cannot fetch it. */
    public function test_a_payload_url_still_carries_a_host_for_lazada(): void
    {
        config(['catalog.public_url' => 'https://shop.example.com']);

        $this->assertStringStartsWith('https://shop.example.com/', (string) $this->payload('catalog/thing.jpg'),
            'The Lazada payload lost its absolute url, so the marketplace cannot fetch the image.');
    }

    /** A genuinely remote image (a marketplace cdn) is somebody else's and is kept. */
    public function test_a_remote_url_is_left_alone(): void
    {
        $url = 'https://lzd-img-global.slatic.net/g/p/abc.jpg';

        $this->assertSame($url, $this->display($url));
    }

    /** Loopback is the one absolute url no other machine's browser can follow. */
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

    /** Path traversal never becomes a url, on either side of the split. */
    public function test_traversal_is_refused(): void
    {
        $this->assertNull($this->display('../../.env'));
        $this->assertNull($this->display('http://localhost/../../.env'));
        $this->assertNull($this->payload('../../.env'));
    }

    /** Spaces and punctuation in real filenames must survive as a fetchable url. */
    public function test_a_filename_with_spaces_is_encoded(): void
    {
        $url = $this->display('catalog/Empress effects/DRIV-Front-Web.png');

        $this->assertStringContainsString('Empress%20effects', (string) $url);
        $this->assertStringNotContainsString(' ', (string) $url);
    }

    /** A path that already names its public prefix is not given a second one. */
    public function test_an_already_prefixed_path_is_not_prefixed_twice(): void
    {
        $this->assertSame('/storage/catalog/thing.jpg', $this->display('storage/catalog/thing.jpg'));
        $this->assertSame('/image/catalog/thing.jpg', $this->display('image/catalog/thing.jpg'));
    }
}
