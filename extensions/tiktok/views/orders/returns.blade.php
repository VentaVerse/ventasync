@extends('layouts.channel')
@section('title', 'TikTok Shop returns and refunds')
@section('breadcrumb', 'Returns and refunds')

{{-- Wrapper only: the list lives in _returns_panel so the fulfilment hub can
     render the same one in place. --}}
@section('content')
<div id="tiktok-returns-page" class="co-page" data-desk-scope>
@include('ext-tiktok::orders._returns_panel', ['panelOwnsHead' => true])
</div>
@endsection
