<?php

namespace Tests\Feature;

use App\Support\ChannelWorkspace;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ChannelStateLabelTest extends TestCase
{
    public static function labels(): array
    {
        return [
            'expiring sign-in' => ['attention', 'expiring', 'Sign-in expires soon', 'warning'],
            'stale sync' => ['attention', 'stale', 'Sync overdue', 'warning'],
            'no token at all' => ['setup', 'no_token', 'Not connected', 'danger'],
            'token already expired' => ['setup', 'expired', 'Sign-in expired', 'danger'],
            'configured but switched off' => ['setup', 'disabled', 'Turned off', 'neutral'],
            'healthy' => ['connected', null, 'Connected', 'success'],
            'nothing measurable' => ['untracked', null, 'Not tracked', 'neutral'],
        ];
    }

    #[DataProvider('labels')]
    public function test_each_condition_says_what_it_is(string $state, ?string $reason, string $label, string $tone): void
    {
        $badge = ChannelWorkspace::badge($state, $reason);

        $this->assertSame($label, $badge['label']);
        $this->assertSame($tone, $badge['tone']);
    }

    public function test_a_switched_off_store_is_not_reported_as_broken(): void
    {
        $badge = ChannelWorkspace::badge('setup', 'disabled');

        $this->assertNotSame('Not connected', $badge['label']);
        $this->assertNotSame('danger', $badge['tone'],
            'A store that is merely switched off is shown in the alarm colour.');
    }

    public function test_the_two_attention_causes_are_told_apart(): void
    {
        $this->assertNotSame(
            ChannelWorkspace::badge('attention', 'expiring')['label'],
            ChannelWorkspace::badge('attention', 'stale')['label'],
            'An expiring sign-in and an overdue sync still read the same, so the operator '
            .'cannot tell which one they are looking at.'
        );
    }

    public function test_an_unrecognised_reason_still_says_something(): void
    {
        foreach ([['attention', 'who-knows'], ['setup', 'who-knows'], ['banana', null]] as [$state, $reason]) {
            $badge = ChannelWorkspace::badge($state, $reason);

            $this->assertNotSame('', trim($badge['label']));
            $this->assertNotSame('', trim($badge['tone']));
        }
    }

    public function test_no_view_keeps_its_own_copy_of_the_wording(): void
    {
        $views = [
            'resources/views/channels/board.blade.php',
        ];

        foreach ($views as $view) {
            $this->assertFileExists(base_path($view));
            $source = file_get_contents(base_path($view));

            $this->assertStringContainsString('ChannelWorkspace::badge(', $source,
                "{$view} does not use the shared wording.");
            $this->assertStringNotContainsString("'attention' => 'Needs attention'", $source,
                "{$view} carries its own label map again, so it can drift from the other screen.");
        }
    }

    public function test_needs_attention_is_only_a_fallback(): void
    {
        $this->assertSame('Needs attention', ChannelWorkspace::badge('attention', null)['label']);

        foreach (['expiring', 'stale'] as $reason) {
            $this->assertNotSame('Needs attention', ChannelWorkspace::badge('attention', $reason)['label']);
        }
    }
}
