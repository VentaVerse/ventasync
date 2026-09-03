{{-- The channel shell, since 2026-09-03 a THIN coat over layouts/blotter
     rather than a second application shell. It used to swap the global
     sidebar for a channel-scoped drawer, which read as teleportation: enter
     Lazada and the nav you were standing in disappears (the owner's words).
     Now every channel page keeps the global shell - sidebar, topbar, palette
     - and the channel's own menu rides as a menubar at the top of the
     content (partials.channel-menubar), sticky on every page of that
     channel.

     What this layout still owns:
     - the x-app / x-app--channel body classes, which scope the channel
       accent onto buttons, segments and focus (blotter-components.css);
     - the channel's accent variables, published once on <body> so every
       surface can ask for them without a per-channel stylesheet
       (App\Support\ChannelAccent explains each one);
     - the legacy page contract (flash, validation errors, banners,
       #js-flash-container) the fifty-plus hosted views were written
       against.

     The composer in AppServiceProvider still binds here, so $channelCard,
     $channelGroups, $channelState and friends arrive exactly as before. --}}
@extends('layouts.blotter')

@section('body-class', 'x-app x-app--channel')

@section('body-attrs')
    @php $__chAccent = $channelCard->accent ?? null; @endphp
    style="--ch: {{ \App\Support\ChannelAccent::raw($__chAccent) }}; --ch-deep: {{ \App\Support\ChannelAccent::deep($__chAccent) }}; --ch-cta: {{ \App\Support\ChannelAccent::cta($__chAccent) }}; --ch-lift: {{ \App\Support\ChannelAccent::onDark($__chAccent) }};"
@endsection

@section('shell-band')
    @include('partials.channel-menubar')
@endsection

@section('main-prelude')
    @include('partials.legacy-page-contract')
@endsection
