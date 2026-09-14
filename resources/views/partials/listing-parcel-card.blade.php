{{-- The parcel a store quotes shipping from, one card for every channel's
     listing page. An empty field follows the catalog's figure, shown as its
     placeholder.

     Expects: $id (the page's field prefix), $canManage, $listing (or null),
     $catalog (the catalog product row: weight, length, width, height), and
     optionally $weightMin, the smallest weight the channel's save accepts. --}}
@php
    $weightMin = (string) ($weightMin ?? '0.001');
@endphp
<section class="fm-section">
    <div class="fm-section__head">
        <h2 class="fm-section__title">Parcel</h2>
    </div>
    <div class="fm-fields">
        <x-ui.field label="Weight in kg" for="{{ $id }}-weight" name="weight">
            <x-ui.input id="{{ $id }}-weight" name="weight" type="number" step="0.001" min="{{ $weightMin }}"
                        :value="old('weight', $listing->weight ?? '')"
                        placeholder="{{ (float) ($catalog->weight ?? 0) }}"
                        :disabled="! $canManage" />
        </x-ui.field>
        <x-ui.field label="Length in cm" for="{{ $id }}-length" name="package_length">
            <x-ui.input id="{{ $id }}-length" name="package_length" type="number" min="1"
                        :value="old('package_length', $listing->package_length ?? '')"
                        placeholder="{{ (int) ($catalog->length ?? 0) }}"
                        :disabled="! $canManage" />
        </x-ui.field>
        <x-ui.field label="Width in cm" for="{{ $id }}-width" name="package_width">
            <x-ui.input id="{{ $id }}-width" name="package_width" type="number" min="1"
                        :value="old('package_width', $listing->package_width ?? '')"
                        placeholder="{{ (int) ($catalog->width ?? 0) }}"
                        :disabled="! $canManage" />
        </x-ui.field>
        <x-ui.field label="Height in cm" for="{{ $id }}-height" name="package_height">
            <x-ui.input id="{{ $id }}-height" name="package_height" type="number" min="1"
                        :value="old('package_height', $listing->package_height ?? '')"
                        placeholder="{{ (int) ($catalog->height ?? 0) }}"
                        :disabled="! $canManage" />
        </x-ui.field>
    </div>
</section>
