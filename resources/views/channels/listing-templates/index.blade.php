@extends('layouts.channel')
@section('title', $channelName . ' Listing Templates')
@section('breadcrumb', 'Listing Templates')

@section('content')
<div class="x-list-head">
    <div>
        <h1 class="x-page-title">Listing Templates</h1>
        <p class="x-page-sub">{{ number_format($templates->count()) }} {{ Str::plural('template', $templates->count()) }}</p>
    </div>
    @if($canManage)
        <x-ui.button variant="primary" :href="$createUrl">New listing template</x-ui.button>
    @endif
</div>

@if($templates->isEmpty())
    <x-ui.empty title="No listing templates yet">
        @if($canManage)
            <x-slot:action>
                <x-ui.button variant="primary" :href="$createUrl">New listing template</x-ui.button>
            </x-slot:action>
        @endif
    </x-ui.empty>
@else
    <x-ui.table>
        <x-slot:head>
            <tr>
                <th scope="col">Name</th>
                <th scope="col">Price rule</th>
                <th scope="col">Used by</th>
                <th scope="col" class="x-td-actions"><span class="x-sr">Actions</span></th>
            </tr>
        </x-slot:head>
        @foreach($templates as $t)
            <tr>
                <td data-label="Name"><a href="{{ $t->edit_url }}">{{ $t->name }}</a></td>
                <td data-label="Price rule"><span class="x-num">{{ $t->price_rule }}</span></td>
                <td data-label="Used by">{{ $t->used_by !== '' ? $t->used_by : 'Not used' }}</td>
                <td class="x-td-actions">
                    <x-ui.button size="sm" :href="$t->edit_url">{{ $canManage ? 'Edit' : 'View' }}</x-ui.button>
                    @if($canManage)
                        <form method="POST" action="{{ $t->destroy_url }}"
                              data-confirm="Delete the listing template &quot;{{ $t->name }}&quot;? Listings it was applied to keep their values." data-confirm-verb="Delete">
                            @csrf @method('DELETE')
                            <x-ui.button type="submit" size="sm" :disabled="$t->used_by !== ''">Delete</x-ui.button>
                        </form>
                    @endif
                </td>
            </tr>
        @endforeach
    </x-ui.table>
@endif
@endsection
