@extends('layouts.app')
@section('breadcrumb', 'Marketplace / Shopee / Products / Push')

@section('title', 'Push to Shopee')

@section('content')
    <div class="page-header">
        <div>
            <h2>Push to Shopee</h2>
            <div class="text-muted text-sm">{{ $product->name }} (ID: {{ $product->product_id }})</div>
        </div>
        <div class="page-header-actions">
            <a class="btn" href="{{ route('ext.shopee.products.index') }}">Back to Products</a>
        </div>
    </div>

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

    <form method="POST" action="{{ route('ext.shopee.products.push.post', $product->product_id) }}">
        @csrf

        {{-- Product Info --}}
        <div class="card">
            <h3 class="section-title mt-0">Product Info</h3>

            <div>
                <label>Item Name <span style="color:#d11;">*</span></label>
                <input type="text" name="item_name" class="input" value="{{ old('item_name', $product->name) }}" maxlength="255">
            </div>

            <div style="margin-top:12px;">
                <label>Description <span style="color:#d11;">*</span></label>
                <textarea name="description" class="input" rows="6" maxlength="5000">{{ old('description', strip_tags($product->description ?? '')) }}</textarea>
            </div>

            <div style="display:grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px; margin-top:12px;">
                <div>
                    <label>Price <span style="color:#d11;">*</span></label>
                    <input type="number" name="original_price" class="input" step="0.01" min="0.01" value="{{ old('original_price', number_format($suggestedPrice ?? (float)$product->price, 2, '.', '')) }}">
                    @if(isset($suggestedPrice) && $suggestedPrice != (float)$product->price)
                        <div class="hint">Base: {{ number_format((float)$product->price, 2) }} + group markup</div>
                    @endif
                </div>
                <div>
                    <label>Stock <span style="color:#d11;">*</span></label>
                    <input type="number" name="normal_stock" class="input" min="0" value="{{ old('normal_stock', (int)$product->quantity) }}">
                </div>
                <div>
                    <label>SKU</label>
                    <input type="text" name="item_sku" class="input" value="{{ old('item_sku', $product->sku) }}" maxlength="100">
                </div>
            </div>
        </div>

        {{-- Dimensions --}}
        <div class="card mt-16">
            <h3 class="section-title mt-0">Dimensions</h3>
            <div style="display:grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: 12px;">
                <div>
                    <label>Weight (kg) <span style="color:#d11;">*</span></label>
                    <input type="number" name="weight" class="input" step="0.01" min="0.01" value="{{ old('weight', number_format((float)$product->weight, 2, '.', '')) }}">
                </div>
                <div>
                    <label>Length (cm) <span style="color:#d11;">*</span></label>
                    <input type="number" name="package_length" class="input" min="1" value="{{ old('package_length', max(1, (int)$product->length)) }}">
                </div>
                <div>
                    <label>Width (cm) <span style="color:#d11;">*</span></label>
                    <input type="number" name="package_width" class="input" min="1" value="{{ old('package_width', max(1, (int)$product->width)) }}">
                </div>
                <div>
                    <label>Height (cm) <span style="color:#d11;">*</span></label>
                    <input type="number" name="package_height" class="input" min="1" value="{{ old('package_height', max(1, (int)$product->height)) }}">
                </div>
            </div>
        </div>

        {{-- Shopee Category --}}
        <div class="card mt-16">
            <h3 class="section-title mt-0">Shopee Category</h3>
            @if(!empty($groupName) && !empty($groupCategoryId))
                <div class="hint" style="margin-bottom:8px;">Pre-selected from product group: <strong>{{ $groupName }}</strong></div>
            @endif
            <div>
                @php
                    $selectedCatId = (int)old('category_id', $groupCategoryId ?? 0);
                @endphp
                <label>Category <span style="color:#d11;">*</span></label>
                <select name="category_id" class="input">
                    <option value="">-- Select Shopee Category --</option>
                    @foreach($shopeeCategories as $c)
                        <option value="{{ (int)$c->category_id }}" {{ $selectedCatId === (int)$c->category_id ? 'selected' : '' }}>
                            {{ $c->name }} ({{ $c->category_id }})
                        </option>
                    @endforeach
                </select>
            </div>
        </div>

        {{-- Logistics --}}
        <div class="card mt-16">
            <h3 class="section-title mt-0">Logistics Channels</h3>
            @if(!empty($logistics))
                @php
                    $oldIds = old('logistic_ids');
                    $groupLids = array_map('intval', $groupLogisticIds ?? []);
                @endphp
                @if(!empty($groupName) && !empty($groupLids))
                    <div class="hint" style="margin-bottom:8px;">Pre-selected from product group: <strong>{{ $groupName }}</strong>. Select at least one. <span style="color:#d11;">*</span></div>
                @else
                    <div class="hint" style="margin-bottom:8px;">Select at least one logistics channel. <span style="color:#d11;">*</span></div>
                @endif
                <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 8px;">
                    @foreach($logistics as $ch)
                        @php
                            $lid = (int)($ch['logistic_id'] ?? 0);
                            $lname = (string)($ch['logistic_name'] ?? 'Channel #' . $lid);
                            $enabled = (bool)($ch['enabled'] ?? false);
                            // Priority: old input > product group logistics > API enabled flag
                            if (is_array($oldIds)) {
                                $isChecked = in_array($lid, array_map('intval', $oldIds));
                            } elseif (!empty($groupLids)) {
                                $isChecked = in_array($lid, $groupLids);
                            } else {
                                $isChecked = $enabled;
                            }
                        @endphp
                        <label style="display:flex; align-items:center; gap:8px; padding:6px 0;">
                            <input type="checkbox" name="logistic_ids[]" value="{{ $lid }}"
                                {{ $isChecked ? 'checked' : '' }}>
                            <span>{{ $lname }} <span class="text-muted text-xs">(ID: {{ $lid }})</span></span>
                        </label>
                    @endforeach
                </div>
            @else
                <div class="text-muted">No logistics channels cached. <a href="{{ route('ext.shopee.logistics.index') }}">Fetch them from the Logistics page</a> first.</div>
            @endif
        </div>

        {{-- Images --}}
        <div class="card mt-16">
            <h3 class="section-title mt-0">Images</h3>
            <div class="hint" style="margin-bottom:8px;">Select images to upload to Shopee. At least one image is required. <span style="color:#d11;">*</span></div>

            @if(!empty($images))
                <div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); gap: 12px;">
                    @foreach($images as $idx => $img)
                        @php
                            $oldSelected = old('selected_images', $images);
                            $isChecked = is_array($oldSelected) && in_array($img, $oldSelected);
                        @endphp
                        <label style="cursor:pointer; border:2px solid var(--border-light); border-radius:6px; padding:6px; text-align:center;">
                            <img src="{{ asset('storage/' . ltrim($img, '/')) }}" alt="" style="width:100%; height:100px; object-fit:cover; border-radius:4px;">
                            <div style="margin-top:6px;">
                                <input type="checkbox" name="selected_images[]" value="{{ $img }}" {{ $isChecked ? 'checked' : '' }}>
                            </div>
                            <div class="text-xs text-muted" style="word-break:break-all; margin-top:4px;">{{ basename($img) }}</div>
                        </label>
                    @endforeach
                </div>
            @else
                <div class="text-muted">No images found for this product.</div>
            @endif
        </div>

        {{-- Brand --}}
        <div class="card mt-16">
            <h3 class="section-title mt-0">Brand</h3>
            <div>
                <label>Brand</label>
                <input type="text" class="input" value="No Brand (brand_id: 0)" disabled>
                <div class="hint" style="margin-top:4px;">Brand is set to "No Brand" by default.</div>
            </div>
        </div>

        <div class="d-flex justify-end gap-8 mt-16" style="margin-bottom:24px;">
            <a class="btn" href="{{ route('ext.shopee.products.index') }}">Cancel</a>
            <button class="btn" type="submit">Push to Shopee</button>
        </div>
    </form>
@endsection
