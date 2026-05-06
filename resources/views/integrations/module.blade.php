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
        @php
            $canAdd = $card->addStore
                && (!$card->addStore->permission || (auth()->user() && auth()->user()->hasPermission($card->addStore->permission)));
            $modalId = 'addStore_' . $card->id;
        @endphp
        <div class="int-module-actions">
            @if($canAdd)
                <button type="button" class="btn"
                        onclick="document.getElementById('{{ $modalId }}').classList.add('active')">
                    + {{ $card->addStore->label }}
                </button>
            @endif
            <a href="{{ route('integrations.index') }}" class="btn secondary">← Back to Integrations</a>
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
            <p>
                @if($canAdd)
                    Use the <strong>+ {{ $card->addStore->label }}</strong> button at the top of this page to connect your first {{ $card->name }} store.
                @else
                    No stores have been added yet.
                @endif
            </p>
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

@if($canAdd)
    <div id="{{ $modalId }}" class="modal-backdrop">
        <div class="modal" style="max-width:480px;">
            <div class="modal-header">
                <h3>{{ $card->addStore->label }} — {{ $card->name }}</h3>
                <button type="button" class="modal-close"
                        onclick="document.getElementById('{{ $modalId }}').classList.remove('active')">&times;</button>
            </div>
            <form method="POST" action="{{ route($card->addStore->route) }}">
                @csrf
                <div style="display:grid; gap:14px;">
                    <div>
                        <label class="text-xs text-muted">Store Name</label>
                        <input type="text" name="store_name" class="input" required placeholder="e.g. My {{ $card->name }} Store" autofocus>
                    </div>
                    <div>
                        <label class="text-xs text-muted">Base URL</label>
                        <input type="url" name="base_url" class="input" required placeholder="https://store.example.com">
                        <div class="text-xs text-muted" style="margin-top:4px;">You'll add the API token and other settings on the next page.</div>
                    </div>
                </div>
                <div style="display:flex; gap:8px; justify-content:flex-end; margin-top:18px;">
                    <button type="button" class="btn secondary"
                            onclick="document.getElementById('{{ $modalId }}').classList.remove('active')">Cancel</button>
                    <button type="submit" class="btn">Create Store</button>
                </div>
            </form>
        </div>
    </div>
@endif

@endsection
