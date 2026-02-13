@extends('layouts.app')

@section('content')
<div class="card">
    <div class="page-header">
        <h2>Edit Manufacturer #{{ $manufacturer->manufacturer_id }}</h2>
        <div style="display:flex; gap:10px; align-items:center;">
            <a class="btn secondary" href="{{ route('manufacturers.index') }}">Back</a>
            <button class="btn" type="submit" form="manufacturer-form">Update</button>
        </div>
    </div>

    <form id="manufacturer-form" method="POST" action="{{ route('manufacturers.update', $manufacturer->manufacturer_id) }}">
        @csrf
        @method('PUT')

        <div class="form-grid">
            <div class="full">
                <label class="required">Name</label>
                <input class="input" name="name" value="{{ old('name', $manufacturer->name) }}" required>
            </div>

            <div>
                <label>Sort Order</label>
                <input class="input" name="sort_order" value="{{ old('sort_order', $manufacturer->sort_order) }}">
            </div>

            <div class="full">
                <label>Description</label>
                <textarea class="input wysiwyg" name="description">{!! old('description', $manufacturer_description ?? '') !!}</textarea>
            </div>
        </div>

        {{-- Update button moved to page header (top-right) --}}
    </form>

    <form id="delete-manufacturer-form" method="POST" action="{{ route('manufacturers.destroy', $manufacturer->manufacturer_id) }}" style="display:none;">
        @csrf
        @method('DELETE')
    </form>

    <div style="margin-top:16px; display:flex; justify-content:flex-start;">
        <button class="btn danger" type="button"
            onclick="if(confirm('Delete this manufacturer?')) document.getElementById('delete-manufacturer-form').submit();">
            Delete
        </button>
    </div>
</div>
@endsection
