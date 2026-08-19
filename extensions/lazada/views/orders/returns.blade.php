@extends('layouts.channel')
@section('breadcrumb', 'Return or Refund')

{{-- Wrapper only: the list lives in _returns_panel so the fulfilment hub can
     render the same one in place. --}}
@section('content')
@include('ext-lazada::orders._returns_panel', ['panelOwnsHead' => true])
@endsection
