<div class="modal-backdrop" id="x-confirm-modal">
    <div class="modal modal--sm" role="dialog" aria-modal="true"
         aria-labelledby="x-confirm-modal-title"
         aria-describedby="x-confirm-modal-message">
        <div class="modal-header">
            <h2 id="x-confirm-modal-title">Confirm</h2>
            <button class="modal-close" type="button" id="x-confirm-modal-close" aria-label="Close">&times;</button>
        </div>
        <p class="x-confirm__message" id="x-confirm-modal-message"></p>
        <table class="x-confirm__lines" id="x-confirm-modal-lines" hidden><tbody></tbody></table>
        <div class="x-confirm__type" id="x-confirm-modal-type" hidden>
            <label class="fm-label" for="x-confirm-modal-input" id="x-confirm-modal-type-label"></label>
            <input class="x-input" id="x-confirm-modal-input" type="text" autocomplete="off" spellcheck="false">
        </div>
        <div class="d-flex justify-end gap-8">
            <x-ui.button variant="secondary" id="x-confirm-modal-cancel">Cancel</x-ui.button>
            <x-ui.button variant="danger" id="x-confirm-modal-ok">Confirm</x-ui.button>
        </div>
    </div>
</div>
