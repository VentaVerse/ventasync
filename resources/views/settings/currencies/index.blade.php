@extends('layouts.app')
@section('breadcrumb', 'Settings / Currencies')

@section('content')
<div class="card">
    <div class="page-header">
        <h2>Currencies</h2>
        <div class="page-header-actions">
            <form method="POST" action="{{ route('currencies.update_rates') }}" style="display:inline;">
                @csrf
                <button class="btn secondary" type="submit">Update Rates</button>
            </form>
            <a class="btn" href="{{ route('currencies.create') }}">Add Currency</a>
        </div>
    </div>

    <div class="toolbar">
        <form method="GET" action="{{ route('currencies.index') }}">
            <input class="input" name="q" value="{{ $q ?? '' }}" placeholder="Search by code or name" style="max-width:300px;">
            <button class="btn" type="submit">Search</button>
        </form>
    </div>

    <div class="table-wrap">
    <table>
        <thead>
        <tr>
            <th style="width:70px;">ID</th>
            <th style="width:80px;">Code</th>
            <th>Name</th>
            <th style="width:70px;">Symbol</th>
            <th style="width:140px;">Exchange Rate</th>
            <th style="width:80px;">Default</th>
            <th style="width:80px;">Status</th>
            <th style="width:160px;">Actions</th>
        </tr>
        </thead>
        <tbody>
        @foreach($currencies as $c)
            <tr>
                <td>{{ $c->id }}</td>
                <td><strong>{{ $c->code }}</strong></td>
                <td>{{ $c->name }}</td>
                <td>{{ $c->symbol }}</td>
                <td>{{ number_format((float) $c->exchange_rate, 4) }}</td>
                <td>{!! $c->is_default ? '<span class="badge badge-green">Yes</span>' : '' !!}</td>
                <td>{!! $c->status ? '<span class="badge badge-green">Active</span>' : '<span class="badge badge-gray">Inactive</span>' !!}</td>
                <td class="d-flex gap-8 items-center">
                    <a class="btn small" href="{{ route('currencies.edit', $c->id) }}">Edit</a>
                    @if(!$c->is_default)
                    <form method="POST" action="{{ route('currencies.destroy', $c->id) }}" data-confirm="Delete this currency?">
                        @csrf
                        @method('DELETE')
                        <button class="btn danger small" type="submit">Delete</button>
                    </form>
                    @endif
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
    </div>

    <div class="mt-16">
        {{ $currencies->onEachSide(1)->links('vendor.pagination.simple') }}
    </div>
</div>

@php $artisanPath = '/usr/bin/php ' . base_path('artisan'); @endphp
<div class="card mt-16">
    <div class="page-header">
        <h2>Auto-Update Exchange Rates</h2>
    </div>
    <p style="margin-bottom:12px; color:var(--text-secondary);">
        Rates are fetched from the <strong>European Central Bank (ECB)</strong> via frankfurter.app.
        ECB publishes new rates every business day around 16:00 CET. Weekends and holidays keep the last known rate.
    </p>

    <h3 class="section-title">Cronjob Command</h3>
    <div style="display:flex; flex-direction:column; gap:12px; margin-bottom:16px;">
        <div>
            <label class="text-xs" style="margin-bottom:4px; display:flex; align-items:center; gap:6px;">
                <span style="font-weight:600; color:var(--text-primary);">Update Exchange Rates</span>
                <span class="badge badge-blue" style="font-size:10px;">ECB / frankfurter.app</span>
                <span style="margin-left:auto; color:var(--text-muted);">once daily</span>
            </label>
            <div style="display:flex; align-items:center; gap:8px; background:#0f172a; border:1px solid #334155; border-radius:var(--radius-sm); padding:10px 12px; overflow-x:auto;">
                <code id="cron-cmd" style="flex:1; font-family:ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; font-size:12.5px; color:#e2e8f0; white-space:nowrap; user-select:all;">{{ $artisanPath }} currencies:update-rates</code>
                <button type="button" class="btn small secondary" onclick="navigator.clipboard.writeText(document.getElementById('cron-cmd').textContent); this.textContent='Copied!'; setTimeout(()=>this.textContent='Copy',1500);" style="flex-shrink:0; height:28px; padding:0 10px; font-size:11px;">Copy</button>
            </div>
        </div>
    </div>
    <p style="font-size:12px; color:var(--text-muted);">
        Recommended schedule: once daily at 17:00 (after ECB publishes at ~16:00 CET)
    </p>
</div>
@endsection
