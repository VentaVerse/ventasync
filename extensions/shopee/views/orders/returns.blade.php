@extends('layouts.channel')
@section('breadcrumb', 'Return or Refund')

{{-- The page is now a wrapper: the list itself lives in _returns_panel so
     the fulfilment hub can render the same one in place. --}}
@section('content')
@include('ext-shopee::orders._returns_panel', ['panelOwnsHead' => true])
@endsection
