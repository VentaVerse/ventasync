@extends('layouts.channel')
@section('title', 'Shopee returns and refunds')
@section('breadcrumb', 'Returns and refunds')

@section('content')
<div id="shopee-returns-page" class="co-page" data-desk-scope>
@include('ext-shopee::orders._returns_panel', ['panelOwnsHead' => true])
</div>
@endsection
