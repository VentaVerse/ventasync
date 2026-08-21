<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * A confirmation that has not been answered must not look like work that
 * has already started.
 *
 * [data-confirm] intercepts submit from a CAPTURE-phase listener on
 * document, so it runs before the form's own listeners and calls
 * preventDefault. preventDefault does not stop propagation, so those
 * listeners still ran: pressing Fetch orders raised a full-screen "Fetching
 * orders - please keep this tab open" behind a dialog that was still asking
 * whether to fetch anything, and Cancel left it up for good, because the
 * cancel path has nothing to take an overlay down with.
 *
 * confirm-modal.js deliberately does NOT stop propagation (that would hide
 * the event from per-form validation), so the contract runs the other way:
 * a listener that acts as though the work has begun must check
 * defaultPrevented first. This test holds every such listener to it.
 */
class ConfirmOverlayTest extends TestCase
{
    /**
     * Submit listeners that raise a loading overlay, found by reading the
     * source rather than by listing files, so a new channel's copy of this
     * pattern is covered the day it is written.
     */
    private function overlayRaisingSubmitListeners(): array
    {
        $found = [];

        foreach (File::allFiles(resource_path('js')) as $file) {
            if ($file->getExtension() !== 'js') {
                continue;
            }

            $source = File::get($file->getPathname());
            $relative = str_replace(resource_path('js').'/', '', $file->getPathname());

            $offset = 0;

            while (($start = strpos($source, "addEventListener('submit'", $offset)) !== false) {
                $offset = $start + 1;

                // The listener body, bounded generously. Overlay-raising
                // work sits at the top of these handlers, never pages down.
                $body = substr($source, $start, 1800);

                $raisesOverlay = preg_match(
                    "/showModal\(|style\.display\s*=\s*'flex'|classList\.add\('active'\)/",
                    $body
                );

                if (! $raisesOverlay) {
                    continue;
                }

                $line = substr_count(substr($source, 0, $start), "\n") + 1;

                $found[$relative.':'.$line] = str_contains($body, 'e.defaultPrevented');
            }
        }

        return $found;
    }

    public function test_an_unanswered_confirmation_never_raises_a_loading_overlay(): void
    {
        $listeners = $this->overlayRaisingSubmitListeners();

        $this->assertNotEmpty(
            $listeners,
            'no overlay-raising submit listeners were found; the scan is looking for the wrong shape'
        );

        $unguarded = array_keys(array_filter($listeners, fn ($guarded) => ! $guarded));

        $this->assertSame([], $unguarded, sprintf(
            "These submit listeners raise a loading overlay without checking defaultPrevented: %s\n\n"
            ."Add `if (e.defaultPrevented) return;` as the first statement. Without it the\n"
            ."overlay appears while the confirmation dialog is still asking, and stays on\n"
            ."screen forever when the operator answers Cancel.",
            implode(', ', $unguarded)
        ));
    }

    /**
     * The reason the call sites carry that guard lives in one place. If
     * somebody ever "simplifies" confirm-modal.js by stopping propagation,
     * the guards become dead code and per-form validation stops running, so
     * the explanation must not quietly disappear.
     */
    public function test_the_confirm_dialog_still_lets_the_event_through(): void
    {
        $source = File::get(resource_path('js/confirm-modal.js'));

        $this->assertStringContainsString('if (e.defaultPrevented) return;', $source,
            'confirm-modal.js must document the contract its callers depend on');

        $submitHandler = substr($source, strpos($source, "document.addEventListener('submit'"), 1400);

        $this->assertStringNotContainsString('stopPropagation', $submitHandler,
            'the submit handler must let the event reach per-form validation listeners');
        $this->assertStringNotContainsString('stopImmediatePropagation', $submitHandler);
    }
}
