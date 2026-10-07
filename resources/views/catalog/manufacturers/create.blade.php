@extends('layouts.blotter')
@section('title', 'New manufacturer')

@section('content')
<form method="POST" action="{{ route('manufacturers.store') }}" class="fm-page" data-guard-unsaved>
    @csrf

    <div class="fm-flash" role="status" aria-live="polite">
        @if($errors->any())
            <div class="fm-note fm-note--fail">
                <div class="fm-note__body">Nothing was saved. Check the fields marked below.</div>
            </div>
        @endif
    </div>

    <div class="fm-head">
        <div class="fm-head__main">
            <a class="fm-back" href="{{ route('manufacturers.index') }}" data-guard-leave>
                <x-ui.icon name="chevron-left" size="14" /> Manufacturers
            </a>
            <h1 class="fm-title">New manufacturer</h1>
        </div>
    </div>

    <div class="fm-body">
        <div class="fm-col fm-col--main">

            <section class="fm-section">
                <div class="fm-section__head">
                    <h2 class="fm-section__title">General</h2>
                </div>

                <div class="fm-fields">
                    <x-ui.field label="Name" for="manufacturer_name_field" name="name" :required="true" wide>
                        <x-ui.input id="manufacturer_name_field" name="name" value="{{ old('name') }}" />
                    </x-ui.field>
                </div>
            </section>
        </div>

        <div class="fm-col fm-col--side">

            <div class="fm-card">
                <div class="fm-card__head"><h2 class="fm-card__title">Details</h2></div>
                <div class="fm-card__body">
                    <x-ui.field label="Sort order" for="sort_order" name="sort_order" hint="Lower numbers show first.">
                        <x-ui.input id="sort_order" name="sort_order" class="fm-input--num" value="{{ old('sort_order', 0) }}" />
                    </x-ui.field>
                </div>
            </div>
        </div>
    </div>

    <x-ui.form-bar :cancel="route('manufacturers.index')">
        <x-slot:note>Nothing is saved until you create the manufacturer.</x-slot:note>
        <x-slot:primary><x-ui.button type="submit" variant="primary">Create manufacturer</x-ui.button></x-slot:primary>
    </x-ui.form-bar>
</form>
@endsection
