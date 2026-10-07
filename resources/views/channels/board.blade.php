@extends('layouts.blotter')
@section('title', 'Channels')

@section('content')
<div class="x-list-head">
    <div>
        <h1 class="x-page-title">Channels</h1>
        <p class="x-page-sub">Connection health across every sales channel.</p>
    </div>
    @if(!empty($addable))
        <div class="cc-head-actions">
            <x-ui.button variant="primary" data-add-store-open>
                <x-ui.icon name="plus" size="14" /> Add store
            </x-ui.button>
        </div>
    @endif
</div>

@if(empty($channels))
        <x-ui.empty
            title="No stores yet"
            :description="! empty($addable)
                ? 'Press Add store to connect the first one. A channel appears here once it has a store.'
                : 'Enable a channel on the Extensions page, then add its first store here.'">
            @if(!empty($addable))
                <x-slot:action>
                    <x-ui.button variant="primary" data-add-store-open><x-ui.icon name="plus" size="14" /> Add store</x-ui.button>
                </x-slot:action>
            @endif
        </x-ui.empty>
@else
<div class="x-board">
    @foreach($channels as $c)
        <article class="x-board__card">
            <header class="x-board__head">
                @php $cMark = \App\Extensions\ExtensionImages::has(strtok((string) $c['key'], ':'), 'logo.png'); @endphp
                @if($cMark)
                    <img class="x-board__mark" src="{{ \App\Extensions\ExtensionImages::url(strtok((string) $c['key'], ':'), 'logo.png') }}" alt="">
                @endif
                <div>
                    <p class="x-board__name">{{ $c['label'] }}</p>
                    @if($c['store'])<p class="x-board__store">{{ $c['store'] }}</p>@endif
                </div>
                @php($badge = \App\Support\ChannelWorkspace::badge($c['state'], $c['stateReason'] ?? null))
                <x-ui.badge :tone="$badge['tone']">{{ $badge['label'] }}</x-ui.badge>
            </header>

            <dl class="x-board__health">
                <div><dt>Last sync</dt><dd class="x-num @if(is_null($c['lastSync'])) x-board__health-value--empty @endif">{{ $c['lastSync'] ?? 'not tracked' }}</dd></div>
                <div><dt>{{ ($c['tokenExpired'] ?? false) ? 'Token expired' : 'Token expires' }}</dt><dd class="x-num @if(is_null($c['tokenExpiry'])) x-board__health-value--empty @endif">{{ $c['tokenExpiry'] ?? 'not tracked' }}</dd></div>
                <div><dt>Errors, 24h</dt><dd class="x-num @if(is_null($c['errors24h'])) x-board__health-value--empty @endif">{{ $c['errors24h'] ?? 'not tracked' }}</dd></div>
            </dl>

            <footer class="x-board__foot">
                <x-ui.button variant="secondary" size="sm" :href="$c['enterUrl']">Open</x-ui.button>
                @if($c['productsUrl'] ?? null)
                    <x-ui.button variant="ghost" size="sm" :href="$c['productsUrl']">Products</x-ui.button>
                @endif
                @if($c['actionUrl'])
                    <x-ui.button variant="ghost" size="sm" :href="$c['actionUrl']">{{ $c['actionLabel'] }}</x-ui.button>
                @endif
            </footer>
        </article>
    @endforeach
</div>
@endif

@if(!empty($addable))
    @include('channels._add-store-modal')
@endif
@endsection
