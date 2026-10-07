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
