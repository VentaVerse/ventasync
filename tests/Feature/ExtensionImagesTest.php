<?php

namespace Tests\Feature;

use App\Extensions\ExtensionImages;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ExtensionImagesTest extends TestCase
{
    private const ID = 'zz-picture-test';

    private function source(): string
    {
        return base_path('extensions/' . self::ID . '/images');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(base_path('extensions/' . self::ID));
        File::deleteDirectory(ExtensionImages::publicDir(self::ID));
        parent::tearDown();
    }

    public function test_pictures_are_published_refreshed_and_removed_with_the_extension(): void
    {
        File::ensureDirectoryExists($this->source() . '/tiles');
        File::put($this->source() . '/logo.png', 'first');
        File::put($this->source() . '/tiles/plan.png', 'plan');
        touch($this->source() . '/logo.png', time() - 100);

        ExtensionImages::publish(self::ID);

        $published = ExtensionImages::publicDir(self::ID);
        $this->assertSame('first', File::get($published . '/logo.png'));
        $this->assertSame('plan', File::get($published . '/tiles/plan.png'));
        $this->assertStringEndsWith('/images/extensions/' . self::ID . '/logo.png', ExtensionImages::url(self::ID, 'logo.png'));

        File::put($this->source() . '/logo.png', 'second');
        touch($this->source() . '/logo.png', time() + 5);
        ExtensionImages::publish(self::ID);
        $this->assertSame('second', File::get($published . '/logo.png'), 'a newer picture in the extension replaces the published one');

        ExtensionImages::remove(self::ID);
        $this->assertDirectoryDoesNotExist($published);
    }

    public function test_an_extension_without_pictures_or_with_an_unsafe_id_publishes_nothing(): void
    {
        ExtensionImages::publish(self::ID);
        $this->assertDirectoryDoesNotExist(ExtensionImages::publicDir(self::ID));

        ExtensionImages::publish('../catalog');
        ExtensionImages::remove('../catalog');
        $this->assertDirectoryExists(public_path('images/brand'), 'an id that climbs touches nothing');
    }

    public function test_every_channel_ships_the_square_mark_the_add_store_modal_shows(): void
    {
        foreach (['shopee', 'lazada', 'tiktok', 'ventacart'] as $channel) {
            $this->assertTrue(ExtensionImages::has($channel, 'logo.png'), "{$channel} has no square mark");
        }
    }

    public function test_the_store_bar_shows_the_wide_logo_when_there_is_one_and_the_mark_and_name_when_there_is_not(): void
    {
        $bar = File::get(resource_path('views/partials/channel-menubar.blade.php'));

        $this->assertStringContainsString("ExtensionImages::isTransparent(\$mbChannelId, 'logo-wide.png')", $bar, 'a lockup on its own background would be a block of colour on the bar');
        $this->assertStringContainsString("ExtensionImages::has(\$mbChannelId, 'logo.png')", $bar);
        $this->assertStringContainsString('x-chnav__markname', $bar, 'a channel without a wide logo is named beside its mark');
        $this->assertTrue(ExtensionImages::has('ventacart', 'logo-wide.png'), 'VentaCart ships the wide logo the bar shows');
    }

    public function test_the_hub_cards_carry_the_channels_mark(): void
    {
        $fulfilment = File::get(resource_path('views/channels/fulfilment.blade.php'));
        $this->assertStringContainsString('fh-card__mark', $fulfilment);
        $this->assertStringContainsString("ExtensionImages::has(\$fhChannelId, 'logo.png')", $fulfilment);
        $this->assertStringContainsString("\$fhMark && ! empty(\$fhCard['store']) ? \$fhCard['store'] : \$fhCard['label']", $fulfilment, 'with the mark the cap carries the store alone, else the full label');

        $board = File::get(resource_path('views/channels/board.blade.php'));
        $this->assertStringContainsString('x-board__mark', $board);
        $this->assertStringContainsString("ExtensionImages::has(strtok((string) \$c['key'], ':'), 'logo.png')", $board);
    }

    public function test_the_add_store_modal_reads_each_channels_logo_from_its_extension(): void
    {
        $modal = File::get(resource_path('views/channels/_add-store-modal.blade.php'));

        $this->assertStringContainsString("ExtensionImages::url(\$c['id'], 'logo.png')", $modal);
        $this->assertStringNotContainsString('images/channels/', $modal);
    }
}
