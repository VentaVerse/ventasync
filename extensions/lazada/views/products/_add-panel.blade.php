{{-- The Add products slide-over (#201, Draft C - the owner 2026-09-05).

     Opens over the Listings table from the + Add products button. Search your
     main catalogue and add in place; each addition joins this store's list
     (unpushed, the push workflow unchanged) and lands in the table behind
     when the panel closes. A slide-over cannot list a whole catalogue, so the
     results are capped and the meta line says how much is out of view - the
     operator narrows by searching, and the full catalogue page is one link
     away for a browse.

     Rides .modal-backdrop's fixed scrim and .active show/hide, the mechanism
     every other dialog uses. Behaviour lives in
     resources/js/pages/channel-add-panel.js, bound from the data- attributes
     here; every URL comes off an attribute, never a Blade-built string in
     script. --}}
<div id="lazada-add-panel" class="modal-backdrop cc-addpanel" data-add-panel
     data-search-url="{{ route('ext.lazada.products.catalogue_search') }}"
     data-add-url="{{ route('ext.lazada.products.add_to_store', ['productId' => '__PID__']) }}">
    @csrf
    <div class="cc-addpanel__sheet" role="dialog" aria-modal="true" aria-labelledby="lazada-add-panel-title">
        <div class="cc-addpanel__head">
            <h2 id="lazada-add-panel-title" class="cc-addpanel__title">Add products</h2>
            <button type="button" class="cc-addpanel__close" data-add-panel-close aria-label="Close">
                <x-ui.icon name="x" size="16" />
            </button>
        </div>
        <p class="cc-addpanel__sub">Search your main catalogue. Each product you add joins this store's list, ready to configure and push to Lazada.</p>

        <div class="cc-addpanel__search">
            <input type="search" class="x-input" placeholder="Name, model or SKU" aria-label="Search your main catalogue"
                   autocomplete="off" data-add-panel-search>
            <label class="cc-addpanel__toggle">
                <input type="checkbox" class="cc-check" data-add-panel-show-all>
                Show products already on this store
            </label>
        </div>

        <div class="cc-addpanel__meta" data-add-panel-meta aria-live="polite"></div>
        <ul class="cc-addpanel__list" data-add-panel-list aria-label="Catalogue products"></ul>

        <div class="cc-addpanel__foot">
            <span class="cc-addpanel__added" data-add-panel-added aria-live="polite"></span>
            <a class="cc-addpanel__full" href="{{ route('ext.lazada.products.index', ['list' => 'add']) }}">Browse the full catalogue</a>
            <x-ui.button type="button" size="sm" data-add-panel-done>Done</x-ui.button>
        </div>
    </div>
</div>
