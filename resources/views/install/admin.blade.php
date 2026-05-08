@extends('install.layout')

@section('title', 'Admin Account')

@section('content')
    <div class="content-header">
        <h1 class="content-title">Create your admin account</h1>
        <p class="content-subtitle">This is the first user account with full access to your VentaSync install.</p>
    </div>

    <div class="content-body">
        <div class="alert alert-warning">
            <strong>Phase A3.5 — not implemented yet.</strong>
        </div>

        <form class="step-form">
            <div class="step-actions step-actions-split">
                <a href="{{ route('install.config') }}" class="btn btn-secondary">Back</a>
                <a href="{{ route('install.complete') }}" class="btn btn-primary">Skip (debug only)</a>
            </div>
        </form>
    </div>
@endsection
