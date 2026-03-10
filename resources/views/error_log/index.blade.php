@extends('layouts.app')
@section('breadcrumb', 'Settings / Error Log')

@section('content')
<div class="card">
    <div class="page-header">
        <h2>Error Log</h2>

        <div class="page-header-actions">
            <form method="POST" action="{{ route('error_log.clear') }}" style="margin:0;" data-confirm="Clear error.log? This cannot be undone.">
                @csrf
                <button type="submit" class="btn danger">Clear error.log</button>
            </form>

            <form method="POST" action="{{ route('error_log.test') }}" style="margin:0;">
                @csrf
                <button type="submit" class="btn">Generate Test Warning</button>
            </form>
        </div>
    </div>

    <div class="toolbar" style="display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;">
        <div style="min-width:0;">
            <div class="font-semibold">File:</div>
            <div style="font-family:monospace; word-break:break-all; overflow-wrap:anywhere;">{{ $logPath }}</div>
        </div>

        <div class="text-right" style="min-width:120px;">
            <div class="font-semibold">Size:</div>
            <div>{{ $sizeHuman }} ({{ number_format($sizeBytes) }} bytes)</div>
            <div class="mt-0" style="margin-top:6px; font-size:12px;">
                <span class="font-semibold">Writable:</span>
                @if($writable)
                    <span style="color:#2ecc71;">Yes</span>
                @else
                    <span style="color:#e74c3c;">No</span>
                @endif
            </div>
        </div>
    </div>

    @if(!$logExists)
        <div class="alert danger mt-12">
            error.log not found. It will be created automatically when the first PHP error is logged.
        </div>
    @endif

    <div class="mt-12">
        <div class="font-semibold mb-0" style="margin-bottom:8px;">Latest entries (last {{ count($lines) }} lines)</div>

        <div style="background:#0b1220; border:1px solid rgba(255,255,255,.10); border-radius:10px; padding:12px; overflow:auto; max-height:520px; max-width:100%;">
            @if(empty($lines))
                <div style="opacity:.85; color:#e6edf3;">No entries.</div>
            @else
                <pre style="margin:0; white-space:pre-wrap; word-break:break-word; color:#e6edf3; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', 'Courier New', monospace;">@foreach($lines as $line){{ $line }}
@endforeach</pre>
            @endif
        </div>
    </div>

    <div class="mt-16 text-muted text-sm">
        This view shows non-fatal PHP errors captured by the application error handler (warnings/notices/deprecations). Fatal errors and exceptions may still appear in standard Laravel logs and server logs.
    </div>
</div>
@endsection
