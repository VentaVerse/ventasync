@extends('layouts.app')

@section('content')
<div class="card">
    <div class="page-header">
        <h2>Edit Product #{{ $product->product_id }}</h2>
        <div style="display:flex; gap:10px;">
            <a class="btn secondary" href="{{ route('products.index') }}">Back</a>
</div>
    </div>

    @if ($errors->any())
        <div class="alert error" style="margin-top:12px;">{{ $errors->first() }}</div>
    @endif


    <form method="POST" action="{{ route('products.update', $product->product_id) }}">
        @csrf
        @method('PUT')

        @include('products.partials.image_manager', ['pimToken' => '', 'pimProductId' => (int) $product->product_id])

        <h3 style="margin-top:14px; margin-bottom:8px;">General</h3>
        <div style="height:1px; background:rgba(255,255,255,0.08); margin-bottom:14px;"></div>

        <div class="tab-pane" style="margin-top:14px;">
            <div class="form-grid">
            <div class="full">
                <label class="required">Name</label>
                <input class="input" name="name" value="{{ old('name', html_entity_decode($product->name ?? '')) }}" required>
                @error('name')
                    <div class="hint" style="color:#ff6b6b; margin-top:4px;">{{ $message }}</div>
                @enderror
            </div>

            <div>
                <label class="required">Model</label>
                <input class="input" name="model" value="{{ old('model', $product->model) }}" required>
                @error('model')
                    <div class="hint" style="color:#ff6b6b; margin-top:4px;">{{ $message }}</div>
                @enderror
            </div>

            <div>
                <label>SKU</label>
                <input class="input" name="sku" value="{{ old('sku', $product->sku) }}">
            </div>

            <div>
                <label>Price</label>
                <input class="input" name="price" value="{{ old('price', $product->price) }}" required>
            </div>

            <div>
                <label>Quantity</label>
                <input class="input" name="quantity" value="{{ old('quantity', $product->quantity) }}" required>
            </div>

            <div>
                <label>Weight</label>
                <input class="input" name="weight" value="{{ old('weight', $product->weight ?? '0') }}" placeholder="e.g. 0.5">
                @error('weight')
                    <div class="hint" style="color:#ff6b6b; margin-top:4px;">{{ $message }}</div>
                @enderror
            </div>

            <div>
                <label>Length</label>
                <input class="input" name="length" value="{{ old('length', $product->length ?? '0') }}" placeholder="e.g. 10">
                @error('length')
                    <div class="hint" style="color:#ff6b6b; margin-top:4px;">{{ $message }}</div>
                @enderror
            </div>

            <div>
                <label>Width</label>
                <input class="input" name="width" value="{{ old('width', $product->width ?? '0') }}" placeholder="e.g. 5">
                @error('width')
                    <div class="hint" style="color:#ff6b6b; margin-top:4px;">{{ $message }}</div>
                @enderror
            </div>

            <div>
                <label>Height</label>
                <input class="input" name="height" value="{{ old('height', $product->height ?? '0') }}" placeholder="e.g. 3">
                @error('height')
                    <div class="hint" style="color:#ff6b6b; margin-top:4px;">{{ $message }}</div>
                @enderror
            </div>

            <div class="typeahead">
                <label>Manufacturer</label>
                <input class="input" id="manufacturer_search" placeholder="Type to search..." autocomplete="off" value="{{ old('manufacturer_name', $manufacturerName ?? '') }}" name="manufacturer_name">
                <input type="hidden" name="manufacturer_id" id="manufacturer_id" value="{{ old('manufacturer_id', $product->manufacturer_id) }}">
                <div class="typeahead-list" id="manufacturer_list"></div>
                <div class="hint">Start typing, then click a result.</div>
            </div>

            <div class="typeahead">
                <label>Category</label>
                <input class="input" id="category_search" placeholder="Type to search..." autocomplete="off" value="{{ old('category_name', $categoryName ?? '') }}" name="category_name">
                <input type="hidden" name="category_id" id="category_id" value="{{ old('category_id', $currentCategoryId ?? 0) }}">
                <div class="typeahead-list" id="category_list"></div>
                <div class="hint">Start typing, then click a result.</div>
            </div>

            <div>
                <label class="required">Status</label>
                <select class="input" name="status" required>
                    <option value="1" {{ old('status', (string)$product->status)=='1'?'selected':'' }}>Enabled</option>
                    <option value="0" {{ old('status', (string)$product->status)=='0'?'selected':'' }}>Disabled</option>
                </select>
            </div>

            <div class="full">
                <label>Description</label>
                <textarea class="input wysiwyg" name="description">{!! old('description', $product->description) !!}</textarea>
            </div>
        </div>
        </div>
        <h3 style="margin-top:22px; margin-bottom:8px;">Options</h3>
        <div style="height:1px; background:rgba(255,255,255,0.08); margin-bottom:14px;"></div>

        <div class="tab-pane" style="margin-top:14px;">
            @include('products.partials.options_fields')
        </div>

        <div style="margin-top:16px; display:flex; gap:10px; align-items:center; justify-content:flex-end;">
            <button class="btn" type="submit">Update</button>
            <button class="btn danger" type="button" onclick="if(confirm('Delete this item?')) document.getElementById('delete-form').submit();">Delete</button>
        </div>
    </form>

    <form id="delete-form" method="POST" action="{{ route('products.destroy', $product->product_id) }}" style="display:none;">
    @csrf
    @method('DELETE')
</form>

</div>

@endsection
