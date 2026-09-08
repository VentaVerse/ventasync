{{-- The + on a listing page opens this, posts over fetch, and the new
     template joins both dropdowns without a page load (the owner, 2026-09-07).
     One per page; the two pickers share it.

     Expected: $storeUrl, the channel's description-templates.store route. --}}
<div class="modal-backdrop" data-dtp-modal data-dtp-url="{{ $storeUrl }}">
    <div class="modal modal--sm">
        <div class="modal-header">
            <h3>New template</h3>
            <button type="button" class="modal-close" data-dtp-cancel aria-label="Close">&times;</button>
        </div>
        <div class="dt-modal__body">
            <div class="fm-fields">
                <x-ui.field label="Name" for="dtp-name" name="dtp_name" :required="true" wide>
                    <x-ui.input id="dtp-name" data-dtp-name maxlength="128" placeholder="Returns policy" />
                </x-ui.field>
                <x-ui.field label="Words" for="dtp-body" name="dtp_body" wide>
                    <x-ui.textarea id="dtp-body" data-dtp-body rows="8" maxlength="20000"></x-ui.textarea>
                </x-ui.field>
            </div>
            <p class="fm-error" data-dtp-error></p>
        </div>
        <div class="dt-modal__foot">
            <x-ui.button type="button" data-dtp-cancel>Cancel</x-ui.button>
            <x-ui.button type="button" variant="primary" data-dtp-save>Save</x-ui.button>
        </div>
    </div>
</div>
