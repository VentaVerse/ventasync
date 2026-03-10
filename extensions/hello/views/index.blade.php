@extends('layouts.app')
@section('breadcrumb', 'Hello Extension')
@section('content')
<div class="card">
    <div class="page-header">
        <h2>Hello from Extension!</h2>
    </div>
    <p>If you can see this page, the extension framework is working correctly.</p>
    <ul style="margin-top:12px;">
        <li>Extension routes are loading</li>
        <li>Extension views are rendering</li>
        <li>Extension nav injection is working</li>
    </ul>
</div>
@endsection
