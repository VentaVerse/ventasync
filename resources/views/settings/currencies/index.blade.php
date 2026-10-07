@extends('layouts.blotter')
@section('title', 'Currencies')
@section('breadcrumb', 'Currencies')

@section('content')
@php
    $canManageCurrencies = auth()->user()?->hasPermission('manage_settings/currency') ?? false;

    $defaultCode = \App\Support\Money::defaultCode();

    $hasFilters = ($q ?? '') !== '';
    $artisanPath = '/usr/bin/php ' . base_path('artisan');
@endphp

<div class="x-list-head">
    <div>
        @include('partials.back-to-settings')
        <h1 class="x-page-title">Currencies</h1>
        <p class="x-page-sub">
            {{ number_format($currencies->total()) }} {{ Str::plural('currency', $currencies->total()) }}.
            A rate says how much of {{ $defaultCode }} one unit of that currency is worth.
        </p>
    </div>
    @if($canManageCurrencies)
    <div class="st-head-actions">
        <form method="POST" action="{{ route('currencies.update_rates') }}">
            @csrf
            <x-ui.button type="submit">Refresh rates</x-ui.button>
        </form>
        <x-ui.button variant="primary" :href="route('currencies.create')">New currency</x-ui.button>
    </div>
    @endif
</div>

@include('partials.flash')

<form method="GET" action="{{ route('currencies.index') }}" class="x-filters">
    <label class="x-sr" for="st-cur-q">Search currencies</label>
    <x-ui.input type="search" id="st-cur-q" name="q" value="{{ $q ?? '' }}"
                placeholder="Search by code or name" class="x-filters__search" />
</form>

@if($currencies->isEmpty())
    <x-ui.empty
        title="{{ $hasFilters ? 'No currencies match that search' : 'No currencies yet' }}"
        description="{{ $hasFilters ? 'Try a different code or name.' : 'Add the currencies you sell and buy in. One of them has to be the default that every figure is reported in.' }}">
        @if($canManageCurrencies && !$hasFilters)
            <x-slot:action>
                <x-ui.button variant="primary" :href="route('currencies.create')">New currency</x-ui.button>
            </x-slot:action>
        @endif
    </x-ui.empty>
@else
<x-ui.table>
    <x-slot:head>
        <tr>
            <th scope="col" class="st-col-code">Code</th>
            <th scope="col">Name</th>
            <th scope="col" class="st-col-symbol">Symbol</th>
            <th scope="col" class="st-col-rate x-td-num">Rate</th>
            <th scope="col" class="st-col-stat">Status</th>
            <th scope="col" class="bl-col-actions"><span class="x-sr">Actions</span></th>
        </tr>
    </x-slot:head>

    @foreach($currencies as $c)
        <tr>
            <td class="st-col-code" data-label="Code">
                @if($canManageCurrencies)
                    <a class="x-row-link x-num" href="{{ route('currencies.edit', $c->id) }}">{{ $c->code }}</a>
                @else
                    <span class="x-num">{{ $c->code }}</span>
                @endif
            </td>
            <td data-label="Name">
                {{ $c->name }}
                @if($c->is_default)
                    <div class="st-note">Default. Every stored figure is reported in this currency.</div>
                @endif
            </td>
            <td class="st-col-symbol" data-label="Symbol">{{ $c->symbol }}</td>
            <td class="st-col-rate x-td-num" data-label="Rate">
                <div class="st-rate">
                    <span class="st-rate__value">{{ rtrim(rtrim(number_format((float) $c->exchange_rate, 8, '.', ''), '0'), '.') }}</span>
                    <span class="st-rate__basis">1 {{ $c->code }} = {{ rtrim(rtrim(number_format((float) $c->exchange_rate, 8, '.', ''), '0'), '.') }} {{ $defaultCode }}</span>
                </div>
            </td>
            <td class="st-col-stat" data-label="Status">
                <x-ui.badge :tone="$c->status ? 'success' : 'neutral'">{{ $c->status ? 'Active' : 'Inactive' }}</x-ui.badge>
            </td>
            <td class="bl-col-actions">
                @if($canManageCurrencies)
                <div class="bl-rowactions">
                    <a class="x-btn x-btn--sm" href="{{ route('currencies.edit', $c->id) }}">Edit</a>
                    @unless($c->is_default)
                        <button type="button" class="x-btn x-btn--sm x-btn--danger"
                                data-confirm="Delete {{ $c->code }}? This cannot be undone."
                                data-confirm-submit="st-cur-del-{{ $c->id }}">Delete</button>
                    @endunless
                </div>
                @endif
            </td>
        </tr>
    @endforeach
</x-ui.table>

@if($canManageCurrencies)
    @foreach($currencies as $c)
        @unless($c->is_default)
            <form id="st-cur-del-{{ $c->id }}" method="POST" action="{{ route('currencies.destroy', $c->id) }}" class="x-sr">
                @csrf
                @method('DELETE')
            </form>
        @endunless
    @endforeach
@endif

<x-ui.pager :paginator="$currencies" />
@endif

<div class="st-cron">
    <h2 class="st-cron__title">Keeping rates up to date</h2>
    <p class="st-cron__note">
        Rates come from the European Central Bank through frankfurter.app, which publishes once
        every business day at about 16:00 CET. Weekends and holidays keep the last rate. Refresh
        rates above does it now for every active currency that is not the default; the line below
        does the same thing on a schedule. Run it once a day at 17:00.
    </p>
    <div class="st-cron__row">
        <code class="st-cron__cmd" id="st-cron-cmd">{{ $artisanPath }} currencies:update-rates</code>
        <x-ui.button type="button" size="sm" data-copy-target="st-cron-cmd">Copy</x-ui.button>
    </div>
</div>
@endsection
