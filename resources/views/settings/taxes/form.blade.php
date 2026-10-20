@extends('layouts.blotter')
@section('title', $rate ? 'Edit ' . $rate->name : 'New tax rate')
@section('breadcrumb', $rate ? 'Edit' : 'New')

@section('content')
<form method="POST" action="{{ $rate ? route('taxes.update', $rate->id) : route('taxes.store') }}" class="fm-page" data-guard-unsaved>
    @csrf
    @if($rate) @method('PUT') @endif

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
            <a class="fm-back" href="{{ route('taxes.index') }}" data-guard-leave>
                <x-ui.icon name="chevron-left" size="14" /> Taxes
            </a>
            <h1 class="fm-title">{{ $rate ? $rate->name : 'New tax rate' }}</h1>
        </div>
    </div>

    <div class="fm-body">
        <div class="fm-col fm-col--main">
            <section class="fm-section">
                <div class="fm-fields fm-fields--2">
                    <x-ui.field label="Name" for="iv-rate-name" name="name" :required="true">
                        <x-ui.input id="iv-rate-name" name="name" maxlength="64" required
                                    value="{{ old('name', $rate?->name) }}" placeholder="VAT" />
                    </x-ui.field>

                    <x-ui.field label="Rate (%)" for="iv-rate-rate" name="rate" :required="true">
                        <x-ui.input id="iv-rate-rate" name="rate" required class="fm-input--num" inputmode="decimal"
                                    value="{{ old('rate', $rate ? rtrim(rtrim(number_format((float) $rate->rate, 4, '.', ''), '0'), '.') : '') }}" placeholder="12" />
                    </x-ui.field>
                </div>
            </section>
        </div>

        <div class="fm-col fm-col--side">
            <div class="fm-card">
                <div class="fm-card__body">
                    @if($rate?->is_default)
                        <x-ui.badge tone="success">Default</x-ui.badge>
                    @elseif($isFirst)
                        <x-ui.badge tone="success">Default</x-ui.badge>
                        <input type="hidden" name="is_default" value="1">
                    @else
                        <input type="hidden" name="is_default" value="0">
                        <label class="fm-switch">
                            <input type="checkbox" class="fm-switch__input" name="is_default" value="1" @checked(old('is_default'))>
                            <span class="fm-switch__track" aria-hidden="true"></span>
                            <span class="fm-switch__text">
                                <span class="fm-switch__label">Make this the default</span>
                            </span>
                        </label>
                    @endif
                    @error('is_default')<div class="fm-error">{{ $message }}</div>@enderror
                </div>
            </div>
        </div>
    </div>

    <x-ui.form-bar :cancel="route('taxes.index')">
        <x-slot:primary><x-ui.button type="submit" variant="primary">{{ $rate ? 'Save tax rate' : 'Add tax rate' }}</x-ui.button></x-slot:primary>
    </x-ui.form-bar>
</form>
@endsection
