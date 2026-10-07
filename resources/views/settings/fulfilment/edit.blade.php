@extends('layouts.blotter')
@section('title', 'Fulfilment')
@section('breadcrumb', 'Fulfilment')

@section('content')
<form method="POST" action="{{ route('settings.fulfilment.update') }}" class="fm-page" data-guard-unsaved>
    @csrf
    @method('PUT')

    @include('partials.flash')

    <div class="fm-head">
        <div class="fm-head__main">
            <a class="fm-back" href="{{ route('settings.hub') }}" data-guard-leave>
                <x-ui.icon name="chevron-left" size="14" /> Settings
            </a>
            <h1 class="fm-title">Fulfilment</h1>
        </div>
    </div>

    <div class="fm-body st-body--one">
        <div class="fm-col fm-col--main">
            <section class="fm-section">
                <div class="fm-section__head">
                    <h2 class="fm-section__title">Packing check</h2>
                </div>
                <label class="fm-switch">
                    <input type="checkbox" class="fm-switch__input" name="packing_check" value="1" @checked(old('packing_check', $setting->packing_check))>
                    <span class="fm-switch__track" aria-hidden="true"></span>
                    <span class="fm-switch__text">
                        <span class="fm-switch__label">Count every item before booking</span>
                    </span>
                </label>
                @error('packing_check')<div class="fm-error">{{ $message }}</div>@enderror
            </section>
        </div>
    </div>

    <x-ui.form-bar :cancel="route('settings.hub')">
        <x-slot:primary><x-ui.button type="submit" variant="primary">Save changes</x-ui.button></x-slot:primary>
    </x-ui.form-bar>
</form>
@endsection
