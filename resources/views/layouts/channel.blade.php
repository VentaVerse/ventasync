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
