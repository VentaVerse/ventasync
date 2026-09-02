@extends('layouts.blotter')
{{--
    Both sections below pass a value directly (the inline `@section('name',
    $value)` form), which Laravel escapes for you -
    ManagesLayouts::startSection() runs e($content) on anything that is not
    a View before storing it. Wrapping the value in e() here a second time
    was tried and reverted: confirmed via curl against a category named
    "Tents & Awnings <b>Sale</b>" that it rendered as literal "&amp;amp;
    Awnings &amp;lt;b&amp;gt;..." in the <title>, double-encoded. One
    escape, from the framework, is correct and sufficient here; only
    html_entity_decode() is applied first, since this schema's category
    names sometimes arrive already HTML-entity-encoded from legacy imports.
--}}
@section('title', 'Edit ' . html_entity_decode($category->name ?: 'category', ENT_QUOTES | ENT_HTML5, 'UTF-8'))
@section('breadcrumb', html_entity_decode($category->name ?: ('Category ' . $category->category_id), ENT_QUOTES | ENT_HTML5, 'UTF-8'))

@section('content')
@php $backUrl = old('_return', $returnUrl ?? null) ?: route('categories.index'); @endphp
<form method="POST" action="{{ route('categories.update', $category->category_id) }}" class="fm-page">
    <input type="hidden" name="_return" value="{{ $backUrl }}">
    @csrf
    @method('PUT')

    <div class="fm-flash" role="status" aria-live="polite">
        @if(session('status'))
            <div class="fm-note fm-note--ok"><div class="fm-note__body">{{ session('status') }}</div></div>
        @endif
        @if($errors->any())
            <div class="fm-note fm-note--fail">
                <div class="fm-note__body">Nothing was saved. Check the fields marked below.</div>
            </div>
        @endif
    </div>

    <div class="fm-head">
        <div class="fm-head__main">
            <a class="fm-back" href="{{ $backUrl }}">
                <x-ui.icon name="chevron-left" size="14" /> Categories
            </a>
            <h1 class="fm-title">{{ html_entity_decode($category->name ?: 'Untitled category', ENT_QUOTES | ENT_HTML5, 'UTF-8') }}</h1>
            <div class="fm-stamps">
                <span class="fm-stamp">
                    <span class="fm-stamp__k">ID</span>
                    <span class="fm-stamp__v">{{ $category->category_id }}</span>
                </span>
            </div>
        </div>

        <div class="fm-head__actions">
            <x-ui.menu label="Category {{ $category->category_id }} actions">
                {{-- Destroy is never a standing control: it sits behind the
                     overflow menu and the shared confirm dialog, and drives a
                     form that lives outside this one (forms cannot nest). --}}
                <button type="button" class="x-menu__item x-menu__item--danger"
                        data-confirm="Delete {{ html_entity_decode($category->name ?: 'this category', ENT_QUOTES | ENT_HTML5, 'UTF-8') }}? This cannot be undone."
                        data-confirm-submit="category-delete-form">Delete category</button>
            </x-ui.menu>
        </div>
    </div>

    <div class="fm-body">
        <div class="fm-col fm-col--main">

            {{-- ---------------------------------------------------------
                 General
                 --------------------------------------------------------- --}}
            <section class="fm-section">
                <div class="fm-section__head">
                    <h2 class="fm-section__title">General</h2>
                </div>

                <div class="fm-fields">
                    <x-ui.field label="Name" for="category_name" name="name" :required="true" wide>
                        <x-ui.input id="category_name" name="name" value="{{ old('name', html_entity_decode($category->name ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8')) }}" />
                    </x-ui.field>

                    <x-ui.field label="Parent category" for="parent_id" name="parent_id" wide
                                hint="Leave as No parent to put this at the top level.">
                        <div class="x-select-wrap">
                            <select id="parent_id" name="parent_id" class="x-input">
                                <option value="0">No parent</option>
                                @foreach($parents as $p)
                                    <option value="{{ $p->category_id }}" @selected((int) old('parent_id', $category->parent_id) === (int) $p->category_id)>{{ $p->name }}</option>
                                @endforeach
                            </select>
                            <x-ui.icon name="chevron-down" size="14" class="x-select-wrap__chevron" />
                        </div>
                    </x-ui.field>

                    <x-ui.field label="Description" name="description" wide>
                        {{-- Escaped, not raw: a textarea's contents are character
                             data, so escaping is what makes the stored HTML
                             round-trip intact instead of letting a description
                             containing "</textarea>" break out of the control.
                             Same fix catalog/products carries. --}}
                        <x-ui.textarea id="f-description" name="description" class="wysiwyg">{{ old('description', $category->description) }}</x-ui.textarea>
                    </x-ui.field>
                </div>
            </section>
        </div>

        <div class="fm-col fm-col--side">

            {{-- Details --------------------------------------------------- --}}
            <div class="fm-card">
                <div class="fm-card__head"><h2 class="fm-card__title">Details</h2></div>
                <div class="fm-card__body">
                    {{-- CategoryController@update validates status as
                         `required|in:0,1`. A bare checkbox submits nothing
                         when off and would fail that rule, so the switch is
                         backed by a hidden 0. --}}
                    @php $statusOn = old('status', (string) $category->status) === '1'; @endphp
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
                        <x-ui.input id="sort_order" name="sort_order" class="fm-input--num" value="{{ old('sort_order', $category->sort_order) }}" />
                    </x-ui.field>
                </div>
            </div>
        </div>
    </div>

    <div class="fm-bar">
        <span class="fm-bar__meta">Category {{ $category->category_id }}</span>
        <div class="fm-bar__actions">
            <a class="fm-cancel" href="{{ $backUrl }}">Cancel</a>
            <x-ui.button type="submit" variant="primary">Save changes</x-ui.button>
        </div>
    </div>
</form>

<form id="category-delete-form" method="POST" action="{{ route('categories.destroy', $category->category_id) }}" class="x-sr">
    @csrf
    @method('DELETE')
</form>

@include('catalog.products.partials.wysiwyg')
@endsection
