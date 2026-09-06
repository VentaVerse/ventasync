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

    public function test_a_redirect_is_followed_only_to_a_public_host(): void
    {
        // One fake: Laravel keeps every stub registered and the first match
        // wins, so a catch-all first would shadow the specific ones.
        Http::fake([
            'cf.shopee.ph/private.jpg' => Http::response('', 302, ['Location' => 'https://10.0.0.5/steal']),
            'cf.shopee.ph/again.jpg' => Http::response('', 302, ['Location' => 'https://cf.shopee.ph/again.jpg']),
            'p16-oec-va.tiktokcdn.com/*' => Http::response('', 302, ['Location' => 'https://p16-oec-sg.tiktokcdn.com/b.jpg']),
            'p16-oec-sg.tiktokcdn.com/*' => Http::response("\xFF\xD8\xFFjpegbytes", 200, ['Content-Type' => 'application/octet-stream']),
        ]);

        $this->assertNull(RemoteImage::fetch('https://cf.shopee.ph/private.jpg'), 'a hop into a private address is refused');

        // A CDN's hop to a sibling host is followed, with the same check on
        // the new host (TikTok's pictures never arrived, 2026-09-07).
        $got = RemoteImage::fetch('https://p16-oec-va.tiktokcdn.com/a.jpg');
        $this->assertNotNull($got, 'the public hop is followed');
        $this->assertSame('jpg', $got['ext'], 'the bytes say JPEG even when the header says octet-stream');

        // Endless hops stop.
        $this->assertNull(RemoteImage::fetch('https://cf.shopee.ph/again.jpg'));
    }

    public function test_a_non_image_answer_is_refused(): void
    {
        Http::fake(['*' => Http::response('<html>', 200, ['Content-Type' => 'text/html'])]);

        $this->assertNull(RemoteImage::fetch('https://cf.shopee.ph/a.jpg'),
            'an answer that is not an image is not stored as one');
    }
}
