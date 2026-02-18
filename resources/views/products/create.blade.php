@php $pimToken = (string) \Illuminate\Support\Str::uuid(); @endphp
@extends('layouts.app')
@section('breadcrumb', 'Catalog / Products / Add')

@section('content')
<div class="card">
    <div class="page-header">
        <h2>Add Product</h2>
        <a class="btn secondary" href="{{ route('products.index') }}">Back</a>
    </div>

    @if ($errors->any())
        <div class="alert error mt-12">{{ $errors->first() }}</div>
    @endif


    <form method="POST" action="{{ route('products.store') }}">
        @csrf

        <div class="typeahead mt-16">
            <label>Manufacturer</label>
            <input class="input" id="manufacturer_search" placeholder="Type to search..." autocomplete="off" value="{{ old('manufacturer_name','') }}" name="manufacturer_name">
            <input type="hidden" name="manufacturer_id" id="manufacturer_id" value="{{ old('manufacturer_id', 0) }}">
            <div class="typeahead-list" id="manufacturer_list"></div>
            <div class="hint">Start typing, then click a result. Required before uploading images.</div>
            @error('manufacturer_name')
                <div class="field-error">{{ $message }}</div>
            @enderror
        </div>

    @include('products.partials.image_manager', ['pimToken' => $pimToken, 'pimProductId' => 0])

        <h3 class="section-title">General</h3>

        <div class="tab-pane mt-16">
            <div class="form-grid">
            <div class="full">
                <label class="required">Name</label>
                <input class="input" name="name" value="{{ old('name') }}" required>
                @error('name')
                    <div class="field-error">{{ $message }}</div>
                @enderror
            </div>

            <div>
                <label class="required">Model</label>
                <input class="input" name="model" value="{{ old('model') }}" required>
                @error('model')
                    <div class="field-error">{{ $message }}</div>
                @enderror
            </div>

            <div>
                <label class="required">SKU</label>
                <input class="input" name="sku" value="{{ old('sku') }}" required>
                @error('sku')
                    <div class="field-error">{{ $message }}</div>
                @enderror
            </div>

            <div>
                <label>Price</label>
                <input class="input" name="price" value="{{ old('price', '0') }}" required>
                @error('price')
                    <div class="field-error">{{ $message }}</div>
                @enderror
            </div>

            <div>
                <label>Quantity</label>
                <input class="input" id="product_quantity" name="quantity" value="{{ old('quantity', '0') }}" required>
                @error('quantity')
                    <div class="field-error">{{ $message }}</div>
                @enderror
                <div class="hint hidden" id="qty_hint">Quantity is auto-calculated from option quantities.</div>
            </div>

            <div>
                <label>Weight</label>
                <input class="input" name="weight" value="{{ old('weight', '0') }}" placeholder="e.g. 0.5">
                @error('weight')
                    <div class="field-error">{{ $message }}</div>
                @enderror
            </div>

            <div>
                <label>Length</label>
                <input class="input" name="length" value="{{ old('length', '0') }}" placeholder="e.g. 10">
                @error('length')
                    <div class="field-error">{{ $message }}</div>
                @enderror
            </div>

            <div>
                <label>Width</label>
                <input class="input" name="width" value="{{ old('width', '0') }}" placeholder="e.g. 5">
                @error('width')
                    <div class="field-error">{{ $message }}</div>
                @enderror
            </div>

            <div>
                <label>Height</label>
                <input class="input" name="height" value="{{ old('height', '0') }}" placeholder="e.g. 3">
                @error('height')
                    <div class="field-error">{{ $message }}</div>
                @enderror
            </div>

            <div class="typeahead">
                <label>Category</label>
                <input class="input" id="category_search" placeholder="Type to search..." autocomplete="off" value="{{ old('category_name','') }}" name="category_name">
                <input type="hidden" name="category_id" id="category_id" value="{{ old('category_id', 0) }}">
                <div class="typeahead-list" id="category_list"></div>
                <div class="hint">Start typing, then click a result.</div>
                @error('category_name')
                    <div class="field-error">{{ $message }}</div>
                @enderror
            </div>

            <div>
                <label class="required">Status</label>
                <select class="input" name="status" required>
                    <option value="1" {{ old('status','1')=='1'?'selected':'' }}>Enabled</option>
                    <option value="0" {{ old('status','1')=='0'?'selected':'' }}>Disabled</option>
                </select>
                @error('status')
                    <div class="field-error">{{ $message }}</div>
                @enderror
            </div>

            <div class="full">
                <label>Description</label>
                <textarea class="input wysiwyg" name="description">{!! old('description', '') !!}</textarea>
                @error('description')
                    <div class="field-error">{{ $message }}</div>
                @enderror
            </div>
        </div>
        </div>

        <h3 class="section-title mt-24">Options</h3>

        <div class="tab-pane mt-16">
            @include('products.partials.options_fields')
        </div>

        <div class="mt-16 d-flex gap-10 items-center justify-end">
            <button class="btn" type="submit">Save</button>
        </div>
    </form>

</div>

@endsection
