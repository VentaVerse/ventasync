@extends('layouts.shell')
@section('title', 'User groups')
@section('breadcrumb', 'User groups')

@section('content')
@php
    // routes/settings.php gates the list on view_user_groups|manage_user_groups
    // and every write on manage_user_groups. The pre-redesign view hardcoded
    // this to true and offered Edit and Delete to the read tier.
    $canManageUserGroups = auth()->user()?->hasPermission('manage_user_groups') ?? false;

    $hasFilters = ($q ?? '') !== '';
@endphp

<div class="x-list-head">
    <div>
        <h1 class="x-page-title">User groups</h1>
        <p class="x-page-sub">{{ number_format($groups->total()) }} {{ Str::plural('group', $groups->total()) }}. A group decides what everyone in it may open and change.</p>
    </div>
    @if($canManageUserGroups)
        <x-ui.button variant="primary" :href="route('user_groups.create')">New group</x-ui.button>
    @endif
</div>

@if(session('error'))
    <div class="fm-flash" role="status" aria-live="polite">
        <div class="fm-note fm-note--fail"><div class="fm-note__body">{{ session('error') }}</div></div>
    </div>
@endif

<form method="GET" action="{{ route('user_groups.index') }}" class="x-filters">
    <label class="x-sr" for="st-groups-q">Search groups</label>
    <x-ui.input type="search" id="st-groups-q" name="q" value="{{ $q ?? '' }}"
                placeholder="Search groups" class="x-filters__search" />
</form>

@if($groups->isEmpty())
    <x-ui.empty
        title="{{ $hasFilters ? 'No groups match that search' : 'No user groups yet' }}"
        description="{{ $hasFilters ? 'Try a different name.' : 'Create a group for each kind of job, then set what that job is allowed to do.' }}">
        @if($canManageUserGroups && !$hasFilters)
            <x-slot:action>
                <x-ui.button variant="primary" :href="route('user_groups.create')">New group</x-ui.button>
            </x-slot:action>
        @endif
    </x-ui.empty>
@else
<x-ui.table>
    <x-slot:head>
        <tr>
            <th class="st-col-id">ID</th>
            <th>Name</th>
            <th class="x-td-actions"><span class="x-sr">Actions</span></th>
        </tr>
    </x-slot:head>

    @foreach($groups as $g)
        @php $isAdminGroup = strtolower($g->name) === 'administrator'; @endphp
        <tr>
            <td class="st-col-id" data-label="ID"><span class="x-num">{{ $g->id }}</span></td>
            <td data-label="Name">
                @if($canManageUserGroups)
                    <a class="x-row-link" href="{{ route('user_groups.edit', $g->id) }}">{{ $g->name }}</a>
                @else
                    {{ $g->name }}
                @endif
                @if($isAdminGroup)
                    <div class="st-note">Always keeps settings, users and user groups. Cannot be deleted.</div>
                @endif
            </td>
            <td class="x-td-actions">
                @if($canManageUserGroups)
                <x-ui.menu label="{{ $g->name }} actions">
                    <a class="x-menu__item" href="{{ route('user_groups.edit', $g->id) }}">Edit permissions</a>
                    @unless($isAdminGroup)
                        <div class="x-menu__sep"></div>
                        <button type="button" class="x-menu__item x-menu__item--danger"
                                data-confirm="Delete {{ $g->name }}? This cannot be undone."
                                data-confirm-submit="st-group-del-{{ $g->id }}">Delete</button>
                    @endunless
                </x-ui.menu>
                @endif
            </td>
        </tr>
    @endforeach
</x-ui.table>

@if($canManageUserGroups)
    @foreach($groups as $g)
        @if(strtolower($g->name) !== 'administrator')
            <form id="st-group-del-{{ $g->id }}" method="POST" action="{{ route('user_groups.destroy', $g->id) }}" class="x-sr">
                @csrf
                @method('DELETE')
            </form>
        @endif
    @endforeach
@endif

<div class="x-pager">
    <span class="x-pager__count x-num">{{ number_format($groups->firstItem() ?? 0) }} to {{ number_format($groups->lastItem() ?? 0) }} of {{ number_format($groups->total()) }}</span>
    <div class="x-pager__nav">
        <x-ui.button size="sm" :href="$groups->previousPageUrl()" :disabled="!$groups->previousPageUrl()"><x-ui.icon name="chevron-left" size="14" /> Previous</x-ui.button>
        <x-ui.button size="sm" :href="$groups->nextPageUrl()" :disabled="!$groups->nextPageUrl()">Next <x-ui.icon name="chevron-right" size="14" /></x-ui.button>
    </div>
</div>
@endif
@endsection
