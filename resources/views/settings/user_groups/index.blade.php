@extends('layouts.blotter')
@section('title', 'User groups')
@section('breadcrumb', 'User groups')

@section('content')
@php
    $canManageUserGroups = auth()->user()?->hasPermission('manage_settings/user_group') ?? false;

    $hasFilters = ($q ?? '') !== '';
@endphp

<div class="x-list-head">
    <div>
        @include('partials.back-to-settings')
        <h1 class="x-page-title">User groups</h1>
        <p class="x-page-sub">{{ number_format($groups->total()) }} {{ Str::plural('group', $groups->total()) }}. A group decides what everyone in it may open and change.</p>
    </div>
    @if($canManageUserGroups)
        <x-ui.button variant="primary" :href="route('user_groups.create')">New group</x-ui.button>
    @endif
</div>

@include('partials.flash')

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
            <th scope="col" class="st-col-id">ID</th>
            <th scope="col">Name</th>
            <th scope="col" class="bl-col-actions"><span class="x-sr">Actions</span></th>
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
            <td class="bl-col-actions">
                @if($canManageUserGroups)
                    <div class="bl-rowactions">
                        <a class="x-btn x-btn--sm" href="{{ route('user_groups.edit', $g->id) }}">Edit permissions</a>
                        <button type="button" class="x-btn x-btn--sm"
                                data-confirm="Create a copy of {{ $g->name }} with the same permissions? You land on the copy to adjust it."
                                data-confirm-tone="primary"
                                data-confirm-submit="st-group-dup-{{ $g->id }}">Duplicate</button>
                        @unless($isAdminGroup)
                            <button type="button" class="x-btn x-btn--sm x-btn--danger"
                                    data-confirm="Delete {{ $g->name }}? This cannot be undone."
                                    data-confirm-submit="st-group-del-{{ $g->id }}">Delete</button>
                        @endunless
                    </div>
                @endif
            </td>
        </tr>
    @endforeach
</x-ui.table>

@if($canManageUserGroups)
    @foreach($groups as $g)
        <form id="st-group-dup-{{ $g->id }}" method="POST" action="{{ route('user_groups.duplicate', $g->id) }}" class="x-sr">
            @csrf
        </form>
        @if(strtolower($g->name) !== 'administrator')
            <form id="st-group-del-{{ $g->id }}" method="POST" action="{{ route('user_groups.destroy', $g->id) }}" class="x-sr">
                @csrf
                @method('DELETE')
            </form>
        @endif
    @endforeach
@endif

<x-ui.pager :paginator="$groups" />
@endif
@endsection
