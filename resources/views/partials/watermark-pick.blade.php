{{-- A product group's watermark template: its listings are marked with it
     when they name none of their own.

     A template belongs to a store, so the list offered here is the store's
     own and never another store's.

     Expected keys:
       selected     ?int    the chosen template id
       integration  string  the channel
       canManage    bool --}}
@php $watermarkOptions = \App\Models\WatermarkTemplate::forStore($integration, \App\Integrations\Listings\ListingStore::id($integration)); @endphp
<x-ui.field label="Template" for="pg-watermark" name="watermark_template_id">
    <div class="x-select-wrap">
        <select id="pg-watermark" name="watermark_template_id" class="x-input" @disabled(!$canManage)>
            <option value="">None</option>
            @foreach($watermarkOptions as $option)
                <option value="{{ $option->id }}" @selected((int) ($selected ?? 0) === (int) $option->id)>{{ $option->name }}</option>
            @endforeach
        </select>
        <x-ui.icon name="chevron-down" size="14" class="x-select-wrap__chevron" />
    </div>
</x-ui.field>
