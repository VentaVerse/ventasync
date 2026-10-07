<?php

namespace Tests\Feature;

use Tests\TestCase;

class ConfirmDialogTest extends TestCase
{
    private function source(string $file): string
    {
        return file_get_contents(base_path($file));
    }

    public function test_focus_opens_on_cancel_rather_than_the_destructive_button(): void
    {
        $js = $this->source('resources/js/confirm-modal.js');

        $this->assertStringContainsString('cancelBtn.focus();', $js,
            'The dialog does not put focus on Cancel.');
        $this->assertStringNotContainsString('okBtn.focus();', $js,
            'The dialog opens with focus on the destructive button, so Enter confirms it.');
    }

    public function test_focus_cannot_leave_the_open_dialog(): void
    {
        $js = $this->source('resources/js/confirm-modal.js');

        $this->assertStringContainsString("e.key !== 'Tab'", $js,
            'Tab is not handled, so focus walks out of the dialog into the page behind the backdrop.');
        $this->assertStringContainsString('last.focus();', $js);
        $this->assertStringContainsString('first.focus();', $js);
    }

    public function test_escape_still_closes_the_dialog(): void
    {
        $this->assertStringContainsString("e.key === 'Escape'", $this->source('resources/js/confirm-modal.js'),
            'Escape no longer closes the dialog, so the trap has no exit.');
    }

    public function test_the_dialog_announces_itself(): void
    {
        $blade = $this->source('resources/views/partials/confirm-modal.blade.php');

        foreach (['role="dialog"', 'aria-modal="true"', 'aria-labelledby="x-confirm-modal-title"'] as $attr)
        {
            $this->assertStringContainsString($attr, $blade,
                "The confirm dialog is missing {$attr}, so assistive technology is not told a dialog opened.");
        }
    }

    public function test_a_bulk_confirmation_states_the_count_and_names_what_it_will_touch(): void
    {
        $js = $this->source('resources/js/pages/channel-listings.js');

        $this->assertStringContainsString('function buildBulkConfirm(', $js,
            'Bulk confirmations use the raw attribute again, so the dialog reads the same for 1 and for 47.');
        $this->assertStringContainsString('buildBulkConfirm(message, ids)', $js,
            'buildBulkConfirm exists but nothing calls it.');
        $this->assertStringContainsString('function tickedNames(', $js);
    }

    public function test_names_are_shown_only_when_they_cover_the_whole_selection(): void
    {
        $this->assertStringContainsString('if (names.length === ids.length)',
            $this->source('resources/js/pages/channel-listings.js'),
            'A partial name list can be shown against a full count.');
    }

    public function test_a_long_list_is_capped_and_says_how_many_it_hid(): void
    {
        $js = $this->source('resources/js/pages/channel-listings.js');

        $this->assertStringContainsString('CONFIRM_NAME_LIMIT', $js);
        $this->assertStringContainsString("'\\nand ' + (names.length - CONFIRM_NAME_LIMIT) + ' more'", $js,
            'A truncated name list does not admit it is truncated, so it reads as the whole list.');
    }

    public function test_the_message_keeps_its_line_breaks_without_becoming_markup(): void
    {
        $this->assertStringContainsString('white-space: pre-line;', $this->source('resources/css/blotter.css'),
            'The confirmation collapses onto one line, so the count and the names run together.');

        $this->assertStringContainsString('msgEl.textContent = message;', $this->source('resources/js/confirm-modal.js'),
            'The dialog renders its message as HTML, so a product name becomes markup.');
    }
}
