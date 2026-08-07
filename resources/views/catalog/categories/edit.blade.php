@extends('layouts.app')
@section('breadcrumb', 'Catalog / Categories / Edit')

@section('content')
<div class="card">
    <div class="page-header">
        <h2>Edit Category #{{ $category->category_id }}</h2>
        <div class="page-header-actions">
            <a class="btn secondary" href="{{ route('categories.index') }}">Back</a>
            <button class="btn" type="submit" form="category-form">Update</button>
        </div>
    </div>

    <form id="category-form" method="POST" action="{{ route('categories.update', $category->category_id) }}">
        @csrf
        @method('PUT')

        <div class="form-grid">
            <div class="full">
                <label class="required">Name</label>
                <input class="input" name="name" value="{{ old('name', html_entity_decode($category->name ?? '')) }}">
            </div>

            <div>
                <label>Parent Category</label>
                <select class="input" name="parent_id">
    <option value="0">-- None --</option>
    @foreach($parents as $p)
        <option value="{{ $p->category_id }}" {{ (int) old('parent_id', $category->parent_id) === (int) $p->category_id ? 'selected' : '' }}>
            {{ $p->name }}
        </option>
    @endforeach
</select>
            </div>

            <div>
                <label class="required">Status</label>
                <select class="input" name="status">
                    <option value="1" {{ old('status', (string)$category->status)=='1'?'selected':'' }}>Enabled</option>
                    <option value="0" {{ old('status', (string)$category->status)=='0'?'selected':'' }}>Disabled</option>
                </select>
            </div>

            <div>
                <label>Sort Order</label>
                <input class="input" name="sort_order" value="{{ old('sort_order', $category->sort_order) }}">
            </div>

            <div class="full">
                <label>Description</label>
                <textarea class="input wysiwyg" name="description">{!! old('description', $category->description) !!}</textarea>
            </div>
        </div>

        {{-- Update button moved to page header (top-right) --}}
</form>

<form id="delete-form" method="POST" action="{{ route('categories.destroy', $category->category_id) }}" class="hidden">
    @csrf
    @method('DELETE')
</form>

<div class="mt-16 d-flex justify-start">
    <button class="btn danger" type="button" data-confirm="Delete this item?" data-confirm-submit="delete-form">Delete</button>
</div>

</div>
@endsection
