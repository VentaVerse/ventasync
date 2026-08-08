@extends('layouts.channel')
@section('title', 'Shopee Authorisation')
@section('breadcrumb', 'Authorisation')

{{-- Round F Task 1 finding: this is NOT a bare redirect. It is a page a human
     reads and copies from.

     Shopee sends the operator's browser here after they authorise the app, with
     the two values the token exchange needs in the query string. The page's
     whole job is to show them so they can be carried to the settings screen.
     It has no form, no script and no CSRF, and it never had any. That function
     is untouched: the same two controller variables, the same names, the same
     values, the same link back.

     Its chrome had to change regardless of that, because it extended
     layouts/app.blade.php, which Round F Task 4 deleted. Left alone, this page
     would have 500'd the moment the layout went. So it moves onto layouts.channel
     with the rest of the workspace and is drawn on the fm- anatomy, and the
     two values are read-only fields in mono with a plain instruction above
     them instead of unlabelled boxes. --}}
@section('content')
<div class="fm-page">

    <div class="fm-head">
        <div class="fm-head__main">
            <a class="fm-back" href="{{ route('ext.shopee.index') }}">
                <x-ui.icon name="chevron-left" size="14" /> Shopee settings
            </a>
            <h1 class="fm-title">Shopee sent these back</h1>
        </div>
    </div>

    <div class="fm-body fm-body--single">
        <div class="fm-col fm-col--main">
            <section class="fm-section">
                <div class="fm-section__head">
                    <h2 class="fm-section__title">Copy these into the Shopee settings screen</h2>
                </div>
                <p class="fm-section__note">These two values are what the settings screen exchanges for an access token. They are short lived, so do it now rather than later.</p>

                <div class="fm-fields fm-fields--2">
                    <x-ui.field label="Code" for="cb-code" hint="Shopee's one-time authorisation code.">
                        <x-ui.input id="cb-code" class="fm-input--num" value="{{ $code }}" readonly
                                    aria-label="Shopee authorisation code, read only" />
                    </x-ui.field>

                    <x-ui.field label="Shop ID" for="cb-shop" hint="Which Shopee shop authorised this.">
                        <x-ui.input id="cb-shop" class="fm-input--num" value="{{ $shop_id }}" readonly
                                    aria-label="Shopee shop ID, read only" />
                    </x-ui.field>
                </div>
            </section>
        </div>
    </div>

    <div class="fm-bar">
        <span class="fm-bar__meta">Nothing has been saved. The settings screen is where these are exchanged.</span>
        <div class="fm-bar__actions">
            <x-ui.button variant="primary" :href="route('ext.shopee.index')">Go to Shopee settings</x-ui.button>
        </div>
    </div>
</div>
@endsection
