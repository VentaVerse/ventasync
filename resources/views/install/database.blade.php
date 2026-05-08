@extends('install.layout')

@section('title', 'Database')

@section('content')
    <div class="content-header">
        <h1 class="content-title">Database connection</h1>
        <p class="content-subtitle">Provide credentials for your MySQL/MariaDB database. We'll test the connection and run the schema setup.</p>
    </div>

    <div class="content-body">
        <div class="alert alert-warning">
            <strong>Phase A3.4 — not implemented yet.</strong> This step's controller logic ships next. Click Back to test the existing flow.
        </div>

        <form class="step-form">
            <div class="step-actions step-actions-split">
                <a href="{{ route('install.requirements') }}" class="btn btn-secondary">Back</a>
                <a href="{{ route('install.config') }}" class="btn btn-primary">Skip (debug only)</a>
            </div>
        </form>
    </div>
@endsection
