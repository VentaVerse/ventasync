@extends('layouts.app')
@section('breadcrumb', 'Marketplace / ' . $setting->store_name . ' / Profiles / ' . ($mode === 'create' ? 'Add' : 'Edit'))

@section('content')
    <div class="page-header">
        <div>
            <h2>{{ $mode === 'create' ? 'Add Profile' : 'Edit Profile' }}</h2>
            <div class="text-muted text-sm">{{ $setting->store_name }} — filter products by category/manufacturer.</div>
        </div>
        <div class="page-header-actions">
            <a class="btn" href="{{ route('opencart.profiles.index', $setting->id) }}">Back to Profiles</a>
        </div>
    </div>

    @if(session('status'))
        <div class="alert success">{{ session('status') }}</div>
    @endif

    @if($errors->any())
        <div class="alert danger">
            <strong>Fix the following:</strong>
            <ul style="margin: 6px 0 0 18px;">
                @foreach($errors->all() as $e)
                    <li>{{ $e }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="card">
        <form method="POST" action="{{ $mode === 'create' ? route('opencart.profiles.store', $setting->id) : route('opencart.profiles.update', [$setting->id, $profile->id]) }}">
            @csrf
            @if($mode === 'edit')
                @method('PUT')
            @endif

            <div>
                <label>Profile Name <span style="color:#d11;">*</span></label>
                <input type="text" name="name" class="input" value="{{ old('name', $profile->name) }}" placeholder="e.g. Guitar Pedals" required>
            </div>

            <h3 class="section-title" style="margin-top:20px;">Product Filter</h3>
            <div class="hint" style="margin-bottom:8px;">Select categories and/or manufacturers to match products. You can also add individual products manually from the Products tab.</div>

            @php
                $selectedCatIds = old('catalog_category_ids', $profile->catalog_category_ids ?? []) ?? [];
                $selectedMfgIds = old('manufacturer_ids', $profile->manufacturer_ids ?? []) ?? [];
                $selectedCatIds = array_map('intval', (array)$selectedCatIds);
                $selectedMfgIds = array_map('intval', (array)$selectedMfgIds);
            @endphp

            <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                <div>
                    <label>Categories</label>
                    <select name="catalog_category_ids[]" class="input" multiple size="12" style="height:auto; min-height:200px;">
                        @foreach($catalogCategories as $c)
                            <option value="{{ $c->category_id }}" {{ in_array((int)$c->category_id, $selectedCatIds) ? 'selected' : '' }}>
                                {{ $c->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label>Manufacturers</label>
                    <select name="manufacturer_ids[]" class="input" multiple size="12" style="height:auto; min-height:200px;">
                        @foreach(($manufacturers ?? collect()) as $m)
                            <option value="{{ $m->manufacturer_id }}" {{ in_array((int)$m->manufacturer_id, $selectedMfgIds) ? 'selected' : '' }}>
                                {{ $m->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="hint" style="margin-top:6px;">
                Matching products: <strong>{{ $productCount }}</strong>
                @if($mode === 'edit')
                    &nbsp;—&nbsp;<a href="{{ route('opencart.profiles.products', [$setting->id, $profile->id]) }}">View Products</a>
                @endif
            </div>

            <div class="d-flex justify-end gap-8 mt-16">
                <button class="btn" type="submit">
                    {{ $mode === 'create' ? 'Create Profile' : 'Save Profile' }}
                </button>
            </div>
        </form>
    </div>
@endsection
