<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ConfirmOverlayTest extends TestCase
{
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

    public function test_the_confirm_dialog_still_lets_the_event_through(): void
    {
        $source = File::get(resource_path('js/confirm-modal.js'));

        $start = strpos($source, "document.addEventListener('submit'");
        $next = strpos($source, 'document.addEventListener(', $start + 1);
        $submitHandler = substr($source, $start, $next === false ? null : $next - $start);

        $this->assertStringNotContainsString('stopPropagation', $submitHandler,
            'the submit handler must let the event reach per-form validation listeners');
        $this->assertStringNotContainsString('stopImmediatePropagation', $submitHandler);
    }
}
