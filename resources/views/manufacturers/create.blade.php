@extends('layouts.app')
@section('breadcrumb', 'Catalog / Manufacturers / Add')

@section('content')
<div class="card">
    <div class="page-header">
        <h2>Add Manufacturer</h2>
        <div class="page-header-actions">
            <a class="btn secondary" href="{{ route('manufacturers.index') }}">Back</a>
            <button class="btn" type="submit" form="manufacturer-form">Save</button>
        </div>
    </div>

    <form id="manufacturer-form" method="POST" action="{{ route('manufacturers.store') }}">
        @csrf

        <div class="form-grid">
            <div class="full">
                <label class="required">Name</label>
                <input class="input" name="name" value="{{ old('name') }}">
            </div>

            <div>
                <label>Sort Order</label>
                <input class="input" name="sort_order" value="{{ old('sort_order', 0) }}">
            </div>


        </div>
        {{-- Save button moved to page header (top-right) --}}
    </form>
</div>
@endsection
