@extends('layouts.app')

@section('content')
<div class="card">
    <div class="page-header">
        <h2>Options</h2>
        <a class="btn" href="{{ route('options.create') }}">Add Option</a>
    </div>

    <div class="toolbar">
        <form method="GET" action="{{ route('options.index') }}">
            <input class="input" name="q" value="{{ $q ?? '' }}" placeholder="Search option">
            <button class="btn secondary" type="submit">Search</button>
        </form>
    </div>

    @if(session('success'))
        <div class="alert success">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="alert error">{{ session('error') }}</div>
    @endif

    <table>
        <thead>
        <tr>
            <th style="width:90px;">ID</th>
            <th>Name</th>
            <th style="width:160px;">Type</th>
            <th style="width:120px;">Sort</th>
            <th style="width:180px;">Actions</th>
        </tr>
        </thead>
        <tbody>
        @foreach($options as $o)
            <tr>
                <td>{{ (int)$o->option_id }}</td>
                <td>{{ $o->name }}</td>
                <td>{{ $o->type }}</td>
                <td>{{ (int)$o->sort_order }}</td>
                <td class="actions">
                    <a class="btn secondary" href="{{ route('options.edit', $o->option_id) }}">Edit</a>
                    <form method="POST" action="{{ route('options.destroy', $o->option_id) }}" onsubmit="return confirm('Delete this option? This cannot be undone.');" style="display:inline;">
                        @csrf
                        @method('DELETE')
                        <button class="btn danger" type="submit">Delete</button>
                    </form>
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <div style="margin-top:14px;">
        {{ $options->links() }}
    </div>
</div>
@endsection
