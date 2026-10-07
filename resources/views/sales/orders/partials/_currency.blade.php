<div class="fm-card">
    <div class="fm-card__head"><h2 class="fm-card__title">Currency</h2></div>
    <div class="fm-card__body">

        <x-ui.field label="Currency" for="of-currency" name="currency_code">
            <x-ui.select id="of-currency">
                @foreach($currencies as $cur)
                    <option value="{{ $cur->id }}"
                            data-code="{{ $cur->code }}"
                            data-symbol="{{ $cur->symbol }}"
                            data-rate="{{ $cur->exchange_rate }}"
                            data-is-default="{{ $cur->is_default ? 1 : 0 }}"
                            @selected($currentCode === $cur->code)>{{ $cur->code }}, {{ $cur->name }}</option>
                @endforeach
            </x-ui.select>
            <input type="hidden" name="currency_code" id="of-currency-code" value="{{ $currentCode }}">
            <input type="hidden" name="currency_id" id="of-currency-id" value="{{ $currentId }}">
        </x-ui.field>

        <x-ui.field label="Exchange rate" for="of-rate" name="currency_rate">
            <x-ui.input type="number" id="of-rate" name="currency_rate"
                        step="0.00000001" min="0.00000001" class="fm-input--num"
                        value="{{ $currentRate }}" />
        </x-ui.field>

        <p class="of-rate__hint" id="of-rate-hint"></p>
    </div>
</div>
