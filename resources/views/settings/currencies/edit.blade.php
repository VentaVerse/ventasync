@extends('layouts.blotter')
@section('title', 'Edit ' . $currency->code)
@section('breadcrumb', $currency->code)

@section('content')
@php
    $defaultCode = \App\Support\Money::defaultCode();
    $isDefault = (bool) $currency->is_default;
    $rate = rtrim(rtrim(number_format((float) $currency->exchange_rate, 8, '.', ''), '0'), '.');
@endphp

<form method="POST" action="{{ route('currencies.update', $currency->id) }}" class="fm-page" data-guard-unsaved>
    @csrf
    @method('PUT')

    @include('partials.flash')

    @if($errors->any())
        <div class="fm-flash" role="status" aria-live="polite">
            <div class="fm-note fm-note--fail">
                <div class="fm-note__body">Nothing was saved. Check the fields marked below.</div>
            </div>
        </div>
    @endif

    <div class="fm-head">
        <div class="fm-head__main">
            <a class="fm-back" href="{{ route('currencies.index') }}" data-guard-leave>
                <x-ui.icon name="chevron-left" size="14" /> Currencies
            </a>
            <h1 class="fm-title">{{ $currency->code }}</h1>
            <div class="fm-stamps">
                <span class="fm-stamp">
                    <span class="fm-stamp__k">Name</span>
                    <span class="fm-stamp__v">{{ $currency->name }}</span>
                </span>
                <span class="fm-stamp">
                    <span class="fm-stamp__k">Rate</span>
                    <span class="fm-stamp__v">1 {{ $currency->code }} = {{ $rate }} {{ $defaultCode }}</span>
                </span>
            </div>
        </div>

        @unless($isDefault)
        <div class="fm-head__actions">
            <x-ui.menu label="{{ $currency->code }} actions">
                <button type="button" class="x-menu__item x-menu__item--danger"
                        data-confirm="Delete {{ $currency->code }}? This cannot be undone."
                        data-confirm-submit="st-cur-delete-form">Delete currency</button>
            </x-ui.menu>
        </div>
        @endunless
    </div>

    <div class="fm-body">
        <div class="fm-col fm-col--main">
            <section class="fm-section">
                <div class="fm-section__head">
                    <h2 class="fm-section__title">What it is</h2>
                </div>

                <div class="fm-fields fm-fields--2">
                    <x-ui.field label="Code" for="st-cur-code" name="code" :required="true"
                                hint="Three letters, e.g. USD.">
                        <x-ui.input id="st-cur-code" name="code" maxlength="3" required
                                    class="st-code-input" value="{{ old('code', $currency->code) }}" />
                    </x-ui.field>

                    <x-ui.field label="Symbol" for="st-cur-symbol" name="symbol" :required="true"
                                hint="What is printed in front of an amount.">
                        <x-ui.input id="st-cur-symbol" name="symbol" maxlength="8" required
                                    value="{{ old('symbol', $currency->symbol) }}" />
                    </x-ui.field>

                    <x-ui.field label="Name" for="st-cur-name" name="name" :required="true" wide>
                        <x-ui.input id="st-cur-name" name="name" maxlength="64" required
                                    value="{{ old('name', $currency->name) }}" />
                    </x-ui.field>
                </div>
            </section>

            <section class="fm-section">
                <div class="fm-section__head">
                    <h2 class="fm-section__title">Rate</h2>
                </div>
                <p class="fm-section__note">
                    @if($isDefault)
                        This is the default currency, so every other rate is expressed in it and
                        its own rate is always 1.
                    @else
                        A rate says how much of {{ $defaultCode }} one unit of this currency is worth.
                        The current rate reads 1 {{ $currency->code }} = {{ $rate }} {{ $defaultCode }}.
                        Refresh rates on the list page overwrites this with the European Central
                        Bank figure.
                    @endif
                </p>

                <div class="fm-fields fm-fields--2">
                    <x-ui.field label="Rate" for="st-cur-rate" name="exchange_rate" :required="true"
                                hint="Up to 8 decimal places.">
                        <x-ui.input id="st-cur-rate" name="exchange_rate" required
                                    class="fm-input--num" inputmode="decimal"
                                    value="{{ old('exchange_rate', $currency->exchange_rate) }}" />
                    </x-ui.field>
                </div>
            </section>
        </div>

        <div class="fm-col fm-col--side">
            <div class="fm-card">
                <div class="fm-card__head"><h2 class="fm-card__title">How it is used</h2></div>
                <div class="fm-card__body">
                    @php $statusOn = old('status', (string) $currency->status) === '1'; @endphp
                    <input type="hidden" name="status" value="0">
                    <label class="fm-switch">
                        <input type="checkbox" class="fm-switch__input" name="status" value="1" @checked($statusOn)>
                        <span class="fm-switch__track" aria-hidden="true"></span>
                        <span class="fm-switch__text">
                            <span class="fm-switch__label">Active</span>
                            <span class="fm-switch__note">Off hides it from currency pickers and from rate refreshes.</span>
                        </span>
                    </label>
                    @error('status')<div class="fm-error">{{ $message }}</div>@enderror

                    <label class="fm-switch">
                        <input type="checkbox" class="fm-switch__input" name="is_default" value="1" @checked(old('is_default', $currency->is_default))>
                        <span class="fm-switch__track" aria-hidden="true"></span>
                        <span class="fm-switch__text">
                            <span class="fm-switch__label">Default currency</span>
                            <span class="fm-switch__note">
                                @if($isDefault)
                                    Every stored figure is reported in this currency. Turn it on for another currency to move the default.
                                @else
                                    Turning this on makes {{ $currency->code }} the currency every figure is reported in, and turns it off for {{ $defaultCode }}.
                                @endif
                            </span>
                        </span>
                    </label>
                    @error('is_default')<div class="fm-error">{{ $message }}</div>@enderror

                    @if($isDefault)
                        <p class="st-note">The default currency cannot be deleted. Make another one the default first.</p>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <x-ui.form-bar :cancel="route('currencies.index')">
        <x-slot:note>{{ $currency->code }}</x-slot:note>
        <x-slot:primary><x-ui.button type="submit" variant="primary">Save changes</x-ui.button></x-slot:primary>
    </x-ui.form-bar>
</form>

@unless($isDefault)
<form id="st-cur-delete-form" method="POST" action="{{ route('currencies.destroy', $currency->id) }}" class="x-sr">
    @csrf
    @method('DELETE')
</form>
@endunless
@endsection
