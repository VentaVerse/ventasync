@extends('layouts.blotter')
@section('title', 'Integrations')

@section('content')
<h1 class="x-page-title">Integrations</h1>
<p class="x-page-sub">Connection health across every sales channel.</p>

<div class="x-board">
    @forelse($channels as $c)
        <article class="x-board__card">
            <header class="x-board__head">
                <div>
                    <p class="x-board__name">{{ $c['label'] }}</p>
                    @if($c['store'])<p class="x-board__store">{{ $c['store'] }}</p>@endif
                </div>
                {{-- Four states, not three. `untracked` means this channel
                     reports nothing to judge it by, which is not the same as
                     being unconfigured - a two-way ternary painted it red and
                     told the seller to set up a store that was working. The
                     channel nav already words this state correctly; this card
                     was the last place still collapsing it into "Setup
                     needed". --}}
                @php
                    $tone = ['connected' => 'success', 'attention' => 'warning', 'setup' => 'danger'][$c['state']] ?? 'neutral';
                    $stateLabel = ['connected' => 'Connected', 'attention' => 'Needs attention', 'setup' => 'Not connected'][$c['state']] ?? 'Not tracked';
                @endphp
                <x-ui.badge :tone="$tone">{{ $stateLabel }}</x-ui.badge>
            </header>

            <dl class="x-board__health">
                <div><dt>Last sync</dt><dd class="x-num @if(is_null($c['lastSync'])) x-board__health-value--empty @endif">{{ $c['lastSync'] ?? 'not tracked' }}</dd></div>
                <div><dt>{{ ($c['tokenExpired'] ?? false) ? 'Token expired' : 'Token expires' }}</dt><dd class="x-num @if(is_null($c['tokenExpiry'])) x-board__health-value--empty @endif">{{ $c['tokenExpiry'] ?? 'not tracked' }}</dd></div>
                <div><dt>Errors, 24h</dt><dd class="x-num @if(is_null($c['errors24h'])) x-board__health-value--empty @endif">{{ $c['errors24h'] ?? 'not tracked' }}</dd></div>
            </dl>

            <footer class="x-board__foot">
                <x-ui.button variant="secondary" size="sm" :href="$c['enterUrl']">Open</x-ui.button>
                @if($c['actionUrl'])
                    <x-ui.button variant="ghost" size="sm" :href="$c['actionUrl']">{{ $c['actionLabel'] }}</x-ui.button>
                @endif
            </footer>
        </article>
    @empty
        <x-ui.empty
            title="No integrations available"
            description="Enable a marketplace integration on the Extensions page to see its health here." />
    @endforelse
</div>
@endsection
