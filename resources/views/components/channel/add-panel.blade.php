{{-- The Add products slide-over (#201, Draft C - the owner 2026-09-05).

     One component for every surface that adds products to a list in place:
     a store's Listings (the main catalogue into this store) and a product
     group's page (this store's products into the group). The page supplies
     the two endpoints and the words; the behaviour is the shared module
     resources/js/pages/channel-add-panel.js, bound from the data- attributes
     on the root. Every URL rides an attribute, never a Blade-built string in
     script.

     A slide-over cannot list a whole catalogue, so the search is the way
     through: results are capped by the server and the meta line says how
     much is out of view. A surface with a full-page overflow passes
     $fullUrl and the footer links to it.

     Rides .modal-backdrop's fixed scrim and .active show/hide, the mechanism
     every other dialog uses. --}}
@props([
    'id',
    'searchUrl',
    'addUrl',
    'title' => 'Add products',
    'sub',
    'placeholder' => 'Name, model or SKU',
    'searchLabel' => 'Search your main catalogue',
    'toggleLabel',
    'inLabel',
    'scope',
    'emptyDefault',
    'emptyAll',
    'listLabel' => 'Products',
    'fullUrl' => null,
    'fullLabel' => 'Browse the full catalogue',
])
<div id="{{ $id }}" class="modal-backdrop cc-addpanel" data-add-panel
     data-search-url="{{ $searchUrl }}"
     data-add-url="{{ $addUrl }}"
     data-in-label="{{ $inLabel }}"
     data-scope="{{ $scope }}"
     data-empty-default="{{ $emptyDefault }}"
     data-empty-all="{{ $emptyAll }}">
    @csrf
    <div class="cc-addpanel__sheet" role="dialog" aria-modal="true" aria-labelledby="{{ $id }}-title">
        <div class="cc-addpanel__head">
            <h2 id="{{ $id }}-title" class="cc-addpanel__title">{{ $title }}</h2>
            <button type="button" class="cc-addpanel__close" data-add-panel-close aria-label="Close">
                <x-ui.icon name="x" size="16" />
            </button>
        </div>
        <p class="cc-addpanel__sub">{{ $sub }}</p>

        <div class="cc-addpanel__search">
            <input type="search" class="x-input" placeholder="{{ $placeholder }}" aria-label="{{ $searchLabel }}"
                   autocomplete="off" data-add-panel-search>
            <label class="cc-addpanel__toggle">
                <input type="checkbox" class="cc-check" data-add-panel-show-all>
                {{ $toggleLabel }}
            </label>
        </div>

        <div class="cc-addpanel__meta" data-add-panel-meta aria-live="polite"></div>
        <ul class="cc-addpanel__list" data-add-panel-list aria-label="{{ $listLabel }}"></ul>

        <div class="cc-addpanel__foot">
            <span class="cc-addpanel__added" data-add-panel-added aria-live="polite"></span>
            @if($fullUrl)
                <a class="cc-addpanel__full" href="{{ $fullUrl }}">{{ $fullLabel }}</a>
            @endif
            <x-ui.button type="button" size="sm" data-add-panel-done>Done</x-ui.button>
        </div>
    </div>
</div>
