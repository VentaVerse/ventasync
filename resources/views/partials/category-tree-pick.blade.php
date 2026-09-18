{{-- THE CATEGORY PICKER: SEARCH, AND THE TREE BEHIND IT.

     The search field is the fast way in and stays exactly as it was: anyone
     who knows the category's name types it. Browse opens the store's tree
     beside it, a column per level, for the times you do not.

     A DEPTH IS NEVER ASSUMED. Shopee reaches five levels, Lazada six, and
     most of Lazada's pickable categories sit deeper than three, so a column
     opens whenever the row you picked says something is below it, and stops
     when it says nothing is. Columns behind the last two fold to a spine
     carrying their chosen name, so a six-level path needs no sideways
     scrolling.

     A search result opens every column along its path, already chosen, so
     you arrive able to see where you landed and to move sideways from it.

     Expected keys:
       name          string   the form field the chosen id is submitted as
       id            string   a unique html id stem for this picker
       label         string   the field's label
       value         ?int     the chosen category id
       valueLabel    string   its name, for the field when the page opens
       searchUrl     ?string  the channel's own remote search, where it has
                              one; without it the field filters the tree it has
       childrenUrl   string   one level of the tree
       pathUrl       string   the path down to a chosen category
       canManage     bool
       required      bool
       requiredWord  string   what the browser's own bubble says when nothing
                              is picked; required means required on both
                              presses, so Save cannot skip it --}}
@php
    $ctpValue = (int) ($value ?? 0);
    $ctpRequired = (bool) ($required ?? false);
@endphp
<x-ui.field :label="$label" :for="$id" :name="$name" :required="$ctpRequired" wide>
    <div class="ctp" data-category-pick
         @if($ctpRequired) data-combo-required="{{ $requiredWord ?? 'Pick a category from the list.' }}" @endif
         data-children-url="{{ $childrenUrl }}"
         data-path-url="{{ $pathUrl }}"
         @if(! empty($searchUrl)) data-search-url="{{ $searchUrl }}" @endif
         @if($ctpValue > 0) data-chosen="{{ $ctpValue }}" @endif>

        {{-- data-combo-value as well as its own hook: fields that depend on
             the category (Lazada's attribute template, TikTok's brand list)
             already watch that selector, and they keep working because this
             announces a change the same way the old control did. --}}
        <input type="hidden" id="{{ $id }}-value" name="{{ $name }}" data-ctp-value data-combo-value
               value="{{ $ctpValue > 0 ? $ctpValue : '' }}">

        <div class="ctp__bar">
            <div class="ctp__search">
                <x-ui.icon name="search" size="14" class="ctp__glass" />
                <x-ui.input id="{{ $id }}" type="search" autocomplete="off" data-ctp-input
                            value="{{ (string) ($valueLabel ?? '') }}"
                            placeholder="Search categories"
                            :disabled="!$canManage" />
                <div class="ctp__results" data-ctp-results role="listbox" aria-label="Matching categories" hidden></div>
            </div>
            <button type="button" class="ctp__browse" data-ctp-browse aria-expanded="false" @disabled(!$canManage)>
                <x-ui.icon name="list" size="14" />
                Browse
            </button>
        </div>

        <p class="ctp__path" data-ctp-path @if($ctpValue <= 0) hidden @endif></p>

        <div class="ctp__tree" data-ctp-tree hidden>
            <div class="ctp__cols" data-ctp-cols></div>
        </div>

        <p class="ctp__say" role="status" aria-live="polite" data-ctp-say hidden></p>
    </div>
</x-ui.field>
