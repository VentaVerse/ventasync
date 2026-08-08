@extends('layouts.shell')
@section('title', 'Users')
@section('breadcrumb', 'Users')

@section('content')
@php
    // routes/settings.php gates the list on view_users|manage_users and every
    // write on manage_users specifically, so the manage tier is a real,
    // reachable state here. The pre-redesign view hardcoded
    // $canManageUsers = true, which offered Edit and Delete to a read-tier
    // operator and let the route bounce them afterwards.
    $canManageUsers = auth()->user()?->hasPermission('manage_users') ?? false;

    $hasFilters = ($q ?? '') !== '';
    $currentUserId = auth()->id();
@endphp

<div class="x-list-head">
    <div>
        <h1 class="x-page-title">Users</h1>
        <p class="x-page-sub">{{ number_format($users->total()) }} {{ Str::plural('person', $users->total()) }} who can sign in</p>
    </div>
    @if($canManageUsers)
        <x-ui.button variant="primary" :href="route('users.create')">New user</x-ui.button>
    @endif
</div>

<form method="GET" action="{{ route('users.index') }}" class="x-filters">
    <label class="x-sr" for="st-users-q">Search users</label>
    <x-ui.input type="search" id="st-users-q" name="q" value="{{ $q ?? '' }}"
                placeholder="Search name, username or email" class="x-filters__search" />
</form>

@if($users->isEmpty())
    <x-ui.empty
        title="{{ $hasFilters ? 'No users match that search' : 'No users yet' }}"
        description="{{ $hasFilters ? 'Try a different name, username or email address.' : 'Add the people who need to sign in, and put each one in the group that matches what they do.' }}">
        @if($canManageUsers && !$hasFilters)
            <x-slot:action>
                <x-ui.button variant="primary" :href="route('users.create')">New user</x-ui.button>
            </x-slot:action>
        @endif
    </x-ui.empty>
@else
<x-ui.table>
    <x-slot:head>
        <tr>
            <th class="st-col-id">ID</th>
            <th>Name</th>
            <th class="st-col-user">Username</th>
            <th>Email</th>
            <th class="st-col-user">Group</th>
            <th class="st-col-when">Last sign-in</th>
            <th class="x-td-actions"><span class="x-sr">Actions</span></th>
        </tr>
    </x-slot:head>

    @foreach($users as $u)
        @php
            // UserController@destroy refuses both of these, so the menu never
            // offers a delete the server would bounce back with a flash.
            $isAdminAccount = ($u->userGroup->name ?? '') === 'Administrator';
            $isSelf = $currentUserId !== null && (int) $currentUserId === (int) $u->id;
            $canDelete = $canManageUsers && !$isAdminAccount && !$isSelf;
        @endphp
        <tr>
            <td class="st-col-id" data-label="ID"><span class="x-num">{{ $u->id }}</span></td>
            <td data-label="Name">
                @if($canManageUsers)
                    <a class="x-row-link" href="{{ route('users.edit', $u->id) }}">{{ $u->name }}</a>
                @else
                    {{ $u->name }}
                @endif
            </td>
            <td class="st-col-user" data-label="Username"><span class="x-num">{{ $u->username }}</span></td>
            <td data-label="Email">{{ $u->email }}</td>
            <td class="st-col-user" data-label="Group">
                @if($u->userGroup)
                    <x-ui.badge :tone="$isAdminAccount ? 'info' : 'neutral'" :dot="false">{{ $u->userGroup->name }}</x-ui.badge>
                @else
                    <span class="st-note">No group</span>
                @endif
            </td>
            <td class="st-col-when" data-label="Last sign-in">
                @if($u->last_login_at)
                    <span class="x-num">{{ $u->last_login_at->format('Y-m-d H:i') }}</span>
                @else
                    <span class="st-note">Never</span>
                @endif
            </td>
            <td class="x-td-actions">
                @if($canManageUsers)
                <x-ui.menu label="{{ $u->name }} actions">
                    <a class="x-menu__item" href="{{ route('users.edit', $u->id) }}">Edit</a>
                    @if(Route::has('ext.audit.activity-log.index'))
                        <a class="x-menu__item" href="{{ route('ext.audit.activity-log.index', ['user_id' => $u->id]) }}">Activity log</a>
                    @endif
                    @if($canDelete)
                        <div class="x-menu__sep"></div>
                        <button type="button" class="x-menu__item x-menu__item--danger"
                                data-confirm="Delete {{ $u->name }}? This cannot be undone."
                                data-confirm-submit="st-user-del-{{ $u->id }}">Delete</button>
                    @endif
                </x-ui.menu>
                @endif
            </td>
        </tr>
    @endforeach
</x-ui.table>

{{-- Delete forms live outside the table. A <form> inside a <td> inside the
     menu panel is legal, but hoisting them keeps every row's markup the same
     shape whether or not that row may be deleted. --}}
@if($canManageUsers)
    @foreach($users as $u)
        @if(($u->userGroup->name ?? '') !== 'Administrator' && (int) auth()->id() !== (int) $u->id)
            <form id="st-user-del-{{ $u->id }}" method="POST" action="{{ route('users.destroy', $u->id) }}" class="x-sr">
                @csrf
                @method('DELETE')
            </form>
        @endif
    @endforeach
@endif

<div class="x-pager">
    <span class="x-pager__count x-num">{{ number_format($users->firstItem() ?? 0) }} to {{ number_format($users->lastItem() ?? 0) }} of {{ number_format($users->total()) }}</span>
    <div class="x-pager__nav">
        <x-ui.button size="sm" :href="$users->previousPageUrl()" :disabled="!$users->previousPageUrl()"><x-ui.icon name="chevron-left" size="14" /> Previous</x-ui.button>
        <x-ui.button size="sm" :href="$users->nextPageUrl()" :disabled="!$users->nextPageUrl()">Next <x-ui.icon name="chevron-right" size="14" /></x-ui.button>
    </div>
</div>
@endif
@endsection
