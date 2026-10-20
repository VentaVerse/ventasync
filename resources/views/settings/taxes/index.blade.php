@extends('layouts.blotter')
@section('title', 'Taxes')
@section('breadcrumb', 'Taxes')

@section('content')
@php
    $canManage = auth()->user()?->hasPermission('manage_invoices/tax') ?? false;
@endphp

<div class="x-list-head">
    <div>
        @include('partials.back-to-settings')
        <h1 class="x-page-title">Taxes</h1>
        <p class="x-page-sub">How this business is registered for tax, and the rates its invoices use.</p>
    </div>
</div>

@include('partials.flash')

<form method="POST" action="{{ route('taxes.registration') }}" class="fm-page" data-guard-unsaved>
    @csrf
    @method('PUT')

    <section class="fm-section">
        <div class="fm-section__head">
            <h2 class="fm-section__title">Registration</h2>
        </div>

        <div class="fm-fields fm-fields--2">
            <x-ui.field label="Tax status" for="iv-tax-status" name="tax_status" :required="true">
                <x-ui.select id="iv-tax-status" name="tax_status" :disabled="!$canManage">
                    <option value="vat" @selected(old('tax_status', $settings->tax_status) === 'vat')>VAT registered</option>
                    <option value="non_vat" @selected(old('tax_status', $settings->tax_status) === 'non_vat')>Non-VAT</option>
                </x-ui.select>
            </x-ui.field>

            <x-ui.field label="Business style" for="iv-business-style" name="business_style">
                <x-ui.input id="iv-business-style" name="business_style" maxlength="255" :readonly="!$canManage"
                            value="{{ old('business_style', $settings->business_style) }}" />
            </x-ui.field>

            <x-ui.field label="TIN" for="iv-tin" name="tin">
                <x-ui.input id="iv-tin" name="tin" maxlength="11" class="fm-input--num" inputmode="numeric" :readonly="!$canManage"
                            value="{{ old('tin', $settings->tin) }}" placeholder="123456789" />
            </x-ui.field>

            <x-ui.field label="Branch code" for="iv-branch" name="branch_code">
                <x-ui.input id="iv-branch" name="branch_code" maxlength="5" class="fm-input--num" inputmode="numeric" :readonly="!$canManage"
                            value="{{ old('branch_code', $settings->branch_code) }}" placeholder="00000" />
            </x-ui.field>
        </div>

        @if($canManage)
            <div class="iv-actions">
                <x-ui.button type="submit" variant="primary">Save registration</x-ui.button>
            </div>
        @endif
    </section>
</form>

<div class="x-list-head iv-rates-head">
    <h2 class="fm-section__title">Tax rates</h2>
    @if($canManage)
        <x-ui.button :href="route('taxes.create')">New tax rate</x-ui.button>
    @endif
</div>

@if($rates->isEmpty())
    <x-ui.empty title="No tax rates yet" description="Invoices show no VAT breakdown until a rate is marked as the default.">
        @if($canManage)
            <x-slot:action>
                <x-ui.button variant="primary" :href="route('taxes.create')">New tax rate</x-ui.button>
            </x-slot:action>
        @endif
    </x-ui.empty>
@else
<x-ui.table>
    <x-slot:head>
        <tr>
            <th scope="col">Name</th>
            <th scope="col" class="x-td-num">Rate</th>
            <th scope="col">Default</th>
            <th scope="col" class="bl-col-actions"><span class="x-sr">Actions</span></th>
        </tr>
    </x-slot:head>

    @foreach($rates as $rate)
        <tr>
            <td data-label="Name">
                @if($canManage)
                    <a class="x-row-link" href="{{ route('taxes.edit', $rate->id) }}">{{ $rate->name }}</a>
                @else
                    {{ $rate->name }}
                @endif
            </td>
            <td class="x-td-num" data-label="Rate">{{ $rate->percent() }}</td>
            <td data-label="Default">
                @if($rate->is_default)<x-ui.badge tone="success">Default</x-ui.badge>@endif
            </td>
            <td class="bl-col-actions">
                @if($canManage)
                <div class="bl-rowactions">
                    <a class="x-btn x-btn--sm" href="{{ route('taxes.edit', $rate->id) }}">Edit</a>
                    @unless($rate->is_default)
                        <button type="button" class="x-btn x-btn--sm x-btn--danger"
                                data-confirm="Delete the tax rate {{ $rate->name }}? This cannot be undone."
                                data-confirm-submit="iv-rate-del-{{ $rate->id }}">Delete</button>
                    @endunless
                </div>
                @endif
            </td>
        </tr>
    @endforeach
</x-ui.table>

@if($canManage)
    @foreach($rates as $rate)
        @unless($rate->is_default)
            <form id="iv-rate-del-{{ $rate->id }}" method="POST" action="{{ route('taxes.destroy', $rate->id) }}" class="x-sr">
                @csrf
                @method('DELETE')
            </form>
        @endunless
    @endforeach
@endif
@endif
@endsection
