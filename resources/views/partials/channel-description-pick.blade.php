{{-- A prefix or suffix template chooser on a listing page (the owner,
     2026-09-07): the store's own words above or below the description.
     None is the default. The + creates one without leaving the page.

     Expected keys:
       id       string  the select's id
       label    string  "Prefix description" or "Suffix description"
       name     string  the field name on the wire
       selected ?int    the chosen template id
       options  Collection of description templates (id, name)
       canManage bool --}}
<x-ui.field :label="$label" :for="$id" :name="$name" wide>
    <div class="dt-pick">
        <div class="x-select-wrap">
            <select id="{{ $id }}" name="{{ $name }}" class="x-input" data-dtp-select @disabled(!$canManage)>
                <option value="">None</option>
                @foreach($options as $option)
                    <option value="{{ $option->id }}" @selected((int) $selected === (int) $option->id)>{{ $option->name }}</option>
                @endforeach
            </select>
            <x-ui.icon name="chevron-down" size="14" class="x-select-wrap__chevron" />
        </div>
        @if($canManage)
            <x-ui.button type="button" size="sm" data-dtp-add data-dtp-for="{{ $id }}" aria-label="New template">+</x-ui.button>
        @endif
    </div>
</x-ui.field>
