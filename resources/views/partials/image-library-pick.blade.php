<div class="modal-backdrop" data-ilp-modal data-ilp-url="{{ $browseUrl }}">
    <div class="fm-pick" role="dialog" aria-modal="true" aria-labelledby="ilp-title">
        <div class="fm-pick__head">
            <h3 class="fm-pick__title" id="ilp-title">Image library</h3>
            <span class="fm-pick__msg" role="status" aria-live="polite" data-ilp-msg></span>
            <div class="fm-pick__actions">
                <x-ui.button type="button" size="sm" data-ilp-cancel>Cancel</x-ui.button>
                <x-ui.button type="button" size="sm" variant="primary" data-ilp-add disabled>Add</x-ui.button>
            </div>
        </div>
        <div class="fm-pick__body">
            <div class="fm-pick__tools">
                <x-ui.button type="button" size="sm" data-ilp-up disabled>
                    <x-ui.icon name="corner-up-left" size="14" /> Up
                </x-ui.button>
                <span class="fm-pick__path" data-ilp-path>/</span>
                <span class="fm-pick__gap"></span>
                <input type="search" class="x-input fm-pick__search" data-ilp-search
                       placeholder="Search every folder" aria-label="Search the image library">
            </div>

            <div class="fm-pick__group" data-ilp-folders-title hidden>Folders</div>
            <div class="fm-pick__grid" data-ilp-folders></div>

            <div class="fm-pick__group" data-ilp-files-title hidden>Images</div>
            <div class="fm-pick__grid" data-ilp-files></div>

            <p class="fm-pick__empty" data-ilp-empty hidden>This folder holds no images.</p>
        </div>
    </div>
</div>
