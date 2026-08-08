@extends('layouts.shell')
@section('title', 'New order status')
@section('breadcrumb', 'New')

@section('content')
<form method="POST" action="{{ route('order_statuses.store') }}" class="fm-page">
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
            <a class="fm-back" href="{{ route('order_statuses.index') }}">
                <x-ui.icon name="chevron-left" size="14" /> Order statuses
            </a>
            <h1 class="fm-title">New order status</h1>
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
                                    value="{{ old('name') }}" placeholder="Ready to ship" />
                    </x-ui.field>
                </div>
            </section>
        </div>

        <div class="fm-col fm-col--side">
            <div class="fm-card">
                <div class="fm-card__head"><h2 class="fm-card__title">What it does</h2></div>
                <div class="fm-card__body">
                    {{-- OrderStatusController@store reads these with
                         $request->has(), so a hidden 0 companion would make
                         has() true on every save and pin both flags on. A bare
                         checkbox, which submits nothing when off, is the only
                         correct control here. --}}
                    <label class="fm-switch">
                        <input type="checkbox" class="fm-switch__input" name="subtract_stock" value="1" @checked(old('subtract_stock'))>
                        <span class="fm-switch__track" aria-hidden="true"></span>
                        <span class="fm-switch__text">
                            <span class="fm-switch__label">Takes stock</span>
                            <span class="fm-switch__note">Stock comes off the product when an order reaches this status.</span>
                        </span>
                    </label>
                    @error('subtract_stock')<div class="fm-error">{{ $message }}</div>@enderror

                    <label class="fm-switch">
                        <input type="checkbox" class="fm-switch__input" name="add_revenue" value="1" @checked(old('add_revenue'))>
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

    <div class="fm-bar">
        <span class="fm-bar__meta">Nothing is saved until you create the status.</span>
        <div class="fm-bar__actions">
            <a class="fm-cancel" href="{{ route('order_statuses.index') }}">Cancel</a>
            <x-ui.button type="submit" variant="primary">Create status</x-ui.button>
        </div>
    </div>
</form>
@endsection
