@extends('install.layout')

@section('title', 'Site Config')

@section('content')
    <div class="content-header">
        <h1 class="content-title">Site configuration</h1>
        <p class="content-subtitle">Company name, default currency, timezone — basic settings you can change later.</p>
    </div>

    <div class="content-body">
        <div class="alert alert-warning">
            <strong>Phase A3.5 — not implemented yet.</strong>
        </div>

        <form class="step-form">
            <div class="step-actions step-actions-split">
                <a href="{{ route('install.database') }}" class="btn btn-secondary">Back</a>
                <a href="{{ route('install.admin') }}" class="btn btn-primary">Skip (debug only)</a>
            </div>
        </form>
    </div>
@endsection
