<?php

namespace Tests\Feature;

use App\Models\ApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProductSearchWordsTest extends TestCase
{
    use RefreshDatabase;

    private int $mosky;

    protected function setUp(): void
    {
        parent::setUp();
        $p = (string) config('catalog.prefix');
        $this->mosky = (int) DB::table($p . 'manufacturer')->insertGetId(['name' => 'Mosky', 'sort_order' => 0, 'image' => '']);

        $this->product('mos-iso-6', 'ISO-6', 'Mosky ISO-6 Pedal Power Supply', $this->mosky);
        $this->product('mos-iso-8', 'ISO-8', 'Mosky Audio ISO-8 Fully Isolated Power supply', $this->mosky);
        $this->product('ammoon-power', 'Power Supply', 'Ammoon Power Supply 8Isolated DC Outputs', 0);
        $trs = $this->product('flat-patch-trs', 'FPT', 'Flat patch cable TRS', 0);
        DB::table($p . 'product_option_value')->insert([
            'product_option_id' => 0, 'product_id' => $trs, 'option_id' => 0, 'option_value_id' => 0, 'sku' => 'flat-patch-trs-30',
            'quantity' => 0, 'subtract' => 1, 'price' => 0, 'price_prefix' => '+', 'points' => 0, 'points_prefix' => '+', 'weight' => 0, 'weight_prefix' => '+',
        ]);
        for ($i = 1; $i <= 30; $i++) {
            $this->product('mos-pedal-' . $i, 'MP' . $i, 'Mosky pedal number ' . $i, $this->mosky);
        }
    }

    private function product(string $sku, string $model, string $name, int $brand): int
    {
        $p = (string) config('catalog.prefix');
        $id = (int) DB::table($p . 'product')->insertGetId([
            'model' => $model, 'sku' => $sku, 'quantity' => 1, 'price' => 100, 'status' => 1, 'image' => '', 'manufacturer_id' => $brand,
            'date_added' => now(), 'date_modified' => now(), 'date_available' => now(),
        ]);
        DB::table($p . 'product_description')->insert([
            'product_id' => $id, 'language_id' => (int) config('catalog.default_language_id'), 'name' => $name,
            'description' => '', 'tag' => '', 'meta_title' => '', 'meta_description' => '', 'meta_keyword' => '',
        ]);

        return $id;
    }

    private function search(array $arguments): array
    {
        $this->app['auth']->forgetGuards();
        $client = ApiClient::create(['name' => 'OpenClaw', 'active' => true, 'mcp_enabled' => true]);
        $token = $client->createToken('assistant', ['products:read'])->plainTextToken;

        return $this->withHeaders(['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json, text/event-stream'])
            ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'products_search', 'arguments' => $arguments]])
            ->json('result.structuredContent');
    }

    private function skus(array $out): array
    {
        return array_column($out['data'], 'sku');
    }

    public function test_spaces_and_hyphens_do_not_matter(): void
    {
        foreach (['ISO 8', 'ISO-8', 'iso8', 'Iso_8'] as $typed) {
            $this->assertSame('mos-iso-8', $this->skus($this->search(['search' => $typed]))[0] ?? null, "\"{$typed}\" finds ISO-8 first");
        }
    }

    public function test_every_word_counts_in_any_order_and_the_brand_is_searched(): void
    {
        $this->assertSame(['mos-iso-8'], $this->skus($this->search(['search' => 'Mosky ISO 8'])));
        $this->assertSame(['mos-iso-8'], $this->skus($this->search(['search' => '8 iso mosky'])));
        $this->assertSame([], $this->skus($this->search(['search' => 'Mosky ISO 12'])), 'every word must be found');
    }

    public function test_a_variation_sku_finds_its_product(): void
    {
        $this->assertSame(['flat-patch-trs'], $this->skus($this->search(['search' => 'flat patch trs 30'])));
    }

    public function test_an_exact_sku_or_model_comes_first(): void
    {
        $out = $this->search(['search' => 'iso 6']);

        $this->assertSame('mos-iso-6', $this->skus($out)[0]);
    }

    public function test_a_long_list_is_reachable_page_by_page(): void
    {
        $first = $this->search(['search' => 'Mosky', 'per_page' => 25]);
        $this->assertSame(32, $first['total']);
        $this->assertSame(2, $first['last_page']);

        $second = $this->search(['search' => 'Mosky', 'per_page' => 25, 'page' => 2]);
        $this->assertCount(7, $second['data']);
        $this->assertSame([], array_intersect($this->skus($first), $this->skus($second)), 'page 2 is not page 1 again');
        $this->assertContains('mos-iso-8', array_merge($this->skus($first), $this->skus($second)));
    }
}
