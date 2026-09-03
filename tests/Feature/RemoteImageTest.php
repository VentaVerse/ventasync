<?php

namespace Tests\Feature;

use App\Support\RemoteImage;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The importers' shared image fetcher is an SSRF guard (2026-09-04,
 * from the security review): the URL is the CHANNEL's data, so it must
 * never reach anything but a public https host, and never follow a
 * redirect that could hop inside after the check.
 */
class RemoteImageTest extends TestCase
{
    public function test_only_public_https_urls_are_fetched(): void
    {
        Http::fake(['*' => Http::response('img', 200, ['Content-Type' => 'image/jpeg'])]);

        $this->assertNull(RemoteImage::fetch('http://cdn.example.com/a.jpg'), 'plain http is refused');
        $this->assertNull(RemoteImage::fetch('https://127.0.0.1/a.jpg'), 'loopback is refused');
        $this->assertNull(RemoteImage::fetch('https://10.0.0.5/a.jpg'), 'a private address is refused');
        $this->assertNull(RemoteImage::fetch('https://169.254.169.254/latest/meta-data'), 'the metadata service is refused');
        $this->assertNull(RemoteImage::fetch('ftp://cdn.example.com/a.jpg'), 'other schemes are refused');

        Http::assertNothingSent();
    }

    public function test_a_redirect_is_not_followed(): void
    {
        Http::fake(['*' => Http::response('', 302, ['Location' => 'https://10.0.0.5/steal'])]);

        // 302 is not successful() with redirects off, so nothing comes back.
        $this->assertNull(RemoteImage::fetch('https://cf.shopee.ph/a.jpg'));
    }

    public function test_a_non_image_answer_is_refused(): void
    {
        Http::fake(['*' => Http::response('<html>', 200, ['Content-Type' => 'text/html'])]);

        $this->assertNull(RemoteImage::fetch('https://cf.shopee.ph/a.jpg'),
            'an answer that is not an image is not stored as one');
    }
}
