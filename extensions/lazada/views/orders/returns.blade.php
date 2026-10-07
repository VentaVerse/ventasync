@extends('layouts.channel')
@section('title', 'Lazada returns and refunds')
@section('breadcrumb', 'Returns and refunds')

@section('content')
<div id="lazada-returns-page" class="co-page" data-desk-scope>
@include('ext-lazada::orders._returns_panel', ['panelOwnsHead' => true])
</div>
@endsection
