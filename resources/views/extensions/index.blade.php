@extends('layouts.app')
@section('breadcrumb', 'Settings / Extensions')

@section('content')

@if(session('success'))
    <div class="alert success">{{ session('success') }}</div>
@endif
@if(session('error'))
    <div class="alert danger">{{ session('error') }}</div>
@endif
@if(session('info'))
    <div class="alert info">{{ session('info') }}</div>
@endif

<div class="card">
    <div class="page-header">
        <h2>Extensions</h2>
        <div class="page-header-actions">
            <form method="POST" action="{{ route('extensions.install') }}" enctype="multipart/form-data" style="display:flex; align-items:center; gap:8px;">
                @csrf
                <input type="file" name="file" accept=".erpx,.zip" required style="max-width:240px;">
                <button class="btn" type="submit">Install</button>
            </form>
        </div>
    </div>

    @if(count($extensions) > 0)
        <h3 class="lic-section-title" style="margin-top:24px; margin-bottom:12px;">Installed Extensions</h3>
        <div class="ext-list">
            @foreach($extensions as $ext)
                @include('extensions.partials._row', ['ext' => $ext, 'domain' => $domain])
            @endforeach
        </div>
    @else
        <p style="padding:24px; color:var(--text-secondary); text-align:center;">No extensions installed. Upload a <code>.erpx</code> or <code>.zip</code> file to get started.</p>
    @endif
</div>
@endsection
