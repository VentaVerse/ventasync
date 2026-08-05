@extends('layouts.shell')
@section('title', 'Settings')
@section('breadcrumb', 'Settings')

@section('content')
    <h1 class="x-page-title">Settings</h1>
    <p class="x-page-sub">Everything you can configure, grouped by area.</p>
    <x-ui.hub :groups="$groups" placeholder="Search settings" />
@endsection
