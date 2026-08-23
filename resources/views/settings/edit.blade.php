@extends('layouts.blotter')

@php
    // One controller action and one view back four real routes
    // (settings.general / .website / .mail / .maintenance, each with a
    // ->defaults('tab', …)). Before this round all four rendered at once and
    // a script hid three of them, so a page that had been split into four
    // URLs was still one document pretending to be four. Each tab is now its
    // own page: only its own sections render, and the segment below is real
    // navigation between four addresses rather than a class toggle.
    // Each tab's URL is resolved here with a clean literal route() call, not
    // route($item['route']) inside the loop below. A route name assembled
    // from a variable is invisible to RouteNameSweepTest's static half, which
    // is the sweep that exists precisely so a renamed route cannot ship as a
    // 500 nobody noticed.
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

    // edit() falls back to 'general' when no route default is bound, but a
    // partial name is built from this below, so an unknown value has to
    // resolve here rather than reach @include.
    $activeTab = array_key_exists($activeTab, $tabs) ? $activeTab : 'general';
    $tab = $tabs[$activeTab];

    // routes/settings.php gates the four GETs on
    // view_website_settings|manage_website_settings and settings.update on
    // manage_website_settings alone. The pre-redesign view hardcoded this to
    // true, so a read-tier operator saw a Save button that would bounce.
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
            <a class="fm-back" href="{{ route('settings.hub') }}">
                <x-ui.icon name="chevron-left" size="14" /> Settings
            </a>
            <h1 class="fm-title">{{ $tab['label'] }}</h1>
        </div>
    </div>

    <nav class="x-segment st-tabs" aria-label="Settings sections">
        @foreach($tabs as $key => $item)
            <a class="x-segment__item {{ $activeTab === $key ? 'is-active' : '' }}"
               href="{{ $item['url'] }}"
               @if($activeTab === $key) aria-current="page" @endif>{{ $item['label'] }}</a>
        @endforeach
    </nav>

    <p class="st-lede">{{ $tab['sub'] }}</p>

    @if($tab['saves'])
        {{-- enctype is load-bearing: the Website Settings tab posts the logo
             and favicon files through this same action. All four tabs share
             one form element so the markup does not fork on which tab is
             showing. --}}
        <form method="POST" action="{{ route('settings.update') }}" enctype="multipart/form-data" class="st-set-form">
            @csrf

            {{-- Literal include names, not 'tab-' . $activeTab. The view
                 sweep (tests/Feature/ViewResolutionSweepTest) resolves every
                 referenced view and can only do that for a literal, and a
                 dynamically-built name is exactly how a renamed partial ships
                 as a 500 nobody noticed. $activeTab is already normalised to
                 one of these four above. --}}
            @if($activeTab === 'general')
                @include('settings.partials.tab-general', ['setting' => $setting, 'ro' => $ro])
            @elseif($activeTab === 'website')
                @include('settings.partials.tab-website', ['setting' => $setting, 'ro' => $ro])
            @elseif($activeTab === 'mail')
                @include('settings.partials.tab-mail', ['setting' => $setting, 'ro' => $ro])
            @endif

            {{-- SettingController::update() validates the UNION of all four
                 tabs (company_name, from_email, timezone,
                 activity_log_retention_days and mail_mailer are each
                 `required`), so a tab that posted only its own fields would
                 fail validation on fields the operator cannot even see. The
                 pre-redesign page never hit this because all four tabs sat
                 inside one form and posted together. This carries the stored
                 value of every required field the current tab does not show,
                 which reproduces exactly what that one form used to send. --}}
            @include('settings.partials.carry', ['setting' => $setting, 'activeTab' => $activeTab])

            @if($canManage)
            <div class="fm-bar">
                <span class="fm-bar__meta">{{ $tab['label'] }}</span>
                <div class="fm-bar__actions">
                    <x-ui.button type="submit" variant="primary">Save settings</x-ui.button>
                </div>
            </div>
            @endif
        </form>
    @else
        @include('settings.partials.tab-maintenance')
    @endif
</div>
@endsection
