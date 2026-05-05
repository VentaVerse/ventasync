@extends('layouts.app')
@section('breadcrumb', 'Orders')

@section('content')

<div class="card">
    <div class="page-header">
        <h2>Orders</h2>
    </div>

    <div class="int-page">
        <div class="int-empty">
            <h3>No marketplace orders available</h3>
            <p>
                Activate or enable a marketplace integration on the
                <a href="{{ route('extensions.index') }}">Extensions page</a>,
                or grant yourself the appropriate orders permission to see
                marketplace order tabs here.
            </p>
        </div>
    </div>
</div>

@endsection
