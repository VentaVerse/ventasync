<?php

namespace Tests\Feature;

use App\Support\ChannelProductStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * One product, one status, on every page that shows it.
 *
 * sync_status and push_error lived on the group pivot, one row per group per
 * product, but whether a product is on the marketplace is a GLOBAL fact. The
 * same product therefore read differently depending on which group you opened.
 *
 * Demonstrated before it was fixed: product 1799 sits in Venta groups 4 and 8.
 * Marking group 4 failed, then pushing successfully from group 8, left group 4
 * reading "failed - Category not found" permanently.
 *
 * Both directions matter and the quiet one is worse. A stale error sends the
 * operator chasing a problem that no longer exists. A stale SUCCESS leaves a
 * green badge hiding a real failure, and that is the one that gets ignored.
 */
class ChannelProductStatusTest extends TestCase
{
    use RefreshDatabase;

    /** A real group row, because the pivot has a foreign key to one. */
    private function makeGroup(): int
    {
        return DB::table('shopee_product_groups')->insertGetId([
            'name' => 'Group '.uniqid('', true),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedPivot(int $groupId, int $productId, string $status, ?string $error = null): void
    {
        DB::table('shopee_product_group_products')->insert([
            'shopee_product_group_id' => $groupId,
            'product_id' => $productId,
            'sync_status' => $status,
            'push_error' => $error,
        ]);
    }

    private function statusIn(int $groupId, int $productId): ?object
    {
        return DB::table('shopee_product_group_products')
            ->where('shopee_product_group_id', $groupId)
            ->where('product_id', $productId)
            ->first();
    }

    private function write(?array $groupIds, int $productId, array $attributes): int
    {
        return ChannelProductStatus::write(
            'shopee_product_group_products',
            'shopee_product_group_id',
            $groupIds,
            $productId,
            $attributes
        );
    }

    /** A null scope means every group, which is right for a single-store channel. */
    public function test_an_unscoped_write_reaches_every_group(): void
    {
        [$a, $b] = [$this->makeGroup(), $this->makeGroup()];
        $this->seedPivot($a, 500, 'pushed');
        $this->seedPivot($b, 500, 'pushed');

        $this->write(null, 500, ['sync_status' => 'error', 'push_error' => 'gone']);

        $this->assertSame('error', $this->statusIn($a, 500)->sync_status);
        $this->assertSame('error', $this->statusIn($b, 500)->sync_status,
            'A group holding this product was left claiming success after a failure elsewhere.');
    }

    /**
     * A multi-store channel scopes to its own store's groups. A result against
     * one shop says nothing about another, and writing across them would make
     * one storefront's failure appear on a different storefront's page.
     */
    public function test_a_scoped_write_stops_at_the_stores_own_groups(): void
    {
        [$mine, $theirs] = [$this->makeGroup(), $this->makeGroup()];
        $this->seedPivot($mine, 500, 'pushed');
        $this->seedPivot($theirs, 500, 'pushed');

        $this->write([$mine], 500, ['sync_status' => 'error']);

        $this->assertSame('error', $this->statusIn($mine, 500)->sync_status);
        $this->assertSame('pushed', $this->statusIn($theirs, 500)->sync_status,
            "Another store's page was changed by a result that has nothing to do with it.");
    }

    public function test_other_products_are_untouched(): void
    {
        $g = $this->makeGroup();
        $this->seedPivot($g, 500, 'pushed');
        $this->seedPivot($g, 501, 'pushed');

        $this->write(null, 500, ['sync_status' => 'error']);

        $this->assertSame('pushed', $this->statusIn($g, 501)->sync_status);
    }

    /** The healing direction, which is the one that clears a stale red badge. */
    public function test_a_success_clears_an_earlier_failure_everywhere(): void
    {
        [$stale, $other] = [$this->makeGroup(), $this->makeGroup()];
        $this->seedPivot($stale, 500, 'error', 'Category not found');
        $this->seedPivot($other, 500, 'pushed');

        $this->write(null, 500, ['sync_status' => 'pushed', 'push_error' => null]);

        $this->assertSame('pushed', $this->statusIn($stale, 500)->sync_status);
        $this->assertNull($this->statusIn($stale, 500)->push_error,
            'A stale failure survived a later success.');
    }

    /**
     * An empty scope is not the same as no scope. A caller that resolved zero
     * groups must write nothing, rather than falling through to every group in
     * the table - which on a multi-store channel would be every shop.
     */
    public function test_an_empty_scope_writes_nothing(): void
    {
        $g = $this->makeGroup();
        $this->seedPivot($g, 500, 'pushed');

        $this->assertSame(0, $this->write([], 500, ['sync_status' => 'error']));
        $this->assertSame('pushed', $this->statusIn($g, 500)->sync_status);
    }

    /**
     * The reason is capped once, here, whichever caller supplies it. The
     * column is TEXT so this is a readability limit rather than a storage
     * one, and Str::limit adds its own ellipsis on top of the cap.
     */
    public function test_a_long_reason_is_truncated_once_here(): void
    {
        $g = $this->makeGroup();
        $this->seedPivot($g, 500, 'pushed');

        $this->write(null, 500, ['sync_status' => 'error', 'push_error' => str_repeat('x', 900)]);

        $stored = $this->statusIn($g, 500)->push_error;

        $this->assertLessThan(500, mb_strlen($stored), 'The reason was stored unbounded.');
        $this->assertStringEndsWith('...', $stored, 'Truncation is not signposted to the reader.');
    }

    /**
     * The ratchet. Every channel's status write goes through this one method,
     * because fanning out is not a rule the next author can be relied on to
     * remember - that rule is exactly what was being forgotten.
     */
    public function test_every_channel_routes_its_status_writes_through_one_place(): void
    {
        foreach (['shopee', 'lazada', 'tiktok', 'venta'] as $channel) {
            $path = glob(base_path("extensions/{$channel}/Controllers/*ProductGroupController.php"))[0] ?? null;

            $this->assertNotNull($path, "No product-group controller found for {$channel}.");

            $this->assertStringContainsString('ChannelProductStatus::write(', file_get_contents($path),
                "{$channel} writes product status without going through the shared writer, so its "
                .'groups can disagree with each other again.');
        }
    }
}
