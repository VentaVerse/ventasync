@extends('layouts.app')
@section('breadcrumb', 'Settings / API / Add Application')

@section('content')

<div class="card">
    <div class="page-header">
        <h2>Add API Application</h2>
        <a class="btn secondary" href="{{ route('api_clients.index') }}">Back</a>
    </div>

    @if(session('error'))
        <div class="field-error">{{ session('error') }}</div>
    @endif

    <form method="POST" action="{{ route('api_clients.store') }}">
        @csrf

        <div class="form-grid">
            <div>
                <label class="required">Name</label>
                <input class="input" name="name" value="{{ old('name') }}" required maxlength="255"
                       placeholder="e.g. Pricing Assistant">
                @error('name')<div class="field-error">{{ $message }}</div>@enderror
            </div>
            <div>
                <label>Description</label>
                <input class="input" name="description" value="{{ old('description') }}" maxlength="255"
                       placeholder="What this application is for">
            </div>

            <div class="full">
                <label class="required">Permissions</label>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr><th>Resource</th><th style="width:90px;">Read</th><th style="width:90px;">Write</th></tr>
                        </thead>
                        <tbody>
                            @foreach($scopes as $key => $meta)
                                <tr>
                                    <td>
                                        {{ $meta['label'] }}
                                        <div class="text-xs text-secondary">{{ $meta['description'] }}</div>
                                    </td>
                                    <td>
                                        @if(in_array('read', $meta['actions'], true))
                                            <input type="checkbox" name="scopes[{{ $key }}][read]" value="1"
                                                   @checked(old("scopes.$key.read"))>
                                        @else
                                            <span class="text-secondary">—</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if(in_array('write', $meta['actions'], true))
                                            <input type="checkbox" name="scopes[{{ $key }}][write]" value="1"
                                                   @checked(old("scopes.$key.write"))>
                                        @else
                                            <span class="text-secondary">—</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @error('scopes')<div class="field-error">{{ $message }}</div>@enderror
            </div>

            <div class="full">
                <label>Expiry</label>
                <label class="text-xs">
                    <input type="checkbox" name="no_expiry" value="1" {{ old('no_expiry', '1') ? 'checked' : '' }}> No expiry
                </label>
                <div class="text-xs text-secondary">Or set an expiry date (uncheck &ldquo;No expiry&rdquo; to use it):</div>
                <input class="input" type="date" name="expires_at" value="{{ old('expires_at') }}">
                @error('expires_at')<div class="field-error">{{ $message }}</div>@enderror
            </div>
        </div>

        <div class="page-header">
            <button class="btn" type="submit">Create &amp; issue token</button>
        </div>
    </form>
</div>

@endsection
