@extends('layouts.app')
@section('breadcrumb', 'Catalog / Categories / Add')

@section('content')
<div class="card">
    <div class="page-header">
        <h2>Add Category</h2>
        <div class="page-header-actions">
            <a class="btn secondary" href="{{ route('categories.index') }}">Back</a>
            <button class="btn" type="submit" form="category-form">Save</button>
        </div>
    </div>

    <form id="category-form" method="POST" action="{{ route('categories.store') }}">
        @csrf

        <div class="form-grid">
            <div class="full">
                <label class="required">Name</label>
                <input class="input" name="name" value="{{ old('name') }}">
            </div>

            <div>
                <label>Parent Category</label>
                <select class="input" name="parent_id">
    <option value="0">-- None --</option>
    @foreach($parents as $p)
        <option value="{{ $p->category_id }}" {{ (int) old('parent_id', 0) === (int) $p->category_id ? 'selected' : '' }}>
            {{ $p->name }}
        </option>
    @endforeach
</select>
            </div>

            <div>
                <label class="required">Status</label>
                <select class="input" name="status">
                    <option value="1" {{ old('status','1')=='1'?'selected':'' }}>Enabled</option>
                    <option value="0" {{ old('status','1')=='0'?'selected':'' }}>Disabled</option>
                </select>
            </div>

            <div>
                <label>Sort Order</label>
                <input class="input" name="sort_order" value="{{ old('sort_order', 0) }}">
            </div>

            <div class="full">
                <label>Description</label>
                <textarea class="input wysiwyg" name="description">{!! old('description', '') !!}</textarea>
            </div>
        </div>

        {{-- Save button moved to page header (top-right) --}}
    </form>
</div>
@endsection
