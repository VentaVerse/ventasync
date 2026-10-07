@extends('layouts.blotter')
@section('title', $card->name)
@section('breadcrumb', $card->name)

@section('content')
@php
    $user = auth()->user();

    $allowed = fn ($item) => ! $item->permission || ($user && $user->hasPermission($item->permission));

    $visibleMenu = collect($card->menu)->filter($allowed)->values();

    $stores = collect($card->stores);
    $pending = $stores->filter(fn ($s) => ($s->status ?? 'active') === 'setup_pending')->count();
@endphp

<div class="int-mod">

    @include('partials.flash')

    <div class="x-list-head">
        <div>
            <h1 class="x-page-title">{{ $card->name }}</h1>
            <p class="x-page-sub">{{ $card->tagline }}</p>
        </div>
    </div>

    @if($visibleMenu->isNotEmpty())
        <nav class="int-mod__nav" aria-label="{{ $card->name }} pages">
            @foreach($visibleMenu as $item)
                <a class="int-mod__navlink" href="{{ route($item->routeName, $item->routeParams) }}">
                    {{ $item->label }}
                    <x-ui.icon name="chevron-right" size="12" />
                </a>
            @endforeach
        </nav>
    @endif

    @if($stores->isEmpty())
        <x-ui.empty
            title="No stores connected"
            description="{{ $card->addStore
                ? 'Nobody has connected a ' . $card->name . ' store yet. Add store on the Channels page connects the first one.'
                : 'Nobody has connected a ' . $card->name . ' store yet.' }}">
            @if($card->addStore)
                <x-slot:action>
                    <x-ui.button variant="secondary" :href="route('channels.index')">Channels</x-ui.button>
                </x-slot:action>
            @endif
        </x-ui.empty>
    @else

    <p class="x-page-sub int-mod__count">
        {{ number_format($stores->count()) }} {{ Str::plural('store', $stores->count()) }} connected@if($pending > 0), {{ number_format($pending) }} still needing setup@endif.
    </p>

    <x-ui.table>
        <x-slot:head>
            <tr>
                <th scope="col">Store</th>
                <th scope="col" class="x-col-stat">State</th>
                <th scope="col" class="x-td-actions"><span class="x-sr">Actions</span></th>
            </tr>
        </x-slot:head>

        @foreach($stores as $store)
            @php
                $storeMenu = collect($store->menu)->filter($allowed)->values();
                $isPending = ($store->status ?? 'active') === 'setup_pending';
                $first = $storeMenu->first();
            @endphp
            <tr>
                <td data-label="Store">
                    @if($first)
                        <a class="x-row-link" href="{{ route($first->routeName, $first->routeParams) }}">{{ $store->label }}</a>
                    @else
                        {{ $store->label }}
                    @endif
                </td>
                <td class="x-col-stat" data-label="State">
                    <x-ui.badge :tone="$isPending ? 'warning' : 'success'">{{ $isPending ? 'Setup pending' : 'Connected' }}</x-ui.badge>
                </td>
                <td class="x-td-actions">
                    @if($storeMenu->isNotEmpty())
                    <x-ui.menu label="{{ $store->label }} actions">
                        @foreach($storeMenu as $item)
                            <a class="x-menu__item" href="{{ route($item->routeName, $item->routeParams) }}">{{ $item->label }}</a>
                        @endforeach
                    </x-ui.menu>
                    @endif
                </td>
            </tr>
        @endforeach
    </x-ui.table>

    @endif
</div>
@endsection
