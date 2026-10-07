@extends('layouts.blotter')
@section('title', 'New currency')
@section('breadcrumb', 'New')

@section('content')
@php
    $defaultCode = \App\Support\Money::defaultCode();
@endphp

<form method="POST" action="{{ route('currencies.store') }}" class="fm-page" data-guard-unsaved>
    @csrf

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
            <h1 class="fm-title">New currency</h1>
        </div>
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
                                    class="st-code-input" value="{{ old('code') }}" placeholder="USD" />
                    </x-ui.field>

                    <x-ui.field label="Symbol" for="st-cur-symbol" name="symbol" :required="true"
                                hint="What is printed in front of an amount.">
                        <x-ui.input id="st-cur-symbol" name="symbol" maxlength="8" required
                                    value="{{ old('symbol') }}" placeholder="$" />
                    </x-ui.field>

                    <x-ui.field label="Name" for="st-cur-name" name="name" :required="true" wide>
                        <x-ui.input id="st-cur-name" name="name" maxlength="64" required
                                    value="{{ old('name') }}" placeholder="US Dollar" />
                    </x-ui.field>
                </div>
            </section>

            <section class="fm-section">
                <div class="fm-section__head">
                    <h2 class="fm-section__title">Rate</h2>
                </div>
                <p class="fm-section__note">
                    A rate says how much of {{ $defaultCode }} one unit of this currency is worth.
                    So a rate of 61.61429452 means 1 of this currency buys 61.61429452 {{ $defaultCode }}.
                    The default currency itself is always 1. Refresh rates on the list page
                    overwrites this with the European Central Bank figure.
                </p>

                <div class="fm-fields fm-fields--2">
                    <x-ui.field label="Rate" for="st-cur-rate" name="exchange_rate" :required="true"
                                hint="Up to 8 decimal places.">
                        <x-ui.input id="st-cur-rate" name="exchange_rate" required
                                    class="fm-input--num" inputmode="decimal"
                                    value="{{ old('exchange_rate', '1.00000000') }}" />
                    </x-ui.field>
                </div>
            </section>
        </div>

        <div class="fm-col fm-col--side">
            <div class="fm-card">
                <div class="fm-card__head"><h2 class="fm-card__title">How it is used</h2></div>
                <div class="fm-card__body">
                    @php $statusOn = old('status', '1') === '1'; @endphp
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
                        <input type="checkbox" class="fm-switch__input" name="is_default" value="1" @checked(old('is_default'))>
                        <span class="fm-switch__track" aria-hidden="true"></span>
                        <span class="fm-switch__text">
                            <span class="fm-switch__label">Make this the default</span>
                            <span class="fm-switch__note">Every stored figure is reported in the default currency. Turning this on turns it off for {{ $defaultCode }}.</span>
                        </span>
                    </label>
                    @error('is_default')<div class="fm-error">{{ $message }}</div>@enderror
                </div>
            </div>
        </div>
    </div>

    <x-ui.form-bar :cancel="route('currencies.index')">
        <x-slot:note>Nothing is saved until you create the currency.</x-slot:note>
        <x-slot:primary><x-ui.button type="submit" variant="primary">Create currency</x-ui.button></x-slot:primary>
    </x-ui.form-bar>
</form>
@endsection
