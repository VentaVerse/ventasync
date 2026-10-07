@extends('layouts.blotter')

@php
    $tabs = [
        'general' => [
            'label' => 'General',
            'url'   => route('settings.general'),
            'sub'   => 'The company name and address that identify this business on documents and mail.',
            'saves' => true,
        ],
        'website' => [
            'label' => 'Website Settings',
            'url'   => route('settings.website'),
            'sub'   => 'The sender address, the timezone every date is shown in, how long activity is kept, and the logo and favicon.',
            'saves' => true,
        ],
        'mail' => [
            'label' => 'Mail',
            'url'   => route('settings.mail'),
            'sub'   => 'How this system sends mail, and a way to prove it works.',
            'saves' => true,
        ],
        'maintenance' => [
            'label' => 'Maintenance',
            'url'   => route('settings.maintenance'),
            'sub'   => 'The one cron line every scheduled task in this application runs from.',
            'saves' => false,
        ],
    ];

    $activeTab = array_key_exists($activeTab, $tabs) ? $activeTab : 'general';
    $tab = $tabs[$activeTab];

    $canManage = auth()->user()?->hasPermission('manage_settings/setting') ?? false;
    $ro = ! $canManage;
@endphp

@section('title', $tab['label'])
@section('breadcrumb', $tab['label'])

@section('content')
<div class="fm-page">

    <div class="fm-flash" role="status" aria-live="polite">
        @if(session('success'))
            <div class="fm-note fm-note--ok"><div class="fm-note__body">{{ session('success') }}</div></div>
        @endif
        @if(session('error'))
            <div class="fm-note fm-note--fail"><div class="fm-note__body">{{ session('error') }}</div></div>
        @endif
        @if($errors->any())
            <div class="fm-note fm-note--fail">
                <div class="fm-note__body">Nothing was saved. Check the fields marked below.</div>
            </div>
        @endif
        @if($ro)
            <div class="fm-note fm-note--warn">
                <div class="fm-note__body">
                    Read only. You can browse these settings but not change them. Saving needs the
                    <code class="st-key">manage_website_settings</code> permission.
                </div>
            </div>
        @endif
    </div>

    <div class="fm-head">
        <div class="fm-head__main">
            <a class="fm-back" href="{{ route('settings.hub') }}" data-guard-leave>
                <x-ui.icon name="chevron-left" size="14" /> Settings
            </a>
            <h1 class="fm-title">{{ $tab['label'] }}</h1>
        </div>
    </div>

    <nav class="x-segment st-tabs" aria-label="Settings sections">
        @foreach($tabs as $key => $item)
            <a class="x-segment__item {{ $activeTab === $key ? 'is-active' : '' }}"
               href="{{ $item['url'] }}" data-guard-leave
               @if($activeTab === $key) aria-current="page" @endif>{{ $item['label'] }}</a>
        @endforeach
    </nav>

    <p class="st-lede">{{ $tab['sub'] }}</p>

    @if($tab['saves'])
        <form method="POST" action="{{ route('settings.update') }}" enctype="multipart/form-data" class="st-set-form" data-guard-unsaved>
            @csrf

            @if($activeTab === 'general')
                @include('settings.partials.tab-general', ['setting' => $setting, 'ro' => $ro])
            @elseif($activeTab === 'website')
                @include('settings.partials.tab-website', ['setting' => $setting, 'ro' => $ro])
            @elseif($activeTab === 'mail')
                @include('settings.partials.tab-mail', ['setting' => $setting, 'ro' => $ro])
            @endif

            @include('settings.partials.carry', ['setting' => $setting, 'activeTab' => $activeTab])

            @if($canManage)
            <x-ui.form-bar>
                <x-slot:note>{{ $tab['label'] }}</x-slot:note>
                <x-slot:primary><x-ui.button type="submit" variant="primary">Save settings</x-ui.button></x-slot:primary>
            </x-ui.form-bar>
            @endif
        </form>
    @else
        @include('settings.partials.tab-maintenance')
    @endif
</div>
@endsection
