{{--
    Global confirm modal for pages on the new shell layout (layouts.shell).

    This began as a port of the inline confirm modal and [data-confirm]
    click/submit interceptors that layouts.app (the legacy layout) shipped on
    the DOM ids "confirm-modal" etc. It was given its own "x-confirm-modal"
    ids so the two could never collide while both layouts were live:
    resources/js/confirm-modal.js only wires itself up when it finds this
    element. layouts.app was deleted in Round F Task 4, so this is the only
    confirm modal left in the app and the id split no longer separates
    anything - it is kept because every trigger, every stylesheet rule and
    confirm-modal.js all name the x- ids, and renaming them app-wide would be
    churn with no behaviour change.

    The Confirm button defaults to the danger fill, because nearly every
    data-confirm in this application guards a deletion. A trigger that is
    NOT destructive (enabling an extension, installing one) sets
    data-confirm-tone="primary" and confirm-modal.js swaps the variant
    class, so red on this dialog keeps meaning "this removes something".
--}}
<div class="modal-backdrop" id="x-confirm-modal">
    {{-- role/aria-modal/aria-labelledby: this was a bare <div>, so nothing
         told assistive technology a dialog had opened, what it was called, or
         that the page behind it was inert. The heading and the message are
         named explicitly rather than left to be guessed from reading order. --}}
    <div class="modal modal--sm" role="dialog" aria-modal="true"
         aria-labelledby="x-confirm-modal-title"
         aria-describedby="x-confirm-modal-message">
        <div class="modal-header">
            <h3 id="x-confirm-modal-title">Confirm</h3>
            <button class="modal-close" type="button" id="x-confirm-modal-close" aria-label="Close">&times;</button>
        </div>
        <p class="x-confirm__message" id="x-confirm-modal-message"></p>
        <div class="d-flex justify-end gap-8">
            <x-ui.button variant="secondary" id="x-confirm-modal-cancel">Cancel</x-ui.button>
            <x-ui.button variant="danger" id="x-confirm-modal-ok">Confirm</x-ui.button>
        </div>
    </div>
</div>
