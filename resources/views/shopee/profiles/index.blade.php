@extends('layouts.app')
@section('breadcrumb', 'Marketplace / Shopee / Profiles')

@section('title', 'Shopee Profiles')

@section('content')
    <div class="page-header">
        <div>
            <h2>Shopee Profiles</h2>
            <div class="text-muted text-sm">Group products and mass-map to Shopee category &amp; attributes.</div>
        </div>
        <div class="page-header-actions">
            <a class="btn" href="{{ route('shopee.profiles.create') }}">Add Profile</a>
        </div>
    </div>

    @if(session('status'))
        <div class="alert success">{{ session('status') }}</div>
    @endif

    <div class="card">
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th style="width:50px;">ID</th>
                    <th>Profile</th>
                    <th>Filter</th>
                    <th>Shopee Category</th>
                    <th>Logistics</th>
                    <th style="width:100px;">Markup</th>
                    <th style="width:180px;">Actions</th>
                </tr>
                </thead>
                <tbody>
                @forelse($profiles as $p)
                    @php
                        $catNames = collect($p->catalog_category_ids ?? [])->map(fn($id) => $categoryNames->get($id))->filter()->values();
                        $mfgNames = collect($p->manufacturer_ids ?? [])->map(fn($id) => $manufacturerNames->get($id))->filter()->values();
                        $filterParts = [];
                        if ($catNames->isNotEmpty()) $filterParts[] = $catNames->implode(', ');
                        if ($mfgNames->isNotEmpty()) $filterParts[] = $mfgNames->implode(', ');
                        $filterLabel = !empty($filterParts) ? implode(' | ', $filterParts) : 'All Products';
                    @endphp
                    <tr>
                        <td>{{ $p->id }}</td>
                        <td class="font-bold">{{ $p->name }}</td>
                        <td>
                            <span class="{{ empty($filterParts) ? 'text-muted' : '' }}">{{ $filterLabel }}</span>
                        </td>
                        <td>
                            @if($p->shopee_category_id)
                                {{ $shopeeCategoryNames->get($p->shopee_category_id, $p->shopee_category_id) }}
                            @else
                                <span class="text-muted">Not set</span>
                            @endif
                        </td>
                        <td>
                            @php $logCount = count($p->logistic_ids ?? []); @endphp
                            @if($logCount > 0)
                                <span class="badge badge-green">{{ $logCount }} channel{{ $logCount > 1 ? 's' : '' }}</span>
                            @else
                                <span class="text-muted">Not set</span>
                            @endif
                        </td>
                        <td>
                            @php
                                $parts = [];
                                if (!empty($p->markup_fixed)) $parts[] = '+' . number_format((float)$p->markup_fixed, 2);
                                if (!empty($p->markup_percent)) $parts[] = '+' . rtrim(rtrim(number_format((float)$p->markup_percent, 2), '0'), '.') . '%';
                            @endphp
                            @if(!empty($parts))
                                {{ implode(' & ', $parts) }}
                            @else
                                <span class="text-muted">-</span>
                            @endif
                        </td>
                        <td>
                            <div class="d-flex gap-6 items-center text-nowrap">
                                <a class="btn small" href="{{ route('shopee.profiles.edit', $p->id) }}">Edit</a>

                                <form method="POST" action="{{ route('shopee.profiles.destroy', $p->id) }}" style="margin:0;" onsubmit="return confirm('Delete this profile?');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn small danger">Delete</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="text-muted">No profiles yet. Click <strong>Add Profile</strong> to create one.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
