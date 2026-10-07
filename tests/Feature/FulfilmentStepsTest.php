<?php

namespace Tests\Feature;

use App\Support\FulfilmentSteps;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class FulfilmentStepsTest extends TestCase
{
    public function test_a_channels_own_keys_survive_and_only_the_words_are_shared(): void
    {
        $tabs = FulfilmentSteps::tabs([
            'CANCELLATION' => 'cancelled',
            'ALL' => 'all',
        ]);

        $this->assertArrayHasKey('CANCELLATION', $tabs);
        $this->assertSame('Cancelled', $tabs['CANCELLATION']);
    }

    public function test_the_strip_comes_out_in_lifecycle_order_however_it_was_declared(): void
    {
        $tabs = FulfilmentSteps::tabs([
            'X_FAILED' => 'failed',
            'X_DELIVERED' => 'delivered',
            'X_ALL' => 'all',
            'X_TO_SHIP' => 'to_ship',
        ]);

        $this->assertSame(
            ['X_ALL', 'X_TO_SHIP', 'X_DELIVERED', 'X_FAILED'],
            array_keys($tabs),
            'declaration order must not decide what a packer reads first'
        );
    }

    public function test_an_unmapped_status_keeps_its_place_at_the_end_rather_than_disappearing(): void
    {
        $ordered = FulfilmentSteps::order([
            'Delivered' => 9,
            'Some Store Invented This' => 3,
            'Start Pickup' => 4,
            'And This' => 1,
        ], 'ventacart.orders');

        $this->assertSame(
            ['Start Pickup', 'Delivered', 'Some Store Invented This', 'And This'],
            array_keys($ordered)
        );

        $this->assertSame(4, $ordered['Start Pickup'], 'the counts must ride with their statuses');
    }

    public function test_the_work_comes_before_the_terminal_states(): void
    {
        $ordered = array_keys(FulfilmentSteps::order([
            'Delivered' => 128,
            'Canceled' => 6,
            'Start Pickup' => 1,
            'Shipped' => 2,
            'In Transit to Manila' => 1,
        ], 'ventacart.orders'));

        $this->assertSame('Start Pickup', $ordered[0],
            'the one step that is somebody\'s move must not sit behind Delivered and Cancelled');
        $this->assertSame('Canceled', $ordered[array_key_last($ordered)]);
    }

    public function test_a_status_is_matched_however_the_store_spelled_it(): void
    {
        $this->assertSame('to_handover', FulfilmentSteps::stepFor('ventacart.orders', 'Start Pickup'));
        $this->assertSame('to_handover', FulfilmentSteps::stepFor('ventacart.orders', 'START_PICKUP'));
        $this->assertSame('to_handover', FulfilmentSteps::stepFor('ventacart.orders', '  start pickup '));
        $this->assertNull(FulfilmentSteps::stepFor('ventacart.orders', 'Nothing Knows This'));
    }

    public function test_bucket_sorts_raw_statuses_into_every_step_with_zeros_kept(): void
    {
        $buckets = FulfilmentSteps::bucket('ventacart.orders', [
            'Processing' => 3,
            'Order Processed' => 2,
            'Start Pickup' => 1,
            'Delivered' => 40,
            'Some Store Invented This' => 5,
        ]);

        $this->assertSame(FulfilmentSteps::placements(), array_keys($buckets));

        $this->assertSame(5, $buckets['to_pack']['count']);
        $this->assertSame(['Processing', 'Order Processed'], $buckets['to_pack']['statuses']);
        $this->assertSame(1, $buckets['to_handover']['count']);
        $this->assertSame(['Start Pickup'], $buckets['to_handover']['statuses']);
        $this->assertSame(40, $buckets['delivered']['count']);

        $this->assertSame(0, $buckets['unpaid']['count'], 'a step with nothing in it is still a step');
        $this->assertSame([], $buckets['unpaid']['statuses']);

        $this->assertSame(5, $buckets['other']['count']);
        $this->assertSame(['Some Store Invented This'], $buckets['other']['statuses']);
    }

    public function test_the_storefront_places_pending_under_unpaid_and_shipped_under_shipping(): void
    {
        $this->assertSame('unpaid', FulfilmentSteps::stepFor('ventacart.orders', 'Pending'));
        $this->assertSame('to_pack', FulfilmentSteps::stepFor('ventacart.orders', 'Order Processed'));
        $this->assertSame('to_handover', FulfilmentSteps::stepFor('ventacart.orders', 'Order Being Shipped'));
        $this->assertSame('shipping', FulfilmentSteps::stepFor('ventacart.orders', 'In Transit to Manila'));
        $this->assertSame('cancelled', FulfilmentSteps::stepFor('ventacart.orders', 'Canceled'));
        $this->assertSame('failed', FulfilmentSteps::stepFor('ventacart.orders', 'Returned'));
    }

    public function test_no_channel_writes_its_own_word_for_a_shared_step(): void
    {
        $services = [
            'shopee' => 'extensions/shopee/Services/ShopeeOrdersPanel.php',
            'lazada' => 'extensions/lazada/Services/LazadaOrdersPanel.php',
            'tiktok' => 'extensions/tiktok/Services/TikTokOrdersPanel.php',
        ];

        $offenders = [];

        foreach ($services as $channel => $path) {
            $source = File::get(base_path($path));

            if (! str_contains($source, 'FulfilmentSteps::tabs(')) {
                $offenders[] = $channel.' (builds its strip without the shared vocabulary)';
                continue;
            }

            preg_match('/FulfilmentSteps::tabs\(\[(.*?)\]\)/s', $source, $m);

            foreach (['To Ship', 'Cancelled', 'Cancellation', 'Failed Delivery', 'Delivered'] as $word) {
                if (isset($m[1]) && str_contains($m[1], "'".$word."'")) {
                    $offenders[] = $channel.' (hard-codes "'.$word.'")';
                }
            }
        }

        $this->assertSame([], $offenders, sprintf(
            "These channels do not take their step wording from FulfilmentSteps: %s\n\n"
            ."Map the channel's own key to a shared step ('CANCELLATION' => 'cancelled').\n"
            ."The key is the marketplace's filter value and stays; only the word is shared.",
            implode(', ', $offenders)
        ));
    }
}
