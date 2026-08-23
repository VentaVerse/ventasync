<?php

namespace Tests\Feature;

use App\Support\ChannelWorkspace;
use Tests\TestCase;

/**
 * What a channel's status chip is allowed to say.
 *
 * Two labels were each covering two unrelated situations:
 *
 *   "Needs attention" meant the sign-in expires within seven days, OR the last
 *   sync is over a day old. Different problem, different fix, one word - and
 *   the channel sidebar shows this label with no figures beside it, so there
 *   was nothing there to work out which.
 *
 *   "Not connected" meant no usable token, OR a store that is simply switched
 *   off. StoreLink's status is 'setup_pending' whenever $store->enabled is
 *   false, so four configured Venta and OpenCart stores - tokens set, orders
 *   synced - were painted red and told to complete a setup that was already
 *   complete.
 *
 * The wording also lived in two Blade files with their own copies of the map,
 * which is how the sidebar and the status board came to describe one channel
 * differently. It lives in one function now, and these tests hold both halves:
 * every condition says what it is, and neither screen carries its own map.
 */
class ChannelStateLabelTest extends TestCase
{
    public static function labels(): array
    {
        return [
            // state, reason, expected label, expected tone
            'expiring sign-in' => ['attention', 'expiring', 'Sign-in expires soon', 'warning'],
            'stale sync' => ['attention', 'stale', 'Sync overdue', 'warning'],
            'no token at all' => ['setup', 'no_token', 'Not connected', 'danger'],
            'token already expired' => ['setup', 'expired', 'Sign-in expired', 'danger'],
            'configured but switched off' => ['setup', 'disabled', 'Turned off', 'neutral'],
            'healthy' => ['connected', null, 'Connected', 'success'],
            'nothing measurable' => ['untracked', null, 'Not tracked', 'neutral'],
        ];
    }

    /**
     * @dataProvider labels
     */
    public function test_each_condition_says_what_it_is(string $state, ?string $reason, string $label, string $tone): void
    {
        $badge = ChannelWorkspace::badge($state, $reason);

        $this->assertSame($label, $badge['label']);
        $this->assertSame($tone, $badge['tone']);
    }

    /**
     * The defect. A store that is configured, holds a valid token and has
     * synced orders is not "Not connected", and painting it in the danger
     * colour tells the seller to fix something that is not broken.
     */
    public function test_a_switched_off_store_is_not_reported_as_broken(): void
    {
        $badge = ChannelWorkspace::badge('setup', 'disabled');

        $this->assertNotSame('Not connected', $badge['label']);
        $this->assertNotSame('danger', $badge['tone'],
            'A store that is merely switched off is shown in the alarm colour.');
    }

    /** Two different problems must not read identically. */
    public function test_the_two_attention_causes_are_told_apart(): void
    {
        $this->assertNotSame(
            ChannelWorkspace::badge('attention', 'expiring')['label'],
            ChannelWorkspace::badge('attention', 'stale')['label'],
            'An expiring sign-in and an overdue sync still read the same, so the operator '
            .'cannot tell which one they are looking at.'
        );
    }

    /** An unknown reason must still produce something honest, never a blank chip. */
    public function test_an_unrecognised_reason_still_says_something(): void
    {
        foreach ([['attention', 'who-knows'], ['setup', 'who-knows'], ['banana', null]] as [$state, $reason]) {
            $badge = ChannelWorkspace::badge($state, $reason);

            $this->assertNotSame('', trim($badge['label']));
            $this->assertNotSame('', trim($badge['tone']));
        }
    }

    /**
     * The ratchet. Neither screen may carry its own copy of the wording again:
     * that is what let them drift into describing one channel two ways.
     */
    public function test_no_view_keeps_its_own_copy_of_the_wording(): void
    {
        $views = [
            'resources/views/partials/channel-nav.blade.php',
            'resources/views/channels/board.blade.php',
        ];

        foreach ($views as $view) {
            $source = file_get_contents(base_path($view));

            $this->assertStringContainsString('ChannelWorkspace::badge(', $source,
                "{$view} does not use the shared wording.");
            $this->assertStringNotContainsString("'attention' => 'Needs attention'", $source,
                "{$view} carries its own label map again, so it can drift from the other screen.");
        }
    }

    /**
     * "Needs attention" survives only as the fallback for an attention state
     * whose cause is unknown. If it comes back as the normal reading, the
     * reason is not reaching the label.
     */
    public function test_needs_attention_is_only_a_fallback(): void
    {
        $this->assertSame('Needs attention', ChannelWorkspace::badge('attention', null)['label']);

        foreach (['expiring', 'stale'] as $reason) {
            $this->assertNotSame('Needs attention', ChannelWorkspace::badge('attention', $reason)['label']);
        }
    }
}
