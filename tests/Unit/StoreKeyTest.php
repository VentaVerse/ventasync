<?php

namespace Tests\Unit;

use App\Support\StoreKey;
use PHPUnit\Framework\TestCase;

class StoreKeyTest extends TestCase
{
    public function test_a_bare_channel_with_a_store_id_parses_to_both_clauses(): void
    {
        $this->assertSame(['source' => 'shopee', 'store_id' => 2], StoreKey::parse('shopee:2'));
    }

    public function test_a_source_that_already_names_the_store_parses_to_the_source_alone(): void
    {
        $this->assertSame(['source' => 'opencart:1', 'store_id' => null], StoreKey::parse('opencart:1'));
        $this->assertSame(['source' => 'ventacart:3', 'store_id' => null], StoreKey::parse('ventacart:3'));
    }

    public function test_a_channel_alone_means_every_store_of_it(): void
    {
        $this->assertSame(['source' => 'shopee', 'store_id' => null], StoreKey::parse('shopee'));
    }

    public function test_garbage_is_refused(): void
    {
        $this->assertNull(StoreKey::parse(''));
        $this->assertNull(StoreKey::parse('shopee:abc'));
        $this->assertNull(StoreKey::parse('DROP TABLE'));
    }

    public function test_of_formats_the_pair(): void
    {
        $this->assertSame('shopee:2', StoreKey::of('shopee', 2));
        $this->assertSame('opencart:1', StoreKey::of('opencart:1', 0), 'a source that names its store is left alone');
    }
}
