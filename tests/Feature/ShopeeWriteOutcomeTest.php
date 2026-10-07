<?php

namespace Tests\Feature;

use Extensions\shopee\Controllers\ShopeeProductGroupController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\TestCase;

class ShopeeWriteOutcomeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        \Extensions\shopee\Models\ShopeeSetting::create(['mode' => 'live', 'store_name' => 'Main store']);
    }

    private const CONTROLLER = 'extensions/shopee/Controllers/ShopeeProductGroupController.php';

    private function invokePrivate(string $method, array $args)
    {
        $ref = new ReflectionMethod(ShopeeProductGroupController::class, $method);

        return $ref->invokeArgs(app(ShopeeProductGroupController::class), $args);
    }

    private function source(): string
    {
        return file_get_contents(base_path(self::CONTROLLER));
    }

    public function test_a_clean_batch_is_a_success(): void
    {
        $this->assertSame('status', $this->invokePrivate('batchTone', [12, 0]));
    }

    public function test_a_batch_that_lost_rows_is_not_a_success(): void
    {
        $this->assertSame('warning', $this->invokePrivate('batchTone', [8, 4]),
            'A partly failed batch still wears the success colour.');
    }

    public function test_a_batch_that_failed_entirely_is_an_error(): void
    {
        $this->assertSame('error', $this->invokePrivate('batchTone', [0, 12]));
    }

    public function test_an_empty_batch_is_not_reported_as_a_failure(): void
    {
        $this->assertSame('status', $this->invokePrivate('batchTone', [0, 0]));
    }

    public function test_a_failure_message_prefers_shopees_own_words(): void
    {
        $this->assertSame(
            'The channel cannot be found in the shop.',
            $this->invokePrivate('apiFailureMessage', [[
                'body' => ['error' => 'error_param', 'message' => 'The channel cannot be found in the shop.'],
            ]])
        );
    }

    public function test_a_failure_message_falls_back_to_the_error_code(): void
    {
        $this->assertSame(
            'product.error_item_not_found',
            $this->invokePrivate('apiFailureMessage', [['body' => ['error' => 'product.error_item_not_found', 'message' => '']]])
        );
    }

    public function test_a_failure_message_is_never_blank(): void
    {
        foreach ([[], ['body' => []], ['body' => ['error' => '', 'message' => '']], ['body' => 'not an array']] as $result) {
            $this->assertNotSame('', $this->invokePrivate('apiFailureMessage', [$result]));
        }
    }

    public function test_a_failed_product_is_marked_on_its_own_row(): void
    {
        $group = $this->group();
        $this->pivotFor($group, 2708, ['sync_status' => 'pushed']);

        $this->invokePrivate('recordSyncOutcomes', [$group, [
            2708 => ['ok' => false, 'error' => 'Item_id is not found.'],
        ]]);

        $row = DB::table('shopee_product_group_products')->where('product_id', 2708)->first();

        $this->assertSame('error', $row->sync_status);
        $this->assertSame('Item_id is not found.', $row->push_error);
        $this->assertNotNull($row->last_pushed_at, 'A write that reached Shopee did not stamp the time.');
    }

    public function test_a_successful_product_clears_the_previous_reason(): void
    {
        $group = $this->group();
        $this->pivotFor($group, 2708, ['sync_status' => 'error', 'push_error' => 'yesterday complaint']);

        $this->invokePrivate('recordSyncOutcomes', [$group, [2708 => ['ok' => true, 'error' => null]]]);

        $row = DB::table('shopee_product_group_products')->where('product_id', 2708)->first();

        $this->assertSame('pushed', $row->sync_status);
        $this->assertNull($row->push_error, 'A fixed product keeps displaying its old failure.');
    }

    public function test_a_result_reaches_every_group_holding_that_product(): void
    {
        $one = $this->group();
        $two = $this->group();
        $this->pivotFor($one, 2708, ['sync_status' => 'pushed']);
        $this->pivotFor($two, 2708, ['sync_status' => 'pushed']);

        $this->invokePrivate('recordSyncOutcomes', [$one, [
            2708 => ['ok' => false, 'error' => 'Item_id is not found.'],
        ]]);

        foreach ([$one, $two] as $group) {
            $row = DB::table('shopee_product_group_products')
                ->where('shopee_product_group_id', $group)
                ->where('product_id', 2708)
                ->first();

            $this->assertSame('error', $row->sync_status,
                'A group holding this product was left claiming success after a failure elsewhere.');
            $this->assertSame('Item_id is not found.', $row->push_error);
        }
    }

    public function test_a_later_success_clears_the_error_in_every_group(): void
    {
        $one = $this->group();
        $two = $this->group();
        $this->pivotFor($one, 2708, ['sync_status' => 'error', 'push_error' => 'Category not found']);
        $this->pivotFor($two, 2708, ['sync_status' => 'pushed']);

        $this->invokePrivate('recordSyncOutcomes', [$two, [2708 => ['ok' => true, 'error' => null]]]);

        $stale = DB::table('shopee_product_group_products')
            ->where('shopee_product_group_id', $one)
            ->where('product_id', 2708)
            ->first();

        $this->assertSame('pushed', $stale->sync_status,
            'A group kept a red badge after the product pushed successfully from another group.');
        $this->assertNull($stale->push_error);
    }

    public function test_no_push_path_flashes_success_unconditionally(): void
    {
        $source = $this->source();

        foreach (["'Sync Price:", "'Sync Qty:", '"Sync Price:', '"Sync Qty:'] as $needle) {
            if (! str_contains($source, $needle)) {
                continue;
            }

            $before = substr($source, 0, strpos($source, $needle));
            $tail = substr($before, -400);

            $this->assertStringNotContainsString("->with('status'", $tail,
                'A price or stock push flashes success without consulting batchTone, '
                .'so a batch that failed will render green.');
        }

        $this->assertStringContainsString('$this->batchTone(', $source,
            'batchTone is gone, so nothing is choosing the flash key from the outcome.');
    }

    public function test_both_push_helpers_report_per_product_outcomes(): void
    {
        $source = $this->source();

        $this->assertSame(
            2,
            substr_count($source, "return ['ok' => \$ok, 'err' => \$err, 'outcomes' => \$outcomes];"),
            'A push helper stopped reporting which products failed, so its failures cannot reach the row.'
        );

        $this->assertSame(
            2,
            substr_count($source, '$this->recordSyncOutcomes('),
            'A push caller stopped writing outcomes to the pivot.'
        );
    }

    public function test_deletion_failures_keep_their_reason(): void
    {
        $this->assertStringContainsString(
            "'push_error' => Str::limit('Delete from Shopee failed. ' . \$msg, 480)",
            $this->source(),
            'Deleting from Shopee counts its failures and discards why, so the failed rows cannot be identified.'
        );
    }

    public function test_relinking_without_a_link_does_not_claim_success(): void
    {
        $source = $this->source();

        $this->assertStringContainsString("'sync_status' => 'pending', 'push_error' => null", $source);
        $this->assertStringNotContainsString("->with('status', 'Product re-linked.')", $source,
            'Re-link reports success on the branch where it found no link and set the product back to not pushed.');
    }

    private function group(): int
    {
        return DB::table('shopee_product_groups')->insertGetId([
            'shopee_setting_id' => \Extensions\shopee\Models\ShopeeSetting::query()->orderBy('id')->value('id')
                ?? \Extensions\shopee\Models\ShopeeSetting::create(['mode' => 'live', 'store_name' => 'Main store'])->id,
            'name' => 'Mosky',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function pivotFor(int $group, int $productId, array $attrs = []): void
    {
        DB::table('shopee_product_group_products')->insert(array_merge([
            'shopee_product_group_id' => $group,
            'product_id' => $productId,
        ], $attrs));
    }
}
