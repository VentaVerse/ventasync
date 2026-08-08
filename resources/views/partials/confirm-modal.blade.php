{{--
    Global confirm modal for pages on the new shell layout (layouts.shell).

    layouts.app (the legacy layout) already ships its own inline confirm
    modal and [data-confirm] click/submit interceptors, keyed to the DOM ids
    "confirm-modal" etc. This partial is the same pattern ported to the new
    design system, but on its own "x-confirm-modal" ids so the two never
    collide: resources/js/confirm-modal.js only wires itself up when it
    finds this element, which only exists on pages that include this
    partial. Legacy pages are untouched.

    The Confirm button defaults to the danger fill, because nearly every
    data-confirm in this application guards a deletion. A trigger that is
    NOT destructive (enabling an extension, installing one) sets
    data-confirm-tone="primary" and confirm-modal.js swaps the variant
    class, so red on this dialog keeps meaning "this removes something".
--}}
<div class="modal-backdrop" id="x-confirm-modal">
    <div class="modal modal--sm">
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
