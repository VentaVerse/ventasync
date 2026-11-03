@extends('layouts.channel')
@section('title', ($group->exists ? $group->name : 'New product group') . ', ' . $channelName)
@section('breadcrumb', $group->exists ? $group->name : 'New product group')

@section('own-errors', true)

@section('content')
<div class="fm-page">
    <form method="POST" id="pg-form" action="{{ $saveUrl }}" data-guard-unsaved>
        @csrf
        @if($group->exists)
            @method('PUT')
        @endif

        <div class="fm-flash" role="status" aria-live="polite">
            @if($errors->any())
                <div class="fm-note fm-note--fail">
                    <div class="fm-note__body">Nothing was saved. Check the fields marked below.</div>
                </div>
            @endif
        </div>

        <div class="fm-head">
            <div class="fm-head__main">
                <a class="fm-back" href="{{ $backUrl }}">
                    <x-ui.icon name="chevron-left" size="14" /> Product Groups
                </a>
                <h1 class="fm-title">{{ $group->exists ? $group->name : 'New product group' }}</h1>
                @if($storeName !== '')
                    <div class="fm-stamps">
                        <span class="fm-stamp"><span class="fm-stamp__k">Store</span><span class="fm-stamp__v">{{ $storeName }}</span></span>
                    </div>
                @endif
            </div>
        </div>

        <div class="fm-body">
            <div class="fm-col fm-col--main">
                <section class="fm-section">
                    <div class="fm-fields">
                        <x-ui.field label="Product group name" for="pg-name" name="name" :required="true" wide>
                            <x-ui.input id="pg-name" name="name" value="{{ old('name', $group->name) }}" maxlength="128"
                                        placeholder="Guitar pedals" :disabled="! $canManage" />
                        </x-ui.field>
                    </div>
                </section>
            </div>
        </div>

        <x-ui.form-bar :cancel="$backUrl" :cancel-label="$canManage ? 'Cancel' : 'Back'">
            <x-slot:primary>
                @if($canManage)
                    <x-ui.button type="submit" variant="primary">{{ $group->exists ? 'Save product group' : 'Create product group' }}</x-ui.button>
                @endif
            </x-slot:primary>
        </x-ui.form-bar>
    </form>

    @if($group->exists)
        <section class="fm-section pg-members">
            <div class="fm-section__head pg-members__head">
                <h2 class="fm-section__title">Products in this group <span class="x-num">{{ number_format($members->count()) }}</span></h2>
                @if($canManage)
                    <x-ui.button type="button" variant="primary" data-add-panel-open>
                        <x-ui.icon name="plus" size="14" /> Add products
                    </x-ui.button>
                @endif
            </div>
            @if($members->isEmpty())
                <x-ui.empty title="No products in this group yet" />
            @else
                <x-ui.table>
                    <x-slot:head>
                        <tr>
                            <th scope="col">Product</th>
                            <th scope="col">SKU</th>
                            <th scope="col" class="x-td-num">Stock</th>
                            <th scope="col" class="x-td-actions"><span class="x-sr">Actions</span></th>
                        </tr>
                    </x-slot:head>
                    @foreach($members as $member)
                        <tr>
                            <td data-label="Product">
                                <div class="pg-member">
                                    <span class="pg-member__thumb">@if($member->thumb)<img src="{{ $member->thumb }}" alt="" loading="lazy">@endif</span>
                                    @if($member->url)
                                        <a class="x-row-link" href="{{ $member->url }}">{{ $member->name }}</a>
                                    @else
                                        {{ $member->name }}
                                    @endif
                                </div>
                            </td>
                            <td data-label="SKU"><span class="x-num">{{ $member->sku !== '' ? $member->sku : 'None' }}</span></td>
                            <td class="x-td-num" data-label="Stock"><span class="x-num">{{ number_format($member->quantity) }}</span></td>
                            <td class="x-td-actions">
                                @if($canManage)
                                    <form method="POST" action="{{ $removeUrl }}"
                                          data-confirm="Take &quot;{{ $member->name }}&quot; out of {{ $group->name }}? It stays on this store with its listing as it is." data-confirm-verb="Remove">
                                        @csrf
                                        <input type="hidden" name="ids[]" value="{{ $member->id }}">
                                        <x-ui.button type="submit" size="sm" variant="danger">Remove</x-ui.button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </x-ui.table>
            @endif
        </section>

        @if($canManage)
            <x-channel.add-panel
                id="pg-add-panel"
                :search-url="$searchUrl"
                :add-url="$addUrl"
                :fixed-group="$group->id"
                sub="Search your Master Catalog. Each product you add joins this group, and this store if it is not on it yet."
                toggle-label="Show products already in this group"
                in-label="In this group"
                scope="this group"
                empty-default="Every enabled product is already in this group."
                empty-all="Your Master Catalog has no enabled products."
                list-label="Master Catalog products"
            />
        @endif
    @endif
</div>
@endsection
