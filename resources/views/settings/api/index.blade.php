@extends('layouts.blotter')
@section('title', 'API applications')
@section('breadcrumb', 'API applications')

@section('content')
@php
    // Reading needs view_api_clients|manage_api_clients; every write needs
    // manage_api_clients. See routes/settings.php.
    $canManage = auth()->user()?->hasPermission('manage_api_clients') ?? false;

    // The plaintext token exists for exactly one request, flashed by
    // ApiClientController after create or rotate. It is never stored in
    // readable form and never rendered on any other page, so this is the
    // only place in the product where it can appear at all.
    $newToken = session('new_token');
@endphp

<div class="x-list-head">
    <div>
        <h1 class="x-page-title">API applications</h1>
        <p class="x-page-sub">
            Other software that talks to this ERP. Each one gets a token that only opens the
            resources and actions granted to it.
        </p>
    </div>
    @if($canManage)
        <x-ui.button variant="primary" :href="route('api_clients.create')">New application</x-ui.button>
    @endif
</div>

@if($newToken)
    {{-- Shown once and never again. Not a modal: a modal that cannot be
         reopened hides the one thing this page exists to hand over. --}}
    <div class="st-token">
        <p class="st-token__title">Token for {{ session('new_token_client') }}</p>
        <p class="st-token__note">
            Copy it now and store it wherever that application keeps its credentials. This is the
            only time it can be read. If it is lost, rotate the application to issue a new one.
        </p>
        <div class="st-token__row">
            {{-- No inline handler: resources/js/pages/settings.js selects the
                 field on focus and drives the copy button. --}}
            <input class="x-input st-token__value" id="st-api-token" type="text" readonly
                   data-select-on-focus value="{{ $newToken }}" aria-label="New API token">
            <x-ui.button type="button" data-copy-target="st-api-token">Copy</x-ui.button>
        </div>
    </div>
@endif

@include('partials.flash')

@if($clients->isEmpty())
    <x-ui.empty
        title="No API applications yet"
        description="Add one for each piece of software that needs to read or write through the API, and give it only the access that piece of software actually needs.">
        @if($canManage)
            <x-slot:action>
                <x-ui.button variant="primary" :href="route('api_clients.create')">New application</x-ui.button>
            </x-slot:action>
        @endif
    </x-ui.empty>
@else
<x-ui.table>
    <x-slot:head>
        <tr>
            <th scope="col">Name</th>
            <th scope="col" class="st-col-tiers x-td-num">Permissions</th>
            <th scope="col" class="st-col-when">Expires</th>
            <th scope="col" class="st-col-when">Created</th>
            <th scope="col" class="st-col-when">Last used</th>
            <th scope="col" class="x-td-actions"><span class="x-sr">Actions</span></th>
        </tr>
    </x-slot:head>

    @foreach($clients as $row)
        @php
            $scopeCount = is_array($row->scopes) ? count($row->scopes) : 0;
            $isExpired = $row->expires_at && $row->expires_at->isPast();
        @endphp
        <tr>
            <td data-label="Name">
                @if($canManage)
                    <a class="x-row-link" href="{{ route('api_clients.edit', $row->model->id) }}">{{ $row->model->name }}</a>
                @else
                    {{ $row->model->name }}
                @endif
            </td>
            <td class="st-col-tiers x-td-num" data-label="Permissions">
                <span class="x-num">{{ $scopeCount }}</span>
            </td>
            <td class="st-col-when" data-label="Expires">
                @if($row->expires_at)
                    @if($isExpired)
                        <x-ui.badge tone="danger">Expired {{ $row->expires_at->format('Y-m-d') }}</x-ui.badge>
                    @else
                        <span class="x-num">{{ $row->expires_at->format('Y-m-d') }}</span>
                    @endif
                @else
                    <span class="st-note">No expiry</span>
                @endif
            </td>
            <td class="st-col-when" data-label="Created">
                <span class="x-num">{{ $row->model->created_at?->format('Y-m-d') }}</span>
            </td>
            <td class="st-col-when" data-label="Last used">
                @if($row->last_used)
                    <span class="x-num">{{ $row->last_used->format('Y-m-d H:i') }}</span>
                @else
                    <span class="st-note">Never</span>
                @endif
            </td>
            <td class="x-td-actions">
                @if($canManage)
                <x-ui.menu label="{{ $row->model->name }} actions">
                    <a class="x-menu__item" href="{{ route('api_clients.edit', $row->model->id) }}">Edit</a>
                    <button type="button" class="x-menu__item"
                            data-confirm="Rotate the token for {{ $row->model->name }}? The current token stops working the moment the new one is issued."
                            data-confirm-submit="st-api-rot-{{ $row->model->id }}">Rotate token</button>
                    <div class="x-menu__sep"></div>
                    <button type="button" class="x-menu__item x-menu__item--danger"
                            data-confirm="Revoke {{ $row->model->name }}? Its token stops working immediately. This cannot be undone."
                            data-confirm-submit="st-api-del-{{ $row->model->id }}">Revoke</button>
                </x-ui.menu>
                @endif
            </td>
        </tr>
    @endforeach
</x-ui.table>

@if($canManage)
    @foreach($clients as $row)
        <form id="st-api-rot-{{ $row->model->id }}" method="POST" action="{{ route('api_clients.rotate', $row->model->id) }}" class="x-sr">
            @csrf
        </form>
        <form id="st-api-del-{{ $row->model->id }}" method="POST" action="{{ route('api_clients.destroy', $row->model->id) }}" class="x-sr">
            @csrf
            @method('DELETE')
        </form>
    @endforeach
@endif
@endif
@endsection
