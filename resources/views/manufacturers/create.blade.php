@extends('layouts.app')

@section('content')
<div class="card">
    <div class="page-header">
        <h2>Add Manufacturer</h2>
        <a class="btn secondary" href="{{ route('manufacturers.index') }}">Back</a>
    </div>

    <form method="POST" action="{{ route('manufacturers.store') }}">
        @csrf

        <div class="form-grid">
            <div class="full">
                <label class="required">Name</label>
                <input class="input" name="name" value="{{ old('name') }}" required>
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

        <div style="margin-top:16px;">
            <button class="btn" type="submit">Save</button>
        </div>
    </form>
</div>
@endsection
