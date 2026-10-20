@extends('layouts.channel')
@section('title', ($group->exists ? $group->name : 'New product group') . ', ' . $channelName)
@section('breadcrumb', $group->exists ? $group->name : 'New product group')

@section('own-errors', true)

@section('content')
<form method="POST" class="fm-page" action="{{ $saveUrl }}" data-guard-unsaved>
    @csrf
    @if($group->exists)
        @method('PUT')
    @endif

    <div class="fm-flash" role="status" aria-live="polite">
        @if($errors->any())
            <div class="fm-note fm-note--fail">
                <div class="fm-note__body">Nothing was saved. Check the fields marked below.</div>
            </div>
        @endif
    </div>

    <div class="fm-head">
        <div class="fm-head__main">
            <a class="fm-back" href="{{ $backUrl }}">
                <x-ui.icon name="chevron-left" size="14" /> Product Groups
            </a>
            <h1 class="fm-title">{{ $group->exists ? $group->name : 'New product group' }}</h1>
            @if($storeName !== '')
                <div class="fm-stamps">
                    <span class="fm-stamp"><span class="fm-stamp__k">Store</span><span class="fm-stamp__v">{{ $storeName }}</span></span>
                </div>
            @endif
        </div>
    </div>

    <div class="fm-body">
        <div class="fm-col fm-col--main">
            <section class="fm-section">
                <div class="fm-fields">
                    <x-ui.field label="Product group name" for="pg-name" name="name" :required="true" wide>
                        <x-ui.input id="pg-name" name="name" value="{{ old('name', $group->name) }}" maxlength="128"
                                    placeholder="Guitar pedals" :disabled="! $canManage" />
                    </x-ui.field>
                </div>
            </section>
        </div>
    </div>

    <x-ui.form-bar :cancel="$backUrl" :cancel-label="$canManage ? 'Cancel' : 'Back'">
        <x-slot:primary>
            @if($canManage)
                <x-ui.button type="submit" variant="primary">{{ $group->exists ? 'Save product group' : 'Create product group' }}</x-ui.button>
            @endif
        </x-slot:primary>
    </x-ui.form-bar>
</form>
@endsection
