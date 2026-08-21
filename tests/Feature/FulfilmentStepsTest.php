<?php

namespace Tests\Feature;

use App\Support\FulfilmentSteps;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * One vocabulary for the lifecycle, across four panels presented as one
 * surface.
 *
 * Lazada said "Cancellation" where Shopee said "Cancelled", for the same
 * step, in the same shift. The storefront's strip came out of a GROUP BY in
 * count order, so its one actionable step sat fourth, behind two terminal
 * states, where every other channel puts the work third.
 */
class FulfilmentStepsTest extends TestCase
{
    public function test_a_channels_own_keys_survive_and_only_the_words_are_shared(): void
    {
        $tabs = FulfilmentSteps::tabs([
            'CANCELLATION' => 'cancelled',
            'ALL' => 'all',
        ]);

        // Lazada's filter value is untouched; this class has no business
        // renaming a marketplace's own constants.
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

    /**
     * The storefront case. A store can add OpenCart statuses this map has
     * never heard of, and an unknown status must not vanish from the strip
     * or be filed under a step it does not belong to.
     */
    public function test_an_unmapped_status_keeps_its_place_at_the_end_rather_than_disappearing(): void
    {
        $ordered = FulfilmentSteps::order([
            'Delivered' => 9,
            'Some Store Invented This' => 3,
            'Start Pickup' => 4,
            'And This' => 1,
        ], 'venta.orders');

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
        ], 'venta.orders'));

        $this->assertSame('Start Pickup', $ordered[0],
            'the one step that is somebody\'s move must not sit behind Delivered and Cancelled');
        $this->assertSame('Canceled', $ordered[array_key_last($ordered)]);
    }

    public function test_a_status_is_matched_however_the_store_spelled_it(): void
    {
        $this->assertSame('ready', FulfilmentSteps::stepFor('venta.orders', 'Start Pickup'));
        $this->assertSame('ready', FulfilmentSteps::stepFor('venta.orders', 'START_PICKUP'));
        $this->assertSame('ready', FulfilmentSteps::stepFor('venta.orders', '  start pickup '));
        $this->assertNull(FulfilmentSteps::stepFor('venta.orders', 'Nothing Knows This'));
    }

    /**
     * The point of the class, asserted where it can actually be seen: no two
     * channels may print different words for the same step.
     */
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

            // A literal label inside the tabs() call means somebody added a
            // channel-private word back in.
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
