@extends('layouts.blotter')
@section('title', 'Invoices')
@section('breadcrumb', 'Invoices')

@section('content')
@php
    $canManage = auth()->user()?->hasPermission('manage_invoices/settings') ?? false;
@endphp

<form method="POST" action="{{ route('settings.invoices.update') }}" class="fm-page" data-guard-unsaved>
    @csrf
    @method('PUT')

    <div class="x-list-head">
        <div>
            @include('partials.back-to-settings')
            <h1 class="x-page-title">Invoices</h1>
            <p class="x-page-sub">The invoice number series and what the PDF shows.</p>
        </div>
    </div>

    @include('partials.flash')

    @if($errors->any())
        <div class="fm-flash" role="status" aria-live="polite">
            <div class="fm-note fm-note--fail">
                <div class="fm-note__body">Nothing was saved. Check the fields marked below.</div>
            </div>
        </div>
    @endif

    <section class="fm-section">
        <div class="fm-fields fm-fields--2">
            <x-ui.field label="Invoice prefix" for="iv-prefix" name="prefix" :required="true">
                <x-ui.input id="iv-prefix" name="prefix" maxlength="24" required :readonly="!$canManage"
                            value="{{ old('prefix', $settings->prefix) }}" placeholder="INV-" />
            </x-ui.field>

            <div class="fm-field">
                <span class="fm-label">Last number given</span>
                <span class="iv-number">{{ $last ? $last->label() : 'None yet. The first will be ' . $next . '.' }}</span>
            </div>

            <div class="fm-field fm-field--wide">
                <input type="hidden" name="print_logo" value="0">
                <label class="fm-switch">
                    <input type="checkbox" class="fm-switch__input" name="print_logo" value="1" @checked(old('print_logo', $settings->print_logo)) @disabled(!$canManage)>
                    <span class="fm-switch__track" aria-hidden="true"></span>
                    <span class="fm-switch__text">
                        <span class="fm-switch__label">Print the logo on the PDF</span>
                    </span>
                </label>
            </div>
        </div>
    </section>

    @if($canManage)
        <x-ui.form-bar :cancel="route('settings.hub')">
            <x-slot:primary><x-ui.button type="submit" variant="primary">Save</x-ui.button></x-slot:primary>
        </x-ui.form-bar>
    @endif
</form>
@endsection
