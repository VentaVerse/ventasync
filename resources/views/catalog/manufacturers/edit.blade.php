@extends('layouts.app')
@section('breadcrumb', 'Catalog / Manufacturers / Edit')

@section('content')
<div class="card">
    <div class="page-header">
        <h2>Edit Manufacturer #{{ $manufacturer->manufacturer_id }}</h2>
        <div class="page-header-actions">
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
                <input class="input" name="name" value="{{ old('name', $manufacturer->name) }}">
            </div>

            <div>
                <label>Sort Order</label>
                <input class="input" name="sort_order" value="{{ old('sort_order', $manufacturer->sort_order) }}">
            </div>


        </div>

        {{-- Update button moved to page header (top-right) --}}
    </form>

    <form id="delete-manufacturer-form" method="POST" action="{{ route('manufacturers.destroy', $manufacturer->manufacturer_id) }}" class="hidden">
        @csrf
        @method('DELETE')
    </form>

    <div class="mt-16 d-flex justify-start">
        <button class="btn danger" type="button"
            data-confirm="Delete this manufacturer?" data-confirm-submit="delete-manufacturer-form">
            Delete
        </button>
    </div>
</div>
@endsection
