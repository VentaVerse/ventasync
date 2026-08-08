@extends('layouts.channel')
@section('title', 'TikTok Shop Product Groups')
@section('breadcrumb', 'Product Groups')

{{-- Leaves the .x-legacy content boundary, rebuilt on the token set. --}}
@section('content-class', '')

@section('content')
@php
    // Read tier is `view_tiktok|manage_tiktok`; create, update and destroy are
    // all `manage_tiktok`. Replaces a hardcoded `true`.
    //
    // The edit GET is registered in the READ group, so a view-tier operator
    // can technically open the form. The link is still gated here, the way
    // Lazada's Product Groups page gates its own: that form exists only to be
    // saved, and the PUT behind it is manage-only, so offering it to an
    // operator who cannot submit it is offering a dead end.
    $canManageTiktok = auth()->user()?->hasPermission('manage_tiktok') ?? false;
@endphp

<div class="cc-page">

<div class="x-list-head">
    <div>
        <h1 class="x-page-title">Product Groups</h1>
        <p class="x-page-sub">
            {{ number_format($groups->count()) }} {{ $groups->count() === 1 ? 'group' : 'groups' }}. A group maps a set of catalogue products to one TikTok Shop category and its price markup.
        </p>
    </div>
    @if($canManageTiktok)
        <x-ui.button variant="primary" :href="route('ext.tiktok.product-groups.create')">New group</x-ui.button>
    @endif
</div>

@if($groups->count() === 0)
    <x-ui.empty title="No product groups yet"
                description="A group is how a batch of catalogue products gets its TikTok Shop category and price markup in one place, instead of setting them per listing.">
        @if($canManageTiktok)
        <x-slot:action>
            <x-ui.button variant="primary" :href="route('ext.tiktok.product-groups.create')">New group</x-ui.button>
        </x-slot:action>
        @endif
    </x-ui.empty>
@else
<x-ui.table>
    <x-slot:head>
        <tr>
            <th class="cc-col-id">ID</th>
            <th>Group</th>
            <th class="cc-col-count x-td-num">Products</th>
            <th>TikTok category</th>
            <th class="cc-col-markup">Markup</th>
            <th class="x-td-actions"><span class="x-sr">Actions</span></th>
        </tr>
    </x-slot:head>

    @foreach($groups as $g)
        @php
            $pCount = $productCounts[$g->id] ?? 0;
            $productsUrl = route('ext.tiktok.product-groups.products', $g->id);

            // The same two lists the old view joined into one "Cat: ... | Mfg: ..."
            // string. They are what decides which catalogue products fall into
            // the group, so they read as a sentence under the group's own name
            // rather than as a fourth column of comma-joined ids.
            $catNames = collect($g->catalog_category_ids ?? [])->map(fn ($id) => $categoryNames->get($id))->filter()->values();
            $mfgNames = collect($g->manufacturer_ids ?? [])->map(fn ($id) => $manufacturerNames->get($id))->filter()->values();

            $filterParts = [];
            if ($catNames->isNotEmpty()) {
                $filterParts[] = 'Categories: ' . $catNames->implode(', ');
            }
            if ($mfgNames->isNotEmpty()) {
                $filterParts[] = 'Manufacturers: ' . $mfgNames->implode(', ');
            }

            // Two figures in different units, so two lines rather than one
            // string that reads as a sum.
            $markupFixed = !empty($g->markup_fixed) ? (float) $g->markup_fixed : null;
            $markupPercent = !empty($g->markup_percent) ? (float) $g->markup_percent : null;
        @endphp
        <tr>
            <td class="cc-col-id" data-label="ID"><span class="x-num">{{ $g->id }}</span></td>
            <td data-label="Group">
                <a class="x-row-link" href="{{ $productsUrl }}">{{ $g->name }}</a>
                @if(!empty($filterParts))
                    <span class="cc-sub">{{ implode('. ', $filterParts) }}</span>
                @else
                    <span class="cc-sub">Products are added by hand, not by a filter</span>
                @endif
            </td>
            <td class="cc-col-count x-td-num" data-label="Products"><span class="x-num">{{ number_format($pCount) }}</span></td>
            <td data-label="TikTok category">
                @if($g->tiktok_category_id)
                    <span class="x-cell-strong">{{ $ttCategoryNames->get($g->tiktok_category_id, $g->tiktok_category_id) }}</span>
                    <span class="cc-sub cc-sub--mono">{{ $g->tiktok_category_id }}</span>
                @else
                    <span class="x-cell-muted">Not set</span>
                @endif
            </td>
            <td class="cc-col-markup" data-label="Markup">
                @if($markupFixed !== null || $markupPercent !== null)
                    @if($markupFixed !== null)
                        <x-money :php="$markupFixed" />
                    @endif
                    @if($markupPercent !== null)
                        <span class="cc-markup__pct">{{ $markupFixed !== null ? 'plus ' : '' }}{{ rtrim(rtrim(number_format($markupPercent, 2), '0'), '.') }}% of price</span>
                    @endif
                @else
                    <span class="x-cell-muted">None</span>
                @endif
            </td>
            <td class="x-td-actions">
                {{-- The old row carried three standing buttons, one of them a
                     solid red Delete, on every line. The row itself is the link
                     to its products now, and the rest sit in the overflow. --}}
                <x-ui.menu label="Group {{ $g->name }} actions">
                    <a class="x-menu__item" href="{{ $productsUrl }}">Products in this group</a>
                    @if($canManageTiktok)
                        <a class="x-menu__item" href="{{ route('ext.tiktok.product-groups.edit', $g->id) }}">Edit group</a>
                        <div class="x-menu__sep"></div>
                        {{-- Destroy is never a standing control: it sits in the
                             overflow behind the shared confirm dialog and drives
                             a form outside this table, because a form cannot be
                             nested inside a row that is itself a stretched
                             link. --}}
                        <button type="button" class="x-menu__item x-menu__item--danger"
                                data-confirm="Delete the group {{ $g->name }}? Its listing rows are removed with it. Nothing changes in the catalogue, and nothing already live on TikTok Shop is touched."
                                data-confirm-submit="ttpg-del-{{ $g->id }}">Delete group</button>
                    @endif
                </x-ui.menu>
            </td>
        </tr>
    @endforeach
</x-ui.table>
@endif

@if($canManageTiktok)
    @foreach($groups as $g)
        <form id="ttpg-del-{{ $g->id }}" method="POST" action="{{ route('ext.tiktok.product-groups.destroy', $g->id) }}" class="x-sr">
            @csrf
            @method('DELETE')
        </form>
    @endforeach
@endif

</div>{{-- /.cc-page --}}
@endsection
