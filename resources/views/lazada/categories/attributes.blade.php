@extends('layouts.app')

@section('title', 'Lazada Category Attributes')

@section('content')
    <div class="page-head">
        <div>
            <h1>Lazada Category Attributes</h1>
            <div class="muted">Category ID: <strong>{{ $category->category_id }}</strong> — {{ $category->name }}</div>
        </div>
        <div class="page-actions" style="display:flex; gap:8px; align-items:center;">
            <a class="btn" href="{{ route('lazada.categories.index') }}">Back to Categories</a>

            <form method="POST" action="{{ route('lazada.categories.attributes.fetch', $category->category_id) }}" style="display:inline;">
                @csrf
                <button class="btn" type="submit">Fetch Attributes from Lazada</button>
            </form>
        </div>
    </div>

    <div class="card" style="margin-bottom:12px;">
        <div class="card-body">
            <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap;">
                <div>
                    <div class="muted" style="font-size:12px;">Region</div>
                    <div>{{ $region !== '' ? $region : '-' }}</div>
                </div>
                <div>
                    <div class="muted" style="font-size:12px;">Last fetched</div>
                    <div>{{ $template && $template->fetched_at ? $template->fetched_at : '-' }}</div>
                </div>
                <div>
                    <div class="muted" style="font-size:12px;">Attributes cached</div>
                    <div>{{ count($attributes) }}</div>
                </div>
            </div>
        </div>
    </div>

    <div class="card" style="margin-bottom:12px;">
        <div class="card-body">
            <h3 style="margin-top:0;">Attribute List</h3>
            @if(empty($attributes))
                <div class="muted">No cached attributes for this category yet. Click <strong>Fetch Attributes from Lazada</strong>.</div>
            @else
                <table class="table">
                    <thead>
                    <tr>
                        <th style="width:160px;">Mandatory</th>
                        <th>Attribute</th>
                        <th style="width:160px;">Input Type</th>
                        <th style="width:160px;">Options</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($attributes as $a)
                        <tr>
                            <td>
                                @if($a['required'])
                                    <span style="display:inline-block; padding:3px 8px; border-radius:999px; background:#ffe8e8; color:#b30000; font-weight:700; font-size:12px;">MANDATORY</span>
                                @else
                                    <span style="display:inline-block; padding:3px 8px; border-radius:999px; background:#f3f4f6; color:#444; font-weight:600; font-size:12px;">Optional</span>
                                @endif
                            </td>
                            <td>
                                <div><strong>{{ $a['name'] }}</strong></div>
                                <div class="muted" style="font-size:12px;">Key: {{ $a['key'] }}</div>
                            </td>
                            <td>{{ $a['input_type'] }}</td>
                            <td>
                                @if(!empty($a['options']))
                                    <span class="muted">{{ count($a['options']) }} value(s)</span>
                                @else
                                    <span class="muted">-</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>

    <div class="card" style="margin-bottom:12px;">
        <div class="card-body">
            <h3 style="margin-top:0;">Raw Response (cached)</h3>
            @if($template && $template->template_body)
                <pre style="white-space:pre-wrap; word-break:break-word; margin:0;">{{ json_encode($template->template_body, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) }}</pre>
            @else
                <div class="muted">No cached response yet.</div>
            @endif
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <h3 style="margin-top:0;">Recent Logs (GetCategoryAttributes)</h3>
            @if($logs->isEmpty())
                <div class="muted">No logs yet.</div>
            @else
                <table class="table">
                    <thead>
                    <tr>
                        <th style="width:180px;">Date</th>
                        <th style="width:90px;">OK</th>
                        <th style="width:120px;">Status</th>
                        <th>Params</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($logs as $log)
                        <tr>
                            <td>{{ $log->created_at }}</td>
                            <td>{{ $log->ok ? 'Yes' : 'No' }}</td>
                            <td>{{ $log->response_status ?? '-' }}</td>
                            <td>
                                <pre style="white-space:pre-wrap; word-break:break-word; margin:0;">{{ json_encode($log->request_params, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) }}</pre>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>
@endsection
