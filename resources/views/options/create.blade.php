@extends('layouts.app')

@section('content')
<div class="card">
    <div class="page-header">
        <h2>Add Option</h2>
        <a class="btn secondary" href="{{ route('options.index') }}">Back</a>
    </div>

    @if ($errors->any())
        <div class="alert error">
            <ul style="margin:0; padding-left:18px;">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('options.store') }}">
        @csrf

        <div class="form-grid">
            <div class="full">
                <label class="required">Name</label>
                <input class="input" name="name" value="{{ old('name') }}" required>
            </div>

            <div>
                <label class="required">Type</label>
                <select class="input" name="type" required>
                    @foreach(['select','radio','checkbox','text','textarea','file','date','time','datetime'] as $t)
                        <option value="{{ $t }}" {{ old('type','select')===$t?'selected':'' }}>{{ $t }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="required">Sort Order</label>
                <input class="input" name="sort_order" type="number" value="{{ old('sort_order', 0) }}" required>
            </div>
        </div>

        <div style="margin-top:16px; display:flex; gap:10px; align-items:center;">
            <button class="btn" type="submit">Create</button>
        </div>
    </form>
</div>
@endsection
