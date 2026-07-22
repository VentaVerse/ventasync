@extends('layouts.app')
@section('breadcrumb', 'Settings / API')

@php
    $canManage = auth()->user()?->hasPermission('manage_api_clients');
@endphp

@section('content')

@if(session('new_token'))
    <div class="card">
        <div class="page-header">
            <h2>Token for &ldquo;{{ session('new_token_client') }}&rdquo;</h2>
        </div>
        <p class="text-secondary text-xs">Copy this now — it is shown once and cannot be retrieved again.</p>
        <div class="toolbar">
            <input class="input" type="text" readonly value="{{ session('new_token') }}" onclick="this.select()">
            <button class="btn" type="button"
                    onclick="navigator.clipboard.writeText('{{ session('new_token') }}'); this.textContent='Copied';">Copy</button>
        </div>
    </div>
@endif

<div class="card">
    <div class="page-header">
        <h2>API Applications</h2>
    </div>
    <p class="text-secondary text-xs">
        Third-party applications with scoped, token-based access to this ERP. Each
        token only permits the resources and actions granted below, and every
        request is recorded in the audit log.
    </p>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th style="width:60px;">ID</th>
                    <th>Name</th>
                    <th>Scopes</th>
                    <th style="width:150px;">Expires</th>
                    <th style="width:150px;">Last used</th>
                    @if($canManage)<th style="width:200px;">Actions</th>@endif
                </tr>
            </thead>
            <tbody>
                @forelse($clients as $row)
                    <tr>
                        <td>{{ $row->model->id }}</td>
                        <td>
                            {{ $row->model->name }}
                            @if($row->model->description)
                                <div class="text-xs text-secondary">{{ $row->model->description }}</div>
                            @endif
                        </td>
                        <td>
                            @forelse($row->scopes as $scope)
                                <span class="badge">{{ $scope }}</span>
                            @empty
                                <span class="text-xs text-secondary">—</span>
                            @endforelse
                        </td>
                        <td class="text-xs">
                            @if($row->expires_at)
                                @if($row->expires_at->isPast())
                                    <span class="badge badge-gray">expired {{ $row->expires_at->format('Y-m-d') }}</span>
                                @else
                                    {{ $row->expires_at->format('Y-m-d') }}
                                @endif
                            @else
                                <span class="text-secondary">No expiry</span>
                            @endif
                        </td>
                        <td class="text-xs text-secondary">
                            {{ $row->last_used ? $row->last_used->diffForHumans() : 'never' }}
                        </td>
                        @if($canManage)
                        <td>
                            <form method="POST" action="{{ route('api_clients.rotate', $row->model->id) }}"
                                  data-confirm="Rotate this token? The current token stops working immediately.">
                                @csrf
                                <button class="btn small" type="submit">Rotate</button>
                            </form>
                            <form method="POST" action="{{ route('api_clients.destroy', $row->model->id) }}"
                                  data-confirm="Revoke this application and delete its token?">
                                @csrf @method('DELETE')
                                <button class="btn danger small" type="submit">Revoke</button>
                            </form>
                        </td>
                        @endif
                    </tr>
                @empty
                    <tr><td colspan="{{ $canManage ? 6 : 5 }}" class="text-secondary">No API applications yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

@if($canManage)
<div class="card">
    <div class="page-header">
        <h2>Create API Application</h2>
    </div>

    <form method="POST" action="{{ route('api_clients.store') }}">
        @csrf

        <div class="form-grid">
            <div>
                <label class="required">Name</label>
                <input class="input" name="name" value="{{ old('name') }}" required maxlength="255"
                       placeholder="e.g. Pricing Assistant">
                @error('name')<div class="field-error">{{ $message }}</div>@enderror
            </div>
            <div>
                <label>Description</label>
                <input class="input" name="description" value="{{ old('description') }}" maxlength="255"
                       placeholder="What this application is for">
            </div>

            <div class="full">
                <label class="required">Permissions</label>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr><th>Resource</th><th style="width:90px;">Read</th><th style="width:90px;">Write</th></tr>
                        </thead>
                        <tbody>
                            @foreach($scopes as $key => $meta)
                                <tr>
                                    <td>
                                        {{ $meta['label'] }}
                                        <div class="text-xs text-secondary">{{ $meta['description'] }}</div>
                                    </td>
                                    <td>
                                        @if(in_array('read', $meta['actions'], true))
                                            <input type="checkbox" name="scopes[{{ $key }}][read]" value="1">
                                        @else
                                            <span class="text-secondary">—</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if(in_array('write', $meta['actions'], true))
                                            <input type="checkbox" name="scopes[{{ $key }}][write]" value="1">
                                        @else
                                            <span class="text-secondary">—</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @error('scopes')<div class="field-error">{{ $message }}</div>@enderror
            </div>

            <div class="full">
                <label>Expiry</label>
                <label class="text-xs">
                    <input type="checkbox" name="no_expiry" value="1" checked> No expiry
                </label>
                <div class="text-xs text-secondary">Or set an expiry date (uncheck &ldquo;No expiry&rdquo; to use it):</div>
                <input class="input" type="date" name="expires_at" value="{{ old('expires_at') }}">
                @error('expires_at')<div class="field-error">{{ $message }}</div>@enderror
            </div>
        </div>

        <div class="page-header">
            <button class="btn" type="submit">Create &amp; issue token</button>
        </div>
    </form>
</div>
@endif

<div class="card">
    <div class="page-header">
        <h2>Recent API Activity</h2>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th style="width:170px;">Time</th>
                    <th>Application</th>
                    <th style="width:70px;">Method</th>
                    <th>Path</th>
                    <th>Scope</th>
                    <th style="width:70px;">Status</th>
                    <th style="width:130px;">IP</th>
                </tr>
            </thead>
            <tbody>
                @forelse($recentLogs as $log)
                    <tr>
                        <td class="text-xs">{{ $log->created_at?->format('Y-m-d H:i:s') }}</td>
                        <td class="text-xs">{{ $log->client?->name ?? '—' }}</td>
                        <td class="text-xs">{{ $log->method }}</td>
                        <td class="text-xs">{{ $log->path }}</td>
                        <td class="text-xs">{{ $log->scope ?? '—' }}</td>
                        <td class="text-xs">
                            @if($log->status >= 400)
                                <span class="badge badge-gray">{{ $log->status }}</span>
                            @else
                                {{ $log->status }}
                            @endif
                        </td>
                        <td class="text-xs text-secondary">{{ $log->ip }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-secondary">No API activity recorded yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

@endsection
