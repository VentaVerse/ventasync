@php
    $v = fn (string $field) => old($field, $o?->{$field});

    // An existing order with its own billing address must not have it overwritten on save.
    $sameDefault = $o === null;
    if ($o !== null) {
        $billing = array_map(fn ($k) => trim((string) ($o->{'payment_' . $k} ?? '')), ['firstname', 'lastname', 'address_1', 'city', 'postcode']);
        $shipping = array_map(fn ($k) => trim((string) ($o->{'shipping_' . $k} ?? '')), ['firstname', 'lastname', 'address_1', 'city', 'postcode']);
        $sameDefault = implode('|', $billing) === '||||' || $billing === $shipping;
    }

    $addressFields = [
        ['firstname', 'First name', 32],
        ['lastname', 'Last name', 32],
        ['company', 'Company', 40],
        ['address_1', 'Address line 1', 128],
        ['address_2', 'Address line 2', 128],
        ['city', 'City', 128],
        ['postcode', 'Postcode', 10],
        ['country', 'Country', 128],
        ['zone', 'State, province or region', 128],
    ];
@endphp

<section class="fm-section">
    <div class="fm-section__head">
        <h2 class="fm-section__title">Customer</h2>
    </div>

    <div class="fm-fields fm-fields--2">
        <x-ui.field label="First name" for="of-firstname" name="firstname" :required="true">
            <x-ui.input id="of-firstname" name="firstname" maxlength="32" required value="{{ $v('firstname') }}" />
        </x-ui.field>

        <x-ui.field label="Last name" for="of-lastname" name="lastname">
            <x-ui.input id="of-lastname" name="lastname" maxlength="32" value="{{ $v('lastname') }}" />
        </x-ui.field>

        <x-ui.field label="Email" for="of-email" name="email">
            <x-ui.input type="email" id="of-email" name="email" maxlength="96" value="{{ $v('email') }}" />
        </x-ui.field>

        <x-ui.field label="Telephone" for="of-telephone" name="telephone">
            <x-ui.input id="of-telephone" name="telephone" maxlength="32" value="{{ $v('telephone') }}" />
        </x-ui.field>
    </div>
</section>

<section class="fm-section">
    <div class="fm-section__head">
        <h2 class="fm-section__title">Ship to</h2>
    </div>

    <div class="fm-fields fm-fields--2">
        @foreach($addressFields as [$field, $label, $max])
            <x-ui.field :label="$label" for="of-ship-{{ $field }}" name="shipping_{{ $field }}">
                <x-ui.input id="of-ship-{{ $field }}" name="shipping_{{ $field }}" maxlength="{{ $max }}"
                            class="of-ship" data-field="{{ $field }}"
                            value="{{ $v('shipping_' . $field) }}" />
            </x-ui.field>
        @endforeach

        <x-ui.field label="Shipping method" for="of-ship-method" name="shipping_method" wide>
            <x-ui.input id="of-ship-method" name="shipping_method" value="{{ $v('shipping_method') }}" />
        </x-ui.field>
    </div>
</section>

<section class="fm-section">
    <div class="fm-section__head">
        <h2 class="fm-section__title">Bill to</h2>
    </div>

    <label class="fm-switch">
        <input type="checkbox" class="fm-switch__input" id="of-same" @checked($sameDefault)>
        <span class="fm-switch__track" aria-hidden="true"></span>
        <span class="fm-switch__text">
            <span class="fm-switch__label">Same as the shipping address</span>
            <span class="fm-switch__note">Turn this off to bill somewhere else.</span>
        </span>
    </label>

    <div class="fm-fields fm-fields--2 of-pay-block" id="of-payment">
        @foreach($addressFields as [$field, $label, $max])
            <x-ui.field :label="$label" for="of-pay-{{ $field }}" name="payment_{{ $field }}">
                <x-ui.input id="of-pay-{{ $field }}" name="payment_{{ $field }}" maxlength="{{ $max }}"
                            class="of-pay" data-field="{{ $field }}"
                            value="{{ $v('payment_' . $field) }}" />
            </x-ui.field>
        @endforeach

        <x-ui.field label="Payment method" for="of-pay-method" name="payment_method" wide>
            <x-ui.input id="of-pay-method" name="payment_method" maxlength="128" value="{{ $v('payment_method') }}" />
        </x-ui.field>
    </div>
</section>
