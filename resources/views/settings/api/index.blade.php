@extends('layouts.app')
@section('breadcrumb', 'Settings / API')

@php
    $canManage = auth()->user()?->hasPermission('manage_api_clients');
@endphp

@section('content')

<div class="card">
    <div class="page-header">
        <h2>API Applications</h2>
        @if($canManage)
            <a class="btn" href="{{ route('api_clients.create') }}">Add Application</a>
        @endif
    </div>
    <p class="text-secondary text-xs">
        Third-party applications with scoped, token-based access to this ERP. Each
        token only permits the resources and actions granted to it.
    </p>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th style="width:60px;">ID</th>
                    <th>Name</th>
                    <th style="width:150px;">Expires</th>
                    <th style="width:150px;">Created</th>
                    <th style="width:180px;">Last used</th>
                    @if($canManage)<th style="width:260px;">Actions</th>@endif
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
                        <td class="text-xs">
                            {{ $row->model->created_at?->format('Y-m-d') }}
                        </td>
                        <td class="text-xs">
                            @if($row->last_used)
                                {{ $row->last_used->format('Y-m-d H:i') }}
                                <span class="text-secondary">({{ $row->last_used->diffForHumans() }})</span>
                            @else
                                <span class="text-secondary">never</span>
                            @endif
                        </td>
                        @if($canManage)
                        <td>
                            <div class="d-flex gap-6 flex-wrap">
                                <a class="btn small secondary" href="{{ route('api_clients.edit', $row->model->id) }}">Edit</a>
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
                            </div>
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

{{-- One-time token reveal — auto-opens after create / rotate --}}
<div id="apiTokenModal" class="modal-backdrop {{ session('new_token') ? 'active' : '' }}">
    <div class="modal" style="max-width:620px;">
        <div class="modal-header">
            <h3>API token for &ldquo;{{ session('new_token_client') }}&rdquo;</h3>
            <button type="button" class="modal-close"
                    onclick="document.getElementById('apiTokenModal').classList.remove('active')">&times;</button>
        </div>
        <div style="padding:1rem;">
            <p class="text-secondary text-xs">Copy this now — it is shown once and cannot be retrieved again.</p>
            <div class="toolbar">
                <input class="input" id="apiTokenValue" type="text" readonly value="{{ session('new_token') }}" onclick="this.select()">
                <button class="btn" type="button"
                        onclick="navigator.clipboard.writeText(document.getElementById('apiTokenValue').value); this.textContent='Copied';">Copy</button>
            </div>
            <div class="page-header" style="margin-top:1rem;">
                <button type="button" class="btn secondary"
                        onclick="document.getElementById('apiTokenModal').classList.remove('active')">Done</button>
            </div>
        </div>
    </div>
</div>

@endsection
