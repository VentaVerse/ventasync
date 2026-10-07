@extends('layouts.blotter')
@section('title', 'New category')

@section('content')
<form method="POST" action="{{ route('categories.store') }}" class="fm-page" data-guard-unsaved>
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
            <a class="fm-back" href="{{ route('categories.index') }}" data-guard-leave>
                <x-ui.icon name="chevron-left" size="14" /> Categories
            </a>
            <h1 class="fm-title">New category</h1>
        </div>
    </div>

    <div class="fm-body">
        <div class="fm-col fm-col--main">

            <section class="fm-section">
                <div class="fm-section__head">
                    <h2 class="fm-section__title">General</h2>
                </div>

                <div class="fm-fields">
                    <x-ui.field label="Name" for="category_name" name="name" :required="true" wide>
                        <x-ui.input id="category_name" name="name" value="{{ old('name') }}" />
                    </x-ui.field>

                    <x-ui.field label="Parent category" for="parent_id" name="parent_id" wide
                                hint="Leave as No parent to put this at the top level.">
                        <div class="x-select-wrap">
                            <select id="parent_id" name="parent_id" class="x-input">
                                <option value="0">No parent</option>
                                @foreach($parents as $p)
                                    <option value="{{ $p->category_id }}" @selected((int) old('parent_id', 0) === (int) $p->category_id)>{{ $p->name }}</option>
                                @endforeach
                            </select>
                            <x-ui.icon name="chevron-down" size="14" class="x-select-wrap__chevron" />
                        </div>
                    </x-ui.field>

                    <x-ui.field label="Description" name="description" wide>
                        {{-- Escaped, not raw: a description containing </textarea> would break out of the control. --}}
                        <x-ui.textarea id="f-description" name="description" class="wysiwyg">{{ old('description', '') }}</x-ui.textarea>
                    </x-ui.field>
                </div>
            </section>
        </div>

        <div class="fm-col fm-col--side">

            <div class="fm-card">
                <div class="fm-card__head"><h2 class="fm-card__title">Details</h2></div>
                <div class="fm-card__body">
                    @php $statusOn = old('status', '1') === '1'; @endphp
                    <input type="hidden" name="status" value="0">
                    <label class="fm-switch">
                        <input type="checkbox" class="fm-switch__input" name="status" value="1" @checked($statusOn)>
                        <span class="fm-switch__track"></span>
                        <span class="fm-switch__text">
                            <span class="fm-switch__label">Enabled</span>
                            <span class="fm-switch__note">Off hides this category from the storefront.</span>
                        </span>
                    </label>
                    @error('status')<div class="fm-error">{{ $message }}</div>@enderror

                    <x-ui.field label="Sort order" for="sort_order" name="sort_order" hint="Lower numbers show first.">
                        <x-ui.input id="sort_order" name="sort_order" class="fm-input--num" value="{{ old('sort_order', 0) }}" />
                    </x-ui.field>
                </div>
            </div>
        </div>
    </div>

    <x-ui.form-bar :cancel="route('categories.index')">
        <x-slot:note>Nothing is saved until you create the category.</x-slot:note>
        <x-slot:primary><x-ui.button type="submit" variant="primary">Create category</x-ui.button></x-slot:primary>
    </x-ui.form-bar>
</form>

@include('catalog.products.partials.wysiwyg')
@endsection
