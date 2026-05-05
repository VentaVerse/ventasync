@extends('layouts.app')
@section('breadcrumb', 'Integrations')

@section('content')

<div class="card">
    <div class="page-header">
        <h2>Integrations</h2>
        <div class="page-header-actions">
            <a href="{{ route('extensions.index') }}" class="btn btn-secondary">Manage Extensions</a>
        </div>
    </div>

    <div class="int-page">
        @if(empty($cards))
            <div class="int-empty">
                <h3>No integrations available</h3>
                <p>
                    Activate or enable a marketplace integration on the
                    <a href="{{ route('extensions.index') }}">Extensions page</a>
                    to see it here.
                </p>
            </div>
        @else
            <div class="int-grid">
                @foreach($cards as $card)
                    <div class="int-card" id="int-{{ $card->id }}">
                        <div class="int-card-head">
                            <div class="int-card-icon" style="background: {{ $card->accent }};">
                                @include('integrations.partials._icon', ['icon' => $card->icon, 'name' => $card->name])
                            </div>
                            <div class="int-card-title">
                                <h3>
                                    {{ $card->name }}
                                    <span class="int-card-pill">Active</span>
                                </h3>
                                <p class="int-card-tagline">{{ $card->tagline }}</p>
                            </div>
                        </div>

                        @php
                            $visibleMenu = collect($card->menu)
                                ->filter(fn($m) => !$m->permission || (auth()->user() && auth()->user()->hasPermission($m->permission)))
                                ->values();
                        @endphp

                        @if($visibleMenu->isNotEmpty())
                            <ul class="int-card-menu">
                                @foreach($visibleMenu as $item)
                                    <li>
                                        <a href="{{ route($item->routeName, $item->routeParams) }}">
                                            <span>{{ $item->label }}</span>
                                        </a>
                                    </li>
                                @endforeach
                            </ul>
                        @endif

                        @if(!empty($card->stores))
                            <div class="int-card-stores">
                                <div class="int-card-stores-head">Stores</div>
                                <ul class="int-card-stores-list">
                                    @foreach($card->stores as $store)
                                        @php
                                            $visibleStoreMenu = collect($store->menu)
                                                ->filter(fn($m) => !$m->permission || (auth()->user() && auth()->user()->hasPermission($m->permission)))
                                                ->values();
                                        @endphp
                                        <li class="int-store-row">
                                            <span class="int-store-name">{{ $store->label }}</span>
                                            @if($visibleStoreMenu->isNotEmpty())
                                                <span class="int-store-actions">
                                                    @foreach($visibleStoreMenu as $i => $item)
                                                        @if($i > 0)<span class="int-store-sep">·</span>@endif
                                                        <a href="{{ route($item->routeName, $item->routeParams) }}">{{ $item->label }}</a>
                                                    @endforeach
                                                </span>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>

@endsection
