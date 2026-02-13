@extends('layouts.app')

@section('content')
<div class="card">
    <div class="page-header">
        <h2>Edit Option #{{ (int)$option->option_id }}</h2>
        <a class="btn secondary" href="{{ route('options.index') }}">Back</a>
    </div>

    @if(session('success'))
        <div class="alert success">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="alert error">{{ session('error') }}</div>
    @endif

    @if ($errors->any())
        <div class="alert error">
            <ul style="margin:0; padding-left:18px;">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('options.update', $option->option_id) }}">
        @csrf
        @method('PUT')

        <div class="form-grid">
            <div class="full">
                <label class="required">Name</label>
                <input class="input" name="name" value="{{ old('name', $option->name) }}" required>
            </div>

            <div>
                <label class="required">Type</label>
                <select class="input" name="type" required>
                    @foreach(['select','radio','checkbox','text','textarea','file','date','time','datetime'] as $t)
                        <option value="{{ $t }}" {{ old('type', $option->type)===$t?'selected':'' }}>{{ $t }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="required">Sort Order</label>
                <input class="input" name="sort_order" type="number" value="{{ old('sort_order', (int)$option->sort_order) }}" required>
            </div>
        </div>

        <hr style="margin:18px 0; opacity:.25;">

        <h3 style="margin:0 0 10px 0;">Values</h3>

        <table>
            <thead>
            <tr>
                <th style="width:90px;">ID</th>
                <th>Name</th>
                <th style="width:140px;">Sort</th>
                <th style="width:140px;">Actions</th>
            </tr>
            </thead>
            <tbody>
            @foreach($values as $i => $v)
                <tr>
                    <td>{{ (int)$v->option_value_id }}</td>
                    <td>
                        <input type="hidden" name="values[{{ $i }}][option_value_id]" value="{{ (int)$v->option_value_id }}">
                        <input class="input" name="values[{{ $i }}][name]" value="{{ old('values.'.$i.'.name', $v->name) }}">
                    </td>
                    <td>
                        <input class="input" type="number" name="values[{{ $i }}][sort_order]" value="{{ old('values.'.$i.'.sort_order', (int)$v->sort_order) }}">
                    </td>
                    <td class="actions">
                        <form method="POST" action="{{ route('options.values.destroy', [$option->option_id, $v->option_value_id]) }}" onsubmit="return confirm('Delete this value?');" style="display:inline;">
                            @csrf
                            @method('DELETE')
                            <button class="btn danger" type="submit">Delete</button>
                        </form>
                    </td>
                </tr>
            @endforeach

            @for($n=0; $n<3; $n++)
                <tr>
                    <td>new</td>
                    <td><input class="input" name="new_values[{{ $n }}][name]" value="{{ old('new_values.'.$n.'.name') }}" placeholder="Add new value"></td>
                    <td><input class="input" type="number" name="new_values[{{ $n }}][sort_order]" value="{{ old('new_values.'.$n.'.sort_order', 0) }}"></td>
                    <td></td>
                </tr>
            @endfor
            </tbody>
        </table>

        <div style="margin-top:16px; display:flex; gap:10px; align-items:center;">
            <button class="btn" type="submit">Save</button>
        </div>
    </form>
</div>
@endsection
