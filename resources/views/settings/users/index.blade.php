@extends('layouts.blotter')
@section('title', 'Users')
@section('breadcrumb', 'Users')

@section('content')
@php
    $canManageUsers = auth()->user()?->hasPermission('manage_settings/user') ?? false;

    $hasFilters = ($q ?? '') !== '';
    $currentUserId = auth()->id();
    [, $maxUsers] = \App\Plans\Quota::standing('users');
    $planFull = \App\Plans\Quota::refusal('users') !== null;
@endphp

<div class="x-list-head">
    <div>
        @include('partials.back-to-settings')
        <h1 class="x-page-title">Users</h1>
        <p class="x-page-sub">
            @if($maxUsers !== null)
                {{ number_format($users->total()) }} of {{ number_format($maxUsers) }} users on your plan
            @else
                {{ number_format($users->total()) }} {{ Str::plural('person', $users->total()) }} who can sign in
            @endif
        </p>
    </div>
    @if($canManageUsers)
        @if($planFull)
            <x-ui.button variant="primary" disabled>New user</x-ui.button>
        @else
            <x-ui.button variant="primary" :href="route('users.create')">New user</x-ui.button>
        @endif
    @endif
</div>

@include('partials.flash')

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
            <th scope="col" class="st-col-id">ID</th>
            <th scope="col">Name</th>
            <th scope="col" class="st-col-user">Username</th>
            <th scope="col">Email</th>
            <th scope="col" class="st-col-user">Group</th>
            <th scope="col" class="st-col-when">Last sign-in</th>
            <th scope="col" class="bl-col-actions"><span class="x-sr">Actions</span></th>
        </tr>
    </x-slot:head>

    @foreach($users as $u)
        @php
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
                @if(\App\Support\Auth\AccountLock::isLocked($u))
                    <x-ui.badge tone="danger" :dot="false">Locked until {{ $u->locked_until->format('H:i') }}</x-ui.badge>
                @elseif($u->last_login_at)
                    <span class="x-num">{{ $u->last_login_at->format('Y-m-d H:i') }}</span>
                @else
                    <span class="st-note">Never</span>
                @endif
            </td>
            <td class="bl-col-actions">
                @if($canManageUsers)
                <div class="bl-rowactions">
                    <a class="x-btn x-btn--sm" href="{{ route('users.edit', $u->id) }}">Edit</a>
                    @if(\App\Support\Auth\AccountLock::isLocked($u))
                        <button type="submit" class="x-btn x-btn--sm" form="st-user-unlock-{{ $u->id }}">Unlock</button>
                    @endif
                    @if(Route::has('ext.audit.activity-log.index'))
                        <a class="x-btn x-btn--sm" href="{{ route('ext.audit.activity-log.index', ['user_id' => $u->id]) }}">Activity log</a>
                    @endif
                    @if($canDelete)
                        <button type="button" class="x-btn x-btn--sm x-btn--danger"
                                data-confirm="Delete {{ $u->name }}? This cannot be undone."
                                data-confirm-submit="st-user-del-{{ $u->id }}">Delete</button>
                    @endif
                </div>
                @endif
            </td>
        </tr>
    @endforeach
</x-ui.table>

@if($canManageUsers)
    @foreach($users as $u)
        @if(\App\Support\Auth\AccountLock::isLocked($u))
            <form id="st-user-unlock-{{ $u->id }}" method="POST" action="{{ route('users.unlock', $u->id) }}" class="x-sr">@csrf</form>
        @endif
        @if(($u->userGroup->name ?? '') !== 'Administrator' && (int) auth()->id() !== (int) $u->id)
            <form id="st-user-del-{{ $u->id }}" method="POST" action="{{ route('users.destroy', $u->id) }}" class="x-sr">
                @csrf
                @method('DELETE')
            </form>
        @endif
    @endforeach
@endif

<x-ui.pager :paginator="$users" />
@endif
@endsection
