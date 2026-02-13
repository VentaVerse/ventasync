@extends('layouts.app')

@section('content')
<div class="card">
    <div class="page-header">
        <h2>Error Log</h2>

        <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
            <form method="POST" action="{{ route('error_log.clear') }}" style="margin:0;">
                @csrf
                <button type="submit" class="btn danger" onclick="return confirm('Clear error.log? This cannot be undone.');">Clear error.log</button>
            </form>

            <form method="POST" action="{{ route('error_log.test') }}" style="margin:0;">
                @csrf
                <button type="submit" class="btn">Generate Test Warning</button>
            </form>
        </div>
    </div>

    <div class="toolbar" style="display:flex; align-items:center; justify-content:space-between; gap:12px;; flex-wrap:wrap;">
        <div style="min-width:0;">
            <div style="font-weight:600;">File:</div>
            <div style="font-family:monospace; word-break:break-all; overflow-wrap:anywhere;">{{ $logPath }}</div>
        </div>

        <div style="text-align:right; min-width:120px;">
            <div style="font-weight:600;">Size:</div>
            <div>{{ $sizeHuman }} ({{ number_format($sizeBytes) }} bytes)</div>
            <div style="margin-top:6px; font-size:12px;">
                <span style="font-weight:600;">Writable:</span>
                @if($writable)
                    <span style="color:#2ecc71;">Yes</span>
                @else
                    <span style="color:#e74c3c;">No</span>
                @endif
            </div>
        </div>
    </div>

    @if(!$logExists)
        <div class="alert danger" style="margin-top:12px;">
            error.log not found. It will be created automatically when the first PHP error is logged.
        </div>
    @endif

    <div style="margin-top:12px;">
        <div style="font-weight:600; margin-bottom:8px;">Latest entries (last {{ count($lines) }} lines)</div>

        <div style="background:#0b1220; border:1px solid rgba(255,255,255,.10); border-radius:10px; padding:12px; overflow:auto; max-height:520px; max-width:100%;">
            @if(empty($lines))
                <div style="opacity:.85; color:#e6edf3;">No entries.</div>
            @else
                <pre style="margin:0; white-space:pre-wrap; word-break:break-word; color:#e6edf3; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', 'Courier New', monospace;">@foreach($lines as $line){{ $line }}
@endforeach</pre>
            @endif
        </div>
    </div>

    <div style="margin-top:14px; opacity:.75; font-size:13px;">
        This view shows non-fatal PHP errors captured by the application error handler (warnings/notices/deprecations). Fatal errors and exceptions may still appear in standard Laravel logs and server logs.
    </div>
</div>
@endsection
