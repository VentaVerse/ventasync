@extends('layouts.app')
@section('breadcrumb', 'Settings / API / Edit')

@php
    $hasExpiry = old('expires_at', $expiresAt?->format('Y-m-d'));
    $noExpiry = old('no_expiry', $expiresAt ? null : '1');
@endphp

@section('content')

<div class="card">
    <div class="page-header">
        <h2>Edit API Application</h2>
        <a class="btn secondary" href="{{ route('api_clients.index') }}">Back</a>
    </div>

    @if(session('error'))
        <div class="field-error">{{ session('error') }}</div>
    @endif

    <form method="POST" action="{{ route('api_clients.update', $client->id) }}">
        @csrf
        @method('PUT')

        <div class="form-grid">
            <div class="full">
                <label class="required">Name</label>
                <input class="input" name="name" value="{{ old('name', $client->name) }}" required maxlength="255">
                @error('name')<div class="field-error">{{ $message }}</div>@enderror
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
                                                   @checked(old("scopes.$key.read", in_array("$key:read", $current, true)))>
                                        @else
                                            <span class="text-secondary">—</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if(in_array('write', $meta['actions'], true))
                                            <input type="checkbox" name="scopes[{{ $key }}][write]" value="1"
                                                   @checked(old("scopes.$key.write", in_array("$key:write", $current, true)))>
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
                    <input type="checkbox" name="no_expiry" value="1" {{ $noExpiry ? 'checked' : '' }}> No expiry
                </label>
                <div class="text-xs text-secondary">Or set an expiry date (uncheck &ldquo;No expiry&rdquo; to use it):</div>
                <input class="input" type="date" name="expires_at" value="{{ $hasExpiry }}">
                @error('expires_at')<div class="field-error">{{ $message }}</div>@enderror
            </div>
        </div>

        <div class="page-header">
            <button class="btn" type="submit">Save changes</button>
        </div>
    </form>
</div>

@endsection
