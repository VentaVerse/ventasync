@extends('layouts.app')
@section('breadcrumb', 'Marketplace / Shopee / Categories / Attributes')

@section('title', 'Shopee Category Attributes')

@section('content')
    <div class="page-header">
        <div>
            <h2>Shopee Category Attributes</h2>
            <div class="text-muted text-sm">Category ID: <strong>{{ $category->category_id }}</strong> — {{ $category->name }}</div>
        </div>
        <div class="page-header-actions">
            <a class="btn" href="{{ route('ext.shopee.categories.index') }}">Back to Categories</a>

            <form method="POST" action="{{ route('ext.shopee.categories.attributes.fetch', $category->category_id) }}" style="display:inline;">
                @csrf
                <button class="btn" type="submit">Fetch Attributes from Shopee</button>
            </form>
        </div>
    </div>

    <div class="card mb-12">
        <div class="meta-bar">
            <div class="meta-item">
                <span class="meta-label">Region</span>
                {{ $region !== '' ? $region : '-' }}
            </div>
            <div class="meta-item">
                <span class="meta-label">Last fetched</span>
                {{ $template && $template->fetched_at ? $template->fetched_at : '-' }}
            </div>
            <div class="meta-item">
                <span class="meta-label">Attributes cached</span>
                {{ count($attributes) }}
            </div>
        </div>
    </div>

    <div class="card mb-12">
        <h3 class="section-title mt-0">Attribute List</h3>
        @if(empty($attributes))
            <div class="text-muted">No cached attributes for this category yet. Click <strong>Fetch Attributes from Shopee</strong>.</div>
        @else
            <div class="table-wrap">
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
                                    <span class="badge badge-red">MANDATORY</span>
                                @else
                                    <span class="badge badge-gray">Optional</span>
                                @endif
                            </td>
                            <td>
                                <div><strong>{{ $a['name'] }}</strong></div>
                                <div class="text-muted text-xs">Key: {{ $a['key'] }}</div>
                            </td>
                            <td>{{ $a['input_type'] }}</td>
                            <td>
                                @if(!empty($a['options']))
                                    <span class="text-muted">{{ count($a['options']) }} value(s)</span>
                                @else
                                    <span class="text-muted">-</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <div class="card mb-12">
        <h3 class="section-title mt-0">Raw Response (cached)</h3>
        @if($template && $template->attributes)
            <pre class="pre" style="white-space:pre-wrap; word-break:break-word;">{{ json_encode($template->attributes, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) }}</pre>
        @else
            <div class="text-muted">No cached response yet.</div>
        @endif
    </div>

    <div class="card">
        <h3 class="section-title mt-0">Recent Logs (get_attributes)</h3>
        @if($logs->isEmpty())
            <div class="text-muted">No logs yet.</div>
        @else
            <div class="table-wrap">
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
            </div>
        @endif
    </div>
@endsection
