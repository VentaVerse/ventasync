@extends('layouts.app')
@section('breadcrumb', 'Marketplace / ' . $setting->store_name . ' / Profiles')

@section('content')
    <div class="page-header">
        <div>
            <h2>{{ $setting->store_name }} — Profiles</h2>
            <div class="text-muted text-sm">Group products by category/manufacturer, then push to OpenCart.</div>
        </div>
        <div class="page-header-actions">
            <a class="btn" href="{{ route('opencart.profiles.create', $setting->id) }}">Add Profile</a>
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
                    <th style="width:100px;">Products</th>
                    <th style="width:220px;">Actions</th>
                </tr>
                </thead>
                <tbody>
                @forelse($profiles as $p)
                    @php
                        $catNames = collect($p->catalog_category_ids ?? [])->map(fn($id) => $categoryNames->get($id))->filter()->values();
                        $mfgNames = collect($p->manufacturer_ids ?? [])->map(fn($id) => $manufacturerNames->get($id))->filter()->values();
                        $filterParts = [];
                        if ($catNames->isNotEmpty()) $filterParts[] = 'Cat: ' . $catNames->implode(', ');
                        if ($mfgNames->isNotEmpty()) $filterParts[] = 'Mfg: ' . $mfgNames->implode(', ');
                        $filterLabel = !empty($filterParts) ? implode(' | ', $filterParts) : 'No filter (manual only)';
                    @endphp
                    <tr>
                        <td>{{ $p->id }}</td>
                        <td class="font-bold">{{ $p->name }}</td>
                        <td>
                            <span class="{{ empty($filterParts) ? 'text-muted' : '' }}">{{ $filterLabel }}</span>
                        </td>
                        <td>{{ $productCounts[$p->id] ?? 0 }}</td>
                        <td>
                            <div class="d-flex gap-6 items-center text-nowrap">
                                <a class="btn small" href="{{ route('opencart.profiles.products', [$setting->id, $p->id]) }}">Products</a>
                                <a class="btn small" href="{{ route('opencart.profiles.edit', [$setting->id, $p->id]) }}">Edit</a>
                                <form method="POST" action="{{ route('opencart.profiles.destroy', [$setting->id, $p->id]) }}" style="margin:0;" data-confirm="Delete this profile?">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn small danger">Delete</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="text-muted">No profiles yet. Click <strong>Add Profile</strong> to create one.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
