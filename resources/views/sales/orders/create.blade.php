@extends('layouts.blotter')
@section('title', 'New order')
@section('breadcrumb', 'New order')

@section('content')
@php
    use App\Support\Money;

    $default = $currencies->firstWhere('is_default', 1);
    $defaultCode = Money::defaultCode();

    $currentCode = old('currency_code', $default->code ?? $defaultCode);
    $currentRate = old('currency_rate', 1);
    $currentId = $currencies->firstWhere('code', $currentCode)->id ?? ($default->id ?? 0);

    /**
     * Line items restored after a failed validation.
     *
     * old('products')[i]['price'] and ['cost'] are ORDER-currency figures:
     * the hidden inputs are written from the displayed price, which is
     * already divided by whatever rate was in the field at submit time. The
     * JS state below must hold DEFAULT-currency figures, because that is
     * what it divides by the current rate on every render. So they are
     * multiplied back up by that same old rate here.
     *
     * Skipping this multiply is a real bug with a name: a USD line submitted
     * at $100 comes back as 100 / rate (about $1.62) and, saved unchanged,
     * stores PHP 100 instead of PHP 6,161.
     */
    $oldRate = (float) old('currency_rate', 1);
    if ($oldRate <= 0) {
        $oldRate = 1;
    }

    $lines = collect(old('products', []))->values()->map(fn ($p) => [
        'product_id'      => (int) ($p['product_id'] ?? 0),
        'name'            => (string) ($p['name'] ?? ''),
        'model'           => (string) ($p['model'] ?? ''),
        'sku'             => (string) ($p['model'] ?? ''),
        'basePrice'       => (float) ($p['price'] ?? 0) * $oldRate,
        'baseCost'        => (float) ($p['cost'] ?? 0) * $oldRate,
        'qty'             => (int) ($p['quantity'] ?? 1),
        'option_value_id' => $p['option_value_id'] ?? null,
        'option_name'     => (string) ($p['option_name'] ?? ''),
        'option_value'    => (string) ($p['option_value'] ?? ''),
    ])->values();
@endphp

<form id="of-form" method="POST" action="{{ route('orders.store') }}" class="fm-page of-page"
      data-of-root
      data-search-url="{{ route('orders.search_products') }}"
      data-default-code="{{ $defaultCode }}"
      data-default-symbol="{{ Money::defaultSymbol() }}"
      data-lines="{{ $lines->toJson() }}">
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
            <a class="fm-back" href="{{ route('orders.index') }}">
                <x-ui.icon name="chevron-left" size="14" /> Orders
            </a>
            <h1 class="fm-title">New order</h1>
        </div>
    </div>

    <div class="fm-body">
        <div class="fm-col fm-col--main">
            @include('sales.orders.partials._parties', ['o' => null])
            @include('sales.orders.partials._lines', ['initialTotal' => 0])
        </div>

        <div class="fm-col fm-col--side">
            @include('sales.orders.partials._currency', [
                'currencies'  => $currencies,
                'currentCode' => $currentCode,
                'currentRate' => $currentRate,
                'currentId'   => $currentId,
            ])

            <div class="fm-card">
                <div class="fm-card__head"><h2 class="fm-card__title">Order</h2></div>
                <div class="fm-card__body">
                    <x-ui.field label="Status" for="of-status" name="order_status_id" :required="true"
                                hint="A status that takes stock will deduct it the moment this order is saved.">
                        <x-ui.select id="of-status" name="order_status_id">
                            @foreach($statuses as $s)
                                <option value="{{ $s->order_status_id }}" @selected((int) old('order_status_id', 1) === $s->order_status_id)>{{ $s->name }}</option>
                            @endforeach
                        </x-ui.select>
                    </x-ui.field>

                    <x-ui.field label="Comment" for="of-comment" name="comment"
                                hint="Kept with the order and shown on its first history entry.">
                        <x-ui.textarea id="of-comment" name="comment" rows="3">{{ old('comment') }}</x-ui.textarea>
                    </x-ui.field>
                </div>
            </div>
        </div>
    </div>

    <div class="fm-bar">
        <span class="fm-bar__meta">New order</span>
        <div class="fm-bar__actions">
            <a class="fm-cancel" href="{{ route('orders.index') }}">Cancel</a>
            <x-ui.button type="submit" variant="primary">Save order</x-ui.button>
        </div>
    </div>
</form>

@include('sales.orders.partials._picker')
@endsection
