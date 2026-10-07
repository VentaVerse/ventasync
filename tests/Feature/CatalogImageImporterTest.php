<?php

namespace Tests\Feature;

use App\Services\Media\CatalogImageImporter;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CatalogImageImporterTest extends TestCase
{
    protected function tearDown(): void
    {
        CatalogImageImporter::resolveUsing(null);
        parent::tearDown();
    }

    public function test_a_host_that_resolves_into_the_private_network_is_never_fetched(): void
    {
        Http::fake(['*' => Http::response('secret', 200)]);
        CatalogImageImporter::resolveUsing(fn () => ['10.0.0.5']);

        $importer = app(CatalogImageImporter::class);

        $this->assertFalse($importer->isSafeRemoteUrl('https://photos.example.com/a.png'));
        $this->assertFalse($importer->fetch('https://photos.example.com/a.png'), 'the download checks the link itself');
        Http::assertNothingSent();
    }

    public function test_one_private_address_among_public_ones_is_enough_to_refuse(): void
    {
        Http::fake(['*' => Http::response('secret', 200)]);
        CatalogImageImporter::resolveUsing(fn () => ['93.184.216.34', '192.168.1.10']);

        $this->assertFalse(app(CatalogImageImporter::class)->fetch('https://photos.example.com/a.png'));
        Http::assertNothingSent();
    }

    public function test_loopback_and_mapped_addresses_and_other_schemes_are_refused(): void
    {
        $importer = app(CatalogImageImporter::class);

        $this->assertFalse($importer->isSafeRemoteUrl('http://127.0.0.1/a.png'));
        $this->assertFalse($importer->isSafeRemoteUrl('http://[::ffff:127.0.0.1]/a.png'));
        $this->assertFalse($importer->isSafeRemoteUrl('http://[::1]/a.png'));
        $this->assertFalse($importer->isSafeRemoteUrl('file:///etc/passwd'));
        $this->assertFalse($importer->isSafeRemoteUrl('ftp://photos.example.com/a.png'));
    }

    public function test_a_public_host_is_fetched(): void
    {
        Http::fake(['https://photos.example.com/*' => Http::response('bytes', 200)]);
        CatalogImageImporter::resolveUsing(fn () => ['93.184.216.34']);

        $this->assertSame('bytes', app(CatalogImageImporter::class)->fetch('https://photos.example.com/a.png'));
    }
}
