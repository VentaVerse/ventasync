@extends('layouts.app')

@section('content')
<div class="card">
    <div class="page-header">
        <h2>Edit Category #{{ $category->category_id }}</h2>
        <a class="btn secondary" href="{{ route('categories.index') }}">Back</a>
    </div>

    <form method="POST" action="{{ route('categories.update', $category->category_id) }}">
        @csrf
        @method('PUT')

        <div class="form-grid">
            <div class="full">
                <label class="required">Name</label>
                <input class="input" name="name" value="{{ old('name', html_entity_decode($category->name ?? '')) }}" required>
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
                <select class="input" name="status" required>
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

            <div style="margin-top:16px; display:flex; gap:10px; align-items:center;">
        <button class="btn" type="submit">Update</button>
        <button class="btn danger" type="button" onclick="if(confirm('Delete this item?')) document.getElementById('delete-form').submit();">Delete</button>
    </div>
</form>

<form id="delete-form" method="POST" action="{{ route('categories.destroy', $category->category_id) }}" style="display:none;">
    @csrf
    @method('DELETE')
</form>

</div>
@endsection
