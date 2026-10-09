@php $watermarkOptions = \App\Models\WatermarkTemplate::forStore($channel, \App\Channels\Listings\ListingStore::id($channel)); @endphp
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
