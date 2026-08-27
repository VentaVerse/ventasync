{{--
    The currency card.

    `currencies.exchange_rate` and an order's `currency_value` both mean
    "units of the default currency per 1 unit of this currency"
    (App\Services\OrderCurrencyService's class docblock is the authority, and
    CurrencyController::fetchAndUpdateRates() inverts the ECB figure to match).
    So the sentence under the field reads "1 USD = PHP 61.61", never the other
    way round, and the default currency's code is resolved through
    App\Support\Money rather than written into the view.

    Props supplied by the caller:
      $currencies   the active currencies, default first
      $currentCode  the code that should start selected
      $currentRate  the rate that should start in the field
      $currentId    the currency id that should start in the hidden field
--}}
<div class="fm-card">
    <div class="fm-card__head"><h2 class="fm-card__title">Currency</h2></div>
    <div class="fm-card__body">

        <x-ui.field label="Currency" for="of-currency" name="currency_code">
            {{-- The select itself carries no name. It drives the two hidden
                 fields below, which are what the controller reads. --}}
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
