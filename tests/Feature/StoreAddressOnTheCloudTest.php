<?php

namespace Tests\Feature;

use App\Rules\StoreAddress;
use App\Support\Net\PublicAddress;
use App\Support\Net\StoreRequest;
use Extensions\opencart\Models\OpenCartSetting;
use Extensions\opencart\Services\OpenCart\OpenCartClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class StoreAddressOnTheCloudTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        PublicAddress::resolveUsing(null);
        parent::tearDown();
    }

    private function hosted(): void
    {
        config(['plans.limits.products' => 1000]);
    }

    private function resolvesTo(string $ip): void
    {
        PublicAddress::resolveUsing(fn () => [$ip]);
    }

    public function test_self_hosted_accepts_any_store_address(): void
    {
        foreach (['http://127.0.0.1:9001', 'http://192.168.1.20/shop', 'https://shop.example.com'] as $url) {
            $this->assertNull(StoreRequest::refusal($url), $url);
        }
    }

    public function test_a_hosted_server_refuses_private_local_and_plain_http_addresses(): void
    {
        $this->hosted();

        foreach ([
            'https://127.0.0.1', 'https://10.0.0.5', 'https://169.254.169.254/latest', 'https://[::1]',
            'https://[::ffff:7f00:1]', 'https://[::ffff:127.0.0.1]', 'https://[::ffff:a00:5]', 'https://[64:ff9b::a9fe:a9fe]',
            'https://[::a00:5]', 'https://100.64.1.1', 'https://198.18.0.1', 'https://192.0.0.8', 'https://[fd00::1]',
        ] as $url) {
            $this->assertSame(StoreRequest::REFUSED, StoreRequest::refusal($url), $url);
        }

        $this->resolvesTo('192.168.1.10');
        $this->assertSame(StoreRequest::REFUSED, StoreRequest::refusal('https://looks-public.example.com'), 'a name that resolves inside');

        $this->resolvesTo('93.184.216.34');
        $this->assertSame(StoreRequest::REFUSED, StoreRequest::refusal('http://shop.example.com'), 'plain http');
        $this->assertNull(StoreRequest::refusal('https://shop.example.com'));
    }

    public function test_the_form_rule_says_why_and_accepts_a_bare_domain(): void
    {
        $this->hosted();
        $this->resolvesTo('10.1.2.3');
        $this->assertTrue(Validator::make(['base_url' => 'https://inside.example.com'], ['base_url' => [new StoreAddress]])->fails());

        $this->resolvesTo('93.184.216.34');
        $this->assertFalse(Validator::make(['shop_domain' => 'acme.myshopify.com'], ['shop_domain' => [new StoreAddress]])->fails());
    }

    public function test_a_store_client_on_a_hosted_server_never_sends_to_a_private_address(): void
    {
        $this->hosted();
        Http::fake();
        $setting = OpenCartSetting::create(['store_name' => 'Inside', 'base_url' => 'http://127.0.0.1:9001', 'api_key' => 'k', 'enabled' => true]);

        $result = (new OpenCartClient($setting))->ping();

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('not allowed on a hosted server', json_encode($result['body']));
        Http::assertNothingSent();
    }
}
