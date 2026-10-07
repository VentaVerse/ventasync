@extends('layouts.channel')
@section('title', 'TikTok Shop returns and refunds')
@section('breadcrumb', 'Returns and refunds')

@section('content')
<div id="tiktok-returns-page" class="co-page" data-desk-scope>
@include('ext-tiktok::orders._returns_panel', ['panelOwnsHead' => true])
</div>
@endsection
