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
            <x-ui.input id="{{ $id }}-length" name="package_length" type="number" step="0.01" min="0.01"
                        :value="old('package_length', $listing->package_length ?? '')"
                        placeholder="{{ (float) ($catalog->length ?? 0) }}"
                        :disabled="! $canManage" />
        </x-ui.field>
        <x-ui.field label="Width in cm" for="{{ $id }}-width" name="package_width">
            <x-ui.input id="{{ $id }}-width" name="package_width" type="number" step="0.01" min="0.01"
                        :value="old('package_width', $listing->package_width ?? '')"
                        placeholder="{{ (float) ($catalog->width ?? 0) }}"
                        :disabled="! $canManage" />
        </x-ui.field>
        <x-ui.field label="Height in cm" for="{{ $id }}-height" name="package_height">
            <x-ui.input id="{{ $id }}-height" name="package_height" type="number" step="0.01" min="0.01"
                        :value="old('package_height', $listing->package_height ?? '')"
                        placeholder="{{ (float) ($catalog->height ?? 0) }}"
                        :disabled="! $canManage" />
        </x-ui.field>
    </div>
</section>
