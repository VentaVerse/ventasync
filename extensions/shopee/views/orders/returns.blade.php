@extends('layouts.channel')
{{-- The channel belongs in the title because these tabs are kept open
     side by side. 'Orders' alone gave three identical browser tabs. --}}
@section('title', 'Shopee returns and refunds')
@section('breadcrumb', 'Returns and refunds')

{{-- The page is now a wrapper: the list itself lives in _returns_panel so
     the fulfilment hub can render the same one in place. --}}
@section('content')
<div id="shopee-returns-page" class="co-page">
@include('ext-shopee::orders._returns_panel', ['panelOwnsHead' => true])
</div>{{-- /#shopee-returns-page --}}
@endsection
