@extends('layouts.app')
@section('breadcrumb', 'Settings / Extensions')

@section('content')

@if(session('success'))
    <div class="alert success">{{ session('success') }}</div>
@endif
@if(session('error'))
    <div class="alert danger">{{ session('error') }}</div>
@endif

<div class="card">
    <div class="page-header">
        <h2>Extensions</h2>
        <div class="page-header-actions">
            <form method="POST" action="{{ route('extensions.install') }}" enctype="multipart/form-data" style="display:flex; align-items:center; gap:8px;">
                @csrf
                <input type="file" name="file" accept=".erpx,.zip" required style="max-width:240px;">
                <button class="btn" type="submit">Install</button>
            </form>
        </div>
    </div>

    @if(count($extensions) > 0)
    <div class="table-wrap">
    <table>
        <thead>
        <tr>
            <th>Name</th>
            <th style="width:90px;">Version</th>
            <th>Description</th>
            <th style="width:100px;">Status</th>
            <th style="width:200px;">License</th>
            <th style="width:180px;">Actions</th>
        </tr>
        </thead>
        <tbody>
        @foreach($extensions as $ext)
            <tr>
                <td>
                    <strong>{{ $ext['name'] }}</strong>
                    @if($ext['author'])
                        <br><span class="text-secondary" style="font-size:12px;">{{ $ext['author'] }}</span>
                    @endif
                </td>
                <td>{{ $ext['version'] }}</td>
                <td>{{ $ext['description'] }}</td>
                <td>
                    @if($ext['installed'])
                        @if($ext['enabled'])
                            <span class="badge badge-green">Enabled</span>
                        @else
                            <span class="badge badge-gray">Disabled</span>
                        @endif
                    @else
                        <span class="text-secondary" style="font-size:12px;">Not installed</span>
                    @endif
                </td>
                <td>
                    @if($ext['license_required'])
                        @if($ext['installed'])
                        <form method="POST" action="{{ route('extensions.update-license', $ext['id']) }}" style="display:flex; align-items:center; gap:4px;">
                            @csrf
                            @method('PUT')
                            <input class="input" type="text" name="license_key" value="{{ $ext['license_key'] ?? '' }}" placeholder="License key" style="max-width:120px; font-size:12px; padding:4px 6px;">
                            <button class="btn small secondary" type="submit">Save</button>
                        </form>
                        @else
                            <span class="text-secondary" style="font-size:12px;">License required</span>
                        @endif
                    @else
                        <span class="text-secondary" style="font-size:12px;">Free</span>
                    @endif
                </td>
                <td class="d-flex gap-8 items-center">
                    @if($ext['installed'])
                        <form method="POST" action="{{ route('extensions.toggle', $ext['id']) }}" style="display:inline;">
                            @csrf
                            <button class="btn small {{ $ext['enabled'] ? 'secondary' : '' }}" type="submit">
                                {{ $ext['enabled'] ? 'Disable' : 'Enable' }}
                            </button>
                        </form>
                        <form method="POST" action="{{ route('extensions.uninstall', $ext['id']) }}" onsubmit="return confirm('Uninstall this extension? All extension files will be deleted.')" style="display:inline;">
                            @csrf
                            @method('DELETE')
                            <button class="btn danger small" type="submit">Uninstall</button>
                        </form>
                    @endif
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
    </div>
    @else
    <p style="padding:24px; color:var(--text-secondary); text-align:center;">No extensions installed. Upload a <code>.erpx</code> or <code>.zip</code> file to get started.</p>
    @endif
</div>
@endsection
