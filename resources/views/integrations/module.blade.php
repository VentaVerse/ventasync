@extends('layouts.app')
@section('breadcrumb', 'Integrations / ' . $card->name)

@section('content')

<div class="int-module-page" style="--module-accent: {{ $card->accent }};">
    <header class="int-module-header">
        <div class="int-module-icon" style="background: {{ $card->accent }};">
            @include('integrations.partials._icon', ['icon' => $card->icon, 'name' => $card->name])
        </div>
        <div class="int-module-title">
            <h2>{{ $card->name }}</h2>
            <p>{{ $card->tagline }}</p>
        </div>
        <div class="int-module-actions">
            <a href="{{ route('integrations.index') }}" class="btn btn-secondary">← Back to Integrations</a>
        </div>
    </header>

    @php
        $visibleMenu = collect($card->menu)
            ->filter(fn($m) => !$m->permission || (auth()->user() && auth()->user()->hasPermission($m->permission)))
            ->values();
    @endphp

    @if($visibleMenu->isNotEmpty())
        <div class="int-module-menu">
            @foreach($visibleMenu as $item)
                <a href="{{ route($item->routeName, $item->routeParams) }}">{{ $item->label }}</a>
            @endforeach
        </div>
    @endif

    @if(empty($card->stores))
        <div class="int-empty">
            <h3>No stores configured</h3>
            <p>Add your first {{ $card->name }} store from the Settings page.</p>
        </div>
    @else
        <div class="int-module-stores-head">
            <h3>Stores</h3>
            <span class="int-module-stores-count">{{ count($card->stores) }} configured</span>
        </div>
        <ul class="int-module-stores">
            @foreach($card->stores as $store)
                @php
                    $visibleStoreMenu = collect($store->menu)
                        ->filter(fn($m) => !$m->permission || (auth()->user() && auth()->user()->hasPermission($m->permission)))
                        ->values();
                @endphp
                <li class="int-module-store">
                    <div class="int-module-store-name">{{ $store->label }}</div>
                    @if($visibleStoreMenu->isNotEmpty())
                        <div class="int-module-store-actions">
                            @foreach($visibleStoreMenu as $item)
                                <a href="{{ route($item->routeName, $item->routeParams) }}" class="int-module-store-action">{{ $item->label }}</a>
                            @endforeach
                        </div>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</div>

@endsection
