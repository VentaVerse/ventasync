@extends('layouts.blotter')
@section('title', 'Watermarks')

@section('content')
@php
    $canManage = auth()->user()?->hasPermission('manage_catalog/watermark_template') ?? false;
@endphp

<div class="x-list-head">
    <div>
        <h1 class="x-page-title">Watermarks</h1>
        <p class="x-page-sub">{{ number_format($templates->total()) }} {{ Str::plural('template', $templates->total()) }}</p>
    </div>
    @if($canManage)
        <x-ui.button variant="primary" :href="route('watermarks.create')">New template</x-ui.button>
    @endif
</div>

<form method="GET" action="{{ route('watermarks.index') }}" class="x-filters">
    <x-ui.input type="search" name="q" value="{{ $q }}" placeholder="Search templates" class="x-filters__search" />
</form>

@if($templates->count() === 0)
    {{-- An empty state answers, it does not assign homework (2026-09-09). --}}
    <x-ui.empty title="{{ $q !== '' ? 'Nothing matches that' : 'No watermark templates yet' }}">
        @if($canManage)
            <x-slot:action>
                <x-ui.button variant="primary" :href="$q !== '' ? route('watermarks.index') : route('watermarks.create')">
                    {{ $q !== '' ? 'Show all' : 'New template' }}
                </x-ui.button>
            </x-slot:action>
        @endif
    </x-ui.empty>
@else
    <x-ui.table caption="Watermark templates, and what each one puts on a picture">
        <thead>
            <tr>
                <th scope="col">Mark</th>
                <th scope="col">Name</th>
                <th scope="col">Where</th>
                <th scope="col" class="x-td-num">Size</th>
                <th scope="col"></th>
            </tr>
        </thead>
        <tbody>
            @foreach($templates as $t)
                <tr>
                    <td data-label="Mark">
                        {{-- On a chequer, or a white logo on white looks like nothing at all. --}}
                        <span class="wm-chip"><img src="{{ $t->markUrl() }}" alt="" loading="lazy"></span>
                    </td>
                    <td data-label="Name">
                        @if($canManage)
                            <a href="{{ route('watermarks.edit', $t->id) }}">{{ $t->name }}</a>
                        @else
                            {{ $t->name }}
                        @endif
                    </td>
                    <td data-label="Where">{{ ucfirst(str_replace('-', ' ', (string) $t->position)) }}</td>
                    <td data-label="Size" class="x-td-num">{{ rtrim(rtrim(number_format((float) $t->size_percent, 2), '0'), '.') }}%</td>
                    <td class="x-td-actions">
                        @if($canManage)
                            <form method="POST" action="{{ route('watermarks.destroy', $t->id) }}"
                                  data-confirm="Delete the template &quot;{{ $t->name }}&quot;? Any listing or product group using it goes up unmarked from then on."
                                  data-confirm-verb="Delete" data-confirm-tone="danger">
                                @csrf
                                @method('DELETE')
                                <x-ui.button type="submit" size="sm" variant="danger">Delete</x-ui.button>
                            </form>
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </x-ui.table>

    {{ $templates->links() }}
@endif
@endsection
