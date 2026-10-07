@php
    $ownPrice = old('price', $price ?? '');
@endphp
<section class="fm-section">
    <div class="fm-section__head">
        <h2 class="fm-section__title">Price rule</h2>
    </div>
    @unless($hasVariations)
        <div class="fm-fields">
            <x-ui.field label="Price" for="{{ $id }}-price" name="price">
                <x-ui.input id="{{ $id }}-price" name="price" type="number" step="0.01" min="0"
                            class="fm-input--num" data-own-price
                            :value="$ownPrice"
                            placeholder="{{ number_format((float) $corePrice, 2, '.', '') }}"
                            :disabled="! $canManage" />
            </x-ui.field>
        </div>
    @endunless
    <div class="fm-fields fm-fields--2">
        <x-ui.field label="Percent added" for="{{ $id }}-markup-pct" name="markup_percent">
            <x-ui.input id="{{ $id }}-markup-pct" name="markup_percent" type="number" step="0.01"
                        class="fm-input--num" data-markup-percent
                        :value="old('markup_percent', $markupPercent ?? '')"
                        placeholder="0"
                        :disabled="! $canManage" />
        </x-ui.field>
        <x-ui.field label="Fixed amount added" for="{{ $id }}-markup-fixed" name="markup_fixed">
            <x-ui.input id="{{ $id }}-markup-fixed" name="markup_fixed" type="number" step="0.01"
                        class="fm-input--num" data-markup-fixed
                        :value="old('markup_fixed', $markupFixed ?? '')"
                        placeholder="0"
                        :disabled="! $canManage" />
        </x-ui.field>
    </div>
    <div class="cl-total" data-running-total data-base-price="{{ (float) $corePrice }}">
        <span class="cl-total__k">Price on {{ $storeName }} after push</span>
        <span class="cl-total__v" data-total-value><x-money :php="(float) $pushPrice" /></span>
        <span class="cl-total__note">Catalog price <x-money :php="(float) $corePrice" /></span>
    </div>
</section>
