@extends('layouts.blotter')
@section('title', 'Order #' . $order->order_id)
@section('breadcrumb', '#' . $order->order_id)

@section('content')
@php
    use App\Support\Money;
    use Illuminate\Support\Str;

    $defaultCode = Money::defaultCode();

    $currentCode = old('currency_code', $order->currency_code ?: $defaultCode);
    $currentRate = old('currency_rate', $order->currency_value ?: 1);
    $currentId = $order->currency_id ?: ($currencies->firstWhere('code', $currentCode)->id ?? 0);

    $isForeign = strcasecmp((string) $currentCode, $defaultCode) !== 0;

    // Pass stored default-currency prices as-is; pre-converting here multiplied the order on save.
    $lines = $products->map(fn ($p) => [
        'product_id'      => (int) $p->product_id,
        'name'            => (string) $p->name,
        'model'           => (string) $p->model,
        'sku'             => (string) $p->model,
        'basePrice'       => (float) $p->price,
        'baseCost'        => (float) $p->cost,
        'qty'             => (int) $p->quantity,
        'option_value_id' => $p->options->first()->product_option_value_id ?? null,
        'option_name'     => (string) ($p->options->first()->name ?? ''),
        'option_value'    => (string) ($p->options->first()->value ?? ''),
    ])->values();
@endphp

<form id="of-form" method="POST" action="{{ route('orders.update', $order->order_id) }}" class="fm-page of-page"
      data-of-root
      data-search-url="{{ route('orders.search_products') }}"
      data-default-code="{{ $defaultCode }}"
      data-default-symbol="{{ Money::defaultSymbol() }}"
      data-lines="{{ $lines->toJson() }}">
    @csrf
    @method('PUT')

    <div class="fm-flash" role="status" aria-live="polite">
        @if(session('status'))
            <div class="fm-note fm-note--ok"><div class="fm-note__body">{{ session('status') }}</div></div>
        @endif
        @if(session('error'))
            <div class="fm-note fm-note--fail"><div class="fm-note__body">{{ session('error') }}</div></div>
        @endif
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
            <h1 class="fm-title">Order #{{ $order->order_id }}</h1>
            <div class="fm-stamps">
                <span class="fm-stamp">
                    <span class="fm-stamp__k">Placed</span>
                    <span class="fm-stamp__v">{{ $order->date_added }}</span>
                </span>
                <span class="fm-stamp">
                    <span class="fm-stamp__k">Total</span>
                    <span class="fm-stamp__v"><x-money :php="(float) $order->total" :foreign="$order->foreign_total !== null ? (float) $order->foreign_total : null" :foreign-code="$order->currency_code" /></span>
                </span>
                @if($order->tracking_number)
                <span class="fm-stamp">
                    <span class="fm-stamp__k">Tracking</span>
                    <span class="fm-stamp__v">{{ $order->tracking_number }}</span>
                </span>
                @endif
            </div>
        </div>

        <div class="fm-head__actions">
            <x-ui.button :href="route('orders.show', $order->order_id)">Open record</x-ui.button>
            <x-ui.menu label="Order #{{ $order->order_id }} actions">
                <button type="button" class="x-menu__item x-menu__item--danger"
                        data-confirm="Delete order #{{ $order->order_id }} and its history? Any stock this order took is not returned. This cannot be undone."
                        data-confirm-submit="of-delete">Delete order</button>
            </x-ui.menu>
        </div>
    </div>

    <div class="fm-body">
        <div class="fm-col fm-col--main">
            @include('sales.orders.partials._parties', ['o' => $order])
            @include('sales.orders.partials._lines', ['initialTotal' => $order->total])

            @if($orderTotals->count())
            <section class="fm-section">
                <div class="fm-section__head">
                    <h2 class="fm-section__title">Totals as last saved</h2>
                </div>
                <p class="fm-section__note">
                    What the server recorded the last time this order was written, in {{ $defaultCode }}.
                    Editing lines above rewrites these on save.
                </p>
                <dl class="of-totals">
                    @foreach($orderTotals as $ot)
                        <div class="of-totals__row">
                            <dt>{{ $ot->title }}</dt>
                            <dd class="x-num">{{ Money::base((float) $ot->value) }}</dd>
                        </div>
                    @endforeach
                </dl>
            </section>
            @endif

            <section class="fm-section">
                <div class="fm-section__head">
                    <h2 class="fm-section__title">History</h2>
                </div>

                @if($history->count())
                    <ol class="of-hist">
                        @foreach($history as $h)
                            <li class="of-hist__item">
                                <span class="of-hist__when x-num">{{ $h->date_added }}</span>
                                <span class="of-hist__what">
                                    <span class="of-hist__status">{{ $h->status->name ?? 'Status not recorded' }}</span>
                                    <span class="of-hist__who">{{ $h->user_name ?: 'The system' }}</span>
                                </span>
                                @if($h->comment)
                                    <span class="of-hist__note">{{ $h->comment }}</span>
                                @endif
                            </li>
                        @endforeach
                    </ol>
                @else
                    <p class="fm-hint">Nothing recorded yet. An entry is written every time this order is saved.</p>
                @endif
            </section>
        </div>

        <div class="fm-col fm-col--side">
            <div class="fm-card">
                <div class="fm-card__head"><h2 class="fm-card__title">Order</h2></div>
                <div class="fm-card__body">
                    <x-ui.field label="Status" for="of-status" name="order_status_id"
                                hint="Moving to or from a status that takes stock adjusts inventory on save.">
                        <x-ui.select id="of-status" name="order_status_id" data-status-select data-current-subtract="{{ (int) ($order->status->subtract_stock ?? 0) }}">
                            @include('sales.orders.partials._status_options', ['statuses' => $statuses, 'selected' => (int) old('order_status_id', $order->order_status_id)])
                        </x-ui.select>
                        <p class="fm-hint" data-status-note hidden></p>
                    </x-ui.field>

                    <x-ui.field label="Tracking number" for="of-tracking" name="tracking_number">
                        <x-ui.input id="of-tracking" name="tracking_number" maxlength="32" class="fm-input--num"
                                    value="{{ old('tracking_number', $order->tracking_number) }}" />
                    </x-ui.field>

                    <x-ui.field label="Comment" for="of-comment" name="comment"
                                hint="Added to the history entry this save writes.">
                        <x-ui.textarea id="of-comment" name="comment" rows="3">{{ old('comment', $order->comment) }}</x-ui.textarea>
                    </x-ui.field>
                </div>
            </div>

            @include('sales.orders.partials._currency', [
                'currencies'  => $currencies,
                'currentCode' => $currentCode,
                'currentRate' => $currentRate,
                'currentId'   => $currentId,
            ])

            @if($isForeign)
            <div class="fm-card">
                <div class="fm-card__head"><h2 class="fm-card__title">As transacted</h2></div>
                <div class="fm-card__body">
                    <p class="fm-hint">
                        This order was placed in {{ $order->currency_code }} at a rate of
                        {{ rtrim(rtrim(number_format((float) $order->currency_value, 8, '.', ''), '0'), '.') }}.
                        Changing the rate here re-converts every line and rewrites what is stored,
                        so change it only if the recorded rate was wrong.
                    </p>
                </div>
            </div>
            @endif

        </div>
    </div>

    <x-ui.form-bar :cancel="route('orders.index')">
        <x-slot:note>Saves the lines, status and comment. A status that takes stock moves it on save.</x-slot:note>
        <x-slot:primary><x-ui.button type="submit" variant="primary">Save order</x-ui.button></x-slot:primary>
    </x-ui.form-bar>
</form>

<form id="of-delete" method="POST" action="{{ route('orders.destroy', $order->order_id) }}" class="x-sr">
    @csrf
    @method('DELETE')
</form>

@include('sales.orders.partials._picker')
@endsection
