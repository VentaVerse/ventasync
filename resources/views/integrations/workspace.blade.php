@extends('layouts.channel')

{{-- Both names in the tab title on a multi-store channel: store names repeat
     across channels, so "Shop A" alone does not identify an open tab. --}}
@section('title', ($channelStoreLabel ?? null) ? $channelCard->name.' '.$channelStoreLabel : $channelCard->name)

{{-- Opt out of the .x-legacy boundary: this page is built to the current
     design, so it should use the current tokens. Task 3 made the boundary the
     default because six of the seven pages rendering in this layout are
     legacy. --}}
@section('content-class', '')

@section('content')
    <div class="x-overview-head">
        <div>
            {{-- The store is what this page is about on a multi-store
                 channel: its figures, its orders, its settings. The channel
                 it belongs to is named in the sidebar head and the
                 breadcrumb, both on screen beside this. --}}
            <h1 class="x-page-title">{{ $channelStoreLabel ?? $channelCard->name }}</h1>
            <p class="x-page-sub">{{ $channelCard->tagline }}</p>
        </div>

        {{-- The workspace's own most-needed action: Connect while the
             channel needs setup, otherwise the same "View orders" secondary
             action the status board already offers. Never both.

             Connect opens in a new tab, same as the "Authorize with Shopee"
             link on the Settings page it mirrors: it hands off to Shopee's
             own consent screen, not a page inside this app. --}}
        @if($channelAction && $channelState === 'setup')
            <x-ui.button variant="primary" size="sm" :href="$channelAction['url']" target="_blank" rel="noopener">
                {{ $channelAction['label'] }}
            </x-ui.button>
        @elseif($channelAction)
            <x-ui.button variant="secondary" size="sm" :href="$channelAction['url']">
                {{ $channelAction['label'] }}
            </x-ui.button>
        @endif
    </div>

    @if($channelWaiting)
        {{-- What needs doing, before what is merely true about the
             connection. An operator opening a channel is asking "what is
             waiting for me", and the health strip below answers a different
             question. --}}
        <div class="x-waiting">
            @foreach($channelWaiting as $row)
                <div class="x-waiting__cell">
                    <span class="x-waiting__value {{ $row['value'] === null ? 'is-untracked' : '' }}">
                        {{ $row['value'] ?? 'Not tracked' }}
                    </span>
                    <span class="x-waiting__label">{{ $row['label'] }}</span>
                </div>
            @endforeach
        </div>
    @endif

    <div class="x-health">
        @foreach($channelHealth as $row)
            <div class="x-health__cell">
                <span class="x-health__label">{{ $row['label'] }}</span>
                {{-- A null value means the figure is not tracked. Printing a
                     zero here would read as "no errors" when it means
                     "never measured". --}}
                <span class="x-health__value {{ $row['value'] === null ? 'is-untracked' : '' }}">
                    {{ $row['value'] ?? 'Not tracked' }}
                </span>
            </div>
        @endforeach
    </div>
@endsection
