@extends('layouts.app')
@section('breadcrumb', 'Catalog / Products / Edit')

@section('content')
<div class="card">
    <div class="page-header">
        <h2>Edit Product #{{ $product->product_id }}</h2>
        <div class="page-header-actions">
            <a class="btn secondary" href="{{ $returnUrl ?? route('products.index') }}">Back</a>
        </div>
    </div>

    @if ($errors->any())
        <div class="alert danger">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route('products.update', $product->product_id) }}">
        @csrf
        @method('PUT')
        <input type="hidden" name="_return" value="{{ $returnUrl ?? route('products.index') }}">

        <div class="typeahead mt-16">
            <label>Manufacturer</label>
            <input class="input" id="manufacturer_search" placeholder="Type to search..." autocomplete="off" value="{{ old('manufacturer_name', $manufacturerName ?? '') }}" name="manufacturer_name">
            <input type="hidden" name="manufacturer_id" id="manufacturer_id" value="{{ old('manufacturer_id', $product->manufacturer_id) }}">
            <div class="typeahead-list" id="manufacturer_list"></div>
            <div class="hint">Start typing, then click a result. Required before uploading images.</div>
        </div>

        @include('products.partials.image_manager', ['pimToken' => '', 'pimProductId' => (int) $product->product_id])

        <h3 class="section-title">General</h3>

        <div class="form-grid">
            <div class="full">
                <label class="required">Name</label>
                <input class="input" name="name" value="{{ old('name', html_entity_decode($product->name ?? '')) }}" required>
                @error('name')
                    <div class="field-error">{{ $message }}</div>
                @enderror
            </div>

            <div>
                <label class="required">Model</label>
                <input class="input" name="model" value="{{ old('model', $product->model) }}" required>
                @error('model')
                    <div class="field-error">{{ $message }}</div>
                @enderror
            </div>

            <div>
                <label class="required">SKU</label>
                <input class="input" name="sku" value="{{ old('sku', $product->sku) }}" required>
                @error('sku')
                    <div class="field-error">{{ $message }}</div>
                @enderror
            </div>

            <div>
                <label>Price</label>
                <input class="input" name="price" value="{{ old('price', $product->price) }}" required>
            </div>

            <div>
                <label>Quantity</label>
                <input class="input" id="product_quantity" name="quantity" value="{{ old('quantity', $product->quantity) }}" required>
                <div class="hint" id="qty_hint" style="display:none;">Quantity is auto-calculated from option quantities.</div>
            </div>

            <div>
                <label>Weight</label>
                <input class="input" name="weight" value="{{ old('weight', $product->weight ?? '0') }}" placeholder="e.g. 0.5">
                @error('weight')
                    <div class="field-error">{{ $message }}</div>
                @enderror
            </div>

            <div>
                <label>Length</label>
                <input class="input" name="length" value="{{ old('length', $product->length ?? '0') }}" placeholder="e.g. 10">
                @error('length')
                    <div class="field-error">{{ $message }}</div>
                @enderror
            </div>

            <div>
                <label>Width</label>
                <input class="input" name="width" value="{{ old('width', $product->width ?? '0') }}" placeholder="e.g. 5">
                @error('width')
                    <div class="field-error">{{ $message }}</div>
                @enderror
            </div>

            <div>
                <label>Height</label>
                <input class="input" name="height" value="{{ old('height', $product->height ?? '0') }}" placeholder="e.g. 3">
                @error('height')
                    <div class="field-error">{{ $message }}</div>
                @enderror
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

        <h3 class="section-title">Options</h3>

        <div>
            @include('products.partials.options_fields')
        </div>

        <div class="d-flex gap-10 items-center justify-end mt-20">
            <button class="btn" type="submit">Update</button>
            <button class="btn danger" type="button" onclick="if(confirm('Delete this item?')) document.getElementById('delete-form').submit();">Delete</button>
        </div>
    </form>

    <form id="delete-form" method="POST" action="{{ route('products.destroy', $product->product_id) }}" class="hidden">
        @csrf
        @method('DELETE')
    </form>
</div>
@endsection