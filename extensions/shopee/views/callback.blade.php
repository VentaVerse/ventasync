@extends('layouts.channel')
@section('title', 'Shopee Authorisation')
@section('breadcrumb', 'Authorisation')

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

    <x-ui.form-bar>
        <x-slot:note>Nothing has been saved. The settings screen is where these are exchanged.</x-slot:note>
        <x-slot:primary>
            <x-ui.button variant="primary" :href="route('ext.shopee.index')">Go to Shopee settings</x-ui.button>
        </x-slot:primary>
    </x-ui.form-bar>
</div>
@endsection
