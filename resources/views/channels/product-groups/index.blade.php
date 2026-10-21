@extends('layouts.channel')
@section('title', 'Product Groups, ' . $channelName)
@section('breadcrumb', 'Product Groups')

@section('content')
<div class="cc-page">
<div class="x-list-head">
    <div>
        <h1 class="x-page-title">Product Groups</h1>
        <p class="x-page-sub">{{ number_format($groups->count()) }} {{ $groups->count() === 1 ? 'product group' : 'product groups' }}@if($storeName !== '') on {{ $storeName }}@endif</p>
    </div>
    @if($canManage)
        <x-ui.button variant="primary" :href="$createUrl">New product group</x-ui.button>
    @endif
</div>
@if($groups->isEmpty())
    <x-ui.empty title="No product groups yet">
        @if($canManage)
            <x-slot:action>
                <x-ui.button variant="primary" :href="$createUrl">New product group</x-ui.button>
            </x-slot:action>
        @endif
    </x-ui.empty>
@else
    <x-ui.table>
        <x-slot:head>
            <tr>
                <th scope="col">Product group</th>
                <th scope="col">Listing template</th>
                <th scope="col" class="cc-col-count x-td-num">Products</th>
                <th scope="col" class="x-td-actions"><span class="x-sr">Actions</span></th>
            </tr>
        </x-slot:head>
        @foreach($groups as $g)
            <tr>
                <td data-label="Product group"><a class="x-row-link" href="{{ $g->editUrl }}">{{ $g->name }}</a></td>
                <td data-label="Listing template">@if($g->template !== ''){{ $g->template }}@else<span class="x-cell-muted">None</span>@endif</td>
                <td class="cc-col-count x-td-num" data-label="Products"><span class="x-num">{{ number_format($g->count) }}</span></td>
                <td class="x-td-actions">
                    @if($canManage)
                        <div class="pg-actions">
                            <x-ui.button size="sm" :href="$g->editUrl">Edit</x-ui.button>
                            <form method="POST" action="{{ $g->destroyUrl }}"
                                  data-confirm="Delete the product group &quot;{{ $g->name }}&quot;? Its products stay on this store and keep their listings." data-confirm-verb="Delete">
                                @csrf
                                @method('DELETE')
                                <x-ui.button type="submit" size="sm" variant="danger">Delete</x-ui.button>
                            </form>
                        </div>
                    @endif
                </td>
            </tr>
        @endforeach
    </x-ui.table>
@endif
</div>
@endsection
