@extends('layouts.app')

@section('content')
<div class="card">
    <div class="page-header">
        <h2>Add Product</h2>
        <a class="btn secondary" href="{{ route('products.index') }}">Back</a>
    </div>

    @if ($errors->any())
        <div class="alert error" style="margin-top:12px;">{{ $errors->first() }}</div>
    @endif


    <form method="POST" action="{{ route('products.store') }}">
        @csrf

        <h3 style="margin-top:14px; margin-bottom:8px;">General</h3>
        <div style="height:1px; background:rgba(255,255,255,0.08); margin-bottom:14px;"></div>

        <div class="tab-pane" style="margin-top:14px;">
            <div class="form-grid">
            <div class="full">
                <label class="required">Name</label>
                <input class="input" name="name" value="{{ old('name') }}" required>
                @error('name')
                    <div class="hint" style="color:#ff6b6b; margin-top:4px;">{{ $message }}</div>
                @enderror
            </div>

            <div>
                <label class="required">Model</label>
                <input class="input" name="model" value="{{ old('model') }}" required>
                @error('model')
                    <div class="hint" style="color:#ff6b6b; margin-top:4px;">{{ $message }}</div>
                @enderror
            </div>

            <div>
                <label>SKU</label>
                <input class="input" name="sku" value="{{ old('sku') }}">
                @error('sku')
                    <div class="hint" style="color:#ff6b6b; margin-top:4px;">{{ $message }}</div>
                @enderror
            </div>

            <div>
                <label>Price</label>
                <input class="input" name="price" value="{{ old('price', '0') }}" required>
                @error('price')
                    <div class="hint" style="color:#ff6b6b; margin-top:4px;">{{ $message }}</div>
                @enderror
            </div>

            <div>
                <label>Quantity</label>
                <input class="input" name="quantity" value="{{ old('quantity', '0') }}" required>
                @error('quantity')
                    <div class="hint" style="color:#ff6b6b; margin-top:4px;">{{ $message }}</div>
                @enderror
            </div>

            <div class="typeahead">
                <label>Manufacturer</label>
                <input class="input" id="manufacturer_search" placeholder="Type to search..." autocomplete="off" value="{{ old('manufacturer_name','') }}" name="manufacturer_name">
                <input type="hidden" name="manufacturer_id" id="manufacturer_id" value="{{ old('manufacturer_id', 0) }}">
                <div class="typeahead-list" id="manufacturer_list"></div>
                <div class="hint">Start typing, then click a result.</div>
                @error('manufacturer_name')
                    <div class="hint" style="color:#ff6b6b; margin-top:4px;">{{ $message }}</div>
                @enderror
            </div>

            <div class="typeahead">
                <label>Category</label>
                <input class="input" id="category_search" placeholder="Type to search..." autocomplete="off" value="{{ old('category_name','') }}" name="category_name">
                <input type="hidden" name="category_id" id="category_id" value="{{ old('category_id', 0) }}">
                <div class="typeahead-list" id="category_list"></div>
                <div class="hint">Start typing, then click a result.</div>
                @error('category_name')
                    <div class="hint" style="color:#ff6b6b; margin-top:4px;">{{ $message }}</div>
                @enderror
            </div>

            <div>
                <label class="required">Status</label>
                <select class="input" name="status" required>
                    <option value="1" {{ old('status','1')=='1'?'selected':'' }}>Enabled</option>
                    <option value="0" {{ old('status','1')=='0'?'selected':'' }}>Disabled</option>
                </select>
                @error('status')
                    <div class="hint" style="color:#ff6b6b; margin-top:4px;">{{ $message }}</div>
                @enderror
            </div>

            <div class="full">
                <label>Description</label>
                <textarea class="input wysiwyg" name="description">{!! old('description', '') !!}</textarea>
                @error('description')
                    <div class="hint" style="color:#ff6b6b; margin-top:4px;">{{ $message }}</div>
                @enderror
            </div>
        </div>
        </div>

        <h3 style="margin-top:22px; margin-bottom:8px;">Options</h3>
        <div style="height:1px; background:rgba(255,255,255,0.08); margin-bottom:14px;"></div>

        <div class="tab-pane" style="margin-top:14px;">
            @include('products.partials.options_fields')
        </div>

        <div style="margin-top:16px; display:flex; gap:10px; align-items:center; justify-content:flex-end;">
            <button class="btn" type="submit">Save</button>
        </div>
    </form>

</div>

@endsection
