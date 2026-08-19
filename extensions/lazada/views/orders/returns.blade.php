@extends('layouts.channel')
{{-- The channel belongs in the title because these tabs are kept open
     side by side. 'Orders' alone gave three identical browser tabs. --}}
@section('title', 'Lazada returns and refunds')
@section('breadcrumb', 'Returns and refunds')

{{-- Wrapper only: the list lives in _returns_panel so the fulfilment hub can
     render the same one in place. --}}
@section('content')
<div id="lazada-returns-page" class="co-page">
@include('ext-lazada::orders._returns_panel', ['panelOwnsHead' => true])
</div>{{-- /#lazada-returns-page --}}
@endsection
