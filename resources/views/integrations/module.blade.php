@extends('layouts.blotter')
@section('title', $card->name)
@section('breadcrumb', $card->name)

@section('content')
@php
    $user = auth()->user();

    // Same per-item gate the registry's own consumers apply: a menu entry
    // with a permission is only offered to somebody who holds it.
    $allowed = fn ($item) => ! $item->permission || ($user && $user->hasPermission($item->permission));

    $visibleMenu = collect($card->menu)->filter($allowed)->values();

    $canAdd = $card->addStore
        && (! $card->addStore->permission || ($user && $user->hasPermission($card->addStore->permission)));

    $stores = collect($card->stores);
    $pending = $stores->filter(fn ($s) => ($s->status ?? 'active') === 'setup_pending')->count();
@endphp

{{-- The store list for a channel that has no seller center of its own yet.
     A converted channel is entered through /integrations/{channel}; this page
     is what the rest still land on, so it stays on the same list anatomy
     every other index in the application uses rather than keeping its own
     card grammar. --}}
<div class="int-mod" x-data="{ addOpen: false }">

    @include('partials.flash')

    <div class="x-list-head">
        <div>
            <h1 class="x-page-title">{{ $card->name }}</h1>
            <p class="x-page-sub">{{ $card->tagline }}</p>
        </div>
        @if($canAdd)
            {{-- A disclosure, not the action: the panel's Create store is the
                 only solid fill on this page. --}}
            <x-ui.button type="button" @click="addOpen = !addOpen"
                         x-bind:aria-expanded="addOpen ? 'true' : 'false'">
                <x-ui.icon name="plus" size="14" /> {{ $card->addStore->label }}
            </x-ui.button>
        @endif
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

    @if($canAdd)
    <form method="POST" action="{{ route($card->addStore->route) }}" class="int-mod__add"
          x-show="addOpen" x-cloak x-transition.duration.120ms>
        @csrf
        <h2 class="int-mod__add-title">{{ $card->addStore->label }}</h2>
        <p class="int-mod__add-note">
            Name it and point it at the store's address. The API token and everything else are
            set on the store's own settings page straight after this.
        </p>
        <div class="int-mod__add-fields">
            <x-ui.field label="Store name" for="int-store-name" name="store_name" :required="true">
                <x-ui.input id="int-store-name" name="store_name" required
                            placeholder="{{ $card->name }} main store" />
            </x-ui.field>
            <x-ui.field label="Base URL" for="int-store-url" name="base_url" :required="true">
                <x-ui.input type="url" id="int-store-url" name="base_url" required
                            placeholder="https://store.example.com" />
            </x-ui.field>
        </div>
        <div class="int-mod__add-bar">
            <button type="button" class="fm-cancel" @click="addOpen = false">Cancel</button>
            <x-ui.button variant="primary" type="submit">Create store</x-ui.button>
        </div>
    </form>
    @endif

    @if($stores->isEmpty())
        <x-ui.empty
            title="No stores connected"
            description="{{ $canAdd
                ? 'Use ' . $card->addStore->label . ' above to connect the first one.'
                : 'Nobody has connected a ' . $card->name . ' store yet.' }}" />
    @else

    <p class="x-page-sub int-mod__count">
        {{ number_format($stores->count()) }} {{ Str::plural('store', $stores->count()) }} connected@if($pending > 0), {{ number_format($pending) }} still needing setup@endif.
    </p>

    <x-ui.table>
        <x-slot:head>
            <tr>
                <th>Store</th>
                <th class="x-col-stat">State</th>
                <th class="x-td-actions"><span class="x-sr">Actions</span></th>
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
