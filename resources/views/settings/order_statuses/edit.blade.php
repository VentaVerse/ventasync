@extends('layouts.blotter')
@section('title', 'Edit ' . $orderStatus->name)
@section('breadcrumb', $orderStatus->name)

@section('content')
<form method="POST" action="{{ route('order_statuses.update', $orderStatus->order_status_id) }}" class="fm-page" data-guard-unsaved>
    @csrf
    @method('PUT')

    @include('partials.flash')

    @if($errors->any())
        <div class="fm-flash" role="status" aria-live="polite">
            <div class="fm-note fm-note--fail">
                <div class="fm-note__body">Nothing was saved. Check the fields marked below.</div>
            </div>
        </div>
    @endif

    <div class="fm-head">
        <div class="fm-head__main">
            <a class="fm-back" href="{{ route('order_statuses.index') }}" data-guard-leave>
                <x-ui.icon name="chevron-left" size="14" /> Order statuses
            </a>
            <h1 class="fm-title">{{ $orderStatus->name }}</h1>
            <div class="fm-stamps">
                <span class="fm-stamp">
                    <span class="fm-stamp__k">ID</span>
                    <span class="fm-stamp__v">{{ $orderStatus->order_status_id }}</span>
                </span>
            </div>
        </div>

        <div class="fm-head__actions">
            <x-ui.menu label="{{ $orderStatus->name }} actions">
                <button type="button" class="x-menu__item x-menu__item--danger"
                        data-confirm="Delete {{ $orderStatus->name }}? Any channel that maps a marketplace status onto it stops resolving to a name. This cannot be undone."
                        data-confirm-submit="st-os-delete-form">Delete status</button>
            </x-ui.menu>
        </div>
    </div>

    <div class="fm-body">
        <div class="fm-col fm-col--main">
            <section class="fm-section">
                <div class="fm-section__head">
                    <h2 class="fm-section__title">Name</h2>
                </div>

                <div class="fm-fields">
                    <x-ui.field label="Name" for="st-os-name" name="name" :required="true" wide
                                hint="Up to 32 characters. This is what operators see on the order.">
                        <x-ui.input id="st-os-name" name="name" maxlength="32" required
                                    value="{{ old('name', $orderStatus->name) }}" />
                    </x-ui.field>
                </div>
            </section>
        </div>

        <div class="fm-col fm-col--side">
            <div class="fm-card">
                <div class="fm-card__head"><h2 class="fm-card__title">What it does</h2></div>
                <div class="fm-card__body">
                    <label class="fm-switch">
                        <input type="checkbox" class="fm-switch__input" name="subtract_stock" value="1" @checked(old('subtract_stock', $orderStatus->subtract_stock))>
                        <span class="fm-switch__track" aria-hidden="true"></span>
                        <span class="fm-switch__text">
                            <span class="fm-switch__label">Subtract stock</span>
                            <span class="fm-switch__note">Stock comes off the product when an order reaches this status.</span>
                        </span>
                    </label>
                    @error('subtract_stock')<div class="fm-error">{{ $message }}</div>@enderror

                    <label class="fm-switch">
                        <input type="checkbox" class="fm-switch__input" name="add_revenue" value="1" @checked(old('add_revenue', $orderStatus->add_revenue))>
                        <span class="fm-switch__track" aria-hidden="true"></span>
                        <span class="fm-switch__text">
                            <span class="fm-switch__label">Counts as revenue</span>
                            <span class="fm-switch__note">Orders at this status are included in sales totals and reports.</span>
                        </span>
                    </label>
                    @error('add_revenue')<div class="fm-error">{{ $message }}</div>@enderror
                </div>
            </div>
        </div>
    </div>

    <x-ui.form-bar :cancel="route('order_statuses.index')">
        <x-slot:note>Status {{ $orderStatus->order_status_id }}</x-slot:note>
        <x-slot:primary><x-ui.button type="submit" variant="primary">Save changes</x-ui.button></x-slot:primary>
    </x-ui.form-bar>
</form>

<form id="st-os-delete-form" method="POST" action="{{ route('order_statuses.destroy', $orderStatus->order_status_id) }}" class="x-sr">
    @csrf
    @method('DELETE')
</form>
@endsection
