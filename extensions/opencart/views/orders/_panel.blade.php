@php
    $panelHiddenParams = $panelHiddenParams ?? [];

    $panelSelfUrl = function (array $params = [], array $except = []) use ($panelBaseUrl, $panelHiddenParams) {
        $query = array_merge(request()->except($except), $params, $panelHiddenParams);

        return $query === [] ? $panelBaseUrl : $panelBaseUrl . '?' . \Illuminate\Support\Arr::query($query);
    };

    $panelUrl = function (array $params = []) use ($panelBaseUrl, $panelHiddenParams) {
        $query = array_merge($panelHiddenParams, $params);

        return $query === [] ? $panelBaseUrl : $panelBaseUrl . '?' . \Illuminate\Support\Arr::query($query);
    };

    $canManageOcOrders = $canManageOcOrders
        ?? (auth()->user()?->hasPermission('manage_sales/order') ?? false);

    $canOpenOcOrders = $canOpenOcOrders ?? ($canManageOcOrders
        || (auth()->user()?->hasPermission('view_sales/order') ?? false));

    $openCartQ = (string) ($filters['q'] ?? '');
    $openCartStatusId = (int) ($filters['status'] ?? 0);
    $openCartHasFilters = $openCartQ !== '' || $openCartStatusId > 0;

    $openCartSortUrl = fn (string $col) => $panelSelfUrl(
        ['sort' => $col, 'dir' => ($sort === $col && $dir === 'asc') ? 'desc' : 'asc'],
        ['page']
    );
@endphp
<button type="button" class="x-desk-toggle" data-desk-toggle aria-expanded="true">
    <span data-desk-label>Find, dates and sync</span>
    <span class="x-desk-toggle__mark" aria-hidden="true">&#9662;</span>
</button>

<form method="GET" action="{{ $panelBaseUrl }}" class="x-filters" data-desk-tool id="orders-filter">
@foreach($panelHiddenParams as $panelHiddenKey => $panelHiddenValue)    <input type="hidden" name="{{ $panelHiddenKey }}" value="{{ $panelHiddenValue }}">
@endforeach    <input type="hidden" name="sort" value="{{ $sort }}">
    <input type="hidden" name="dir" value="{{ $dir }}">

    <x-ui.input type="search" name="q" class="x-filters__search"
                value="{{ $openCartQ }}" placeholder="Order no, customer, product or tracking" aria-label="Search orders" />

    <div class="x-select-wrap x-filters__select">
        <select name="status" class="x-input" data-autosubmit aria-label="Filter by status">
            <option value="0">All statuses</option>
            @foreach($statuses as $openCartS)
                <option value="{{ $openCartS->order_status_id }}" @selected($openCartStatusId === (int) $openCartS->order_status_id)>{{ $openCartS->name }}</option>
            @endforeach
        </select>
        <x-ui.icon name="chevron-down" size="14" class="x-select-wrap__chevron" />
    </div>

    <x-ui.button type="submit" variant="secondary">Search</x-ui.button>

    @if($openCartHasFilters)
        <a class="x-filters__reset" href="{{ $panelUrl() }}">Reset</a>
    @endif
</form>

@if($orders->count() === 0)
    @php
        $openCartEmptyDesc = $openCartHasFilters
            ? 'Nothing matches those filters. Clear them to see the full list.'
            : 'Nothing has synced from this storefront yet. Orders appear here as soon as the OpenCart sync brings them in.';

        $emptyFiltered = (isset($active_tab) && strtoupper((string) $active_tab) !== 'ALL')
            || collect(request()->except(array_merge(array_keys($panelHiddenParams ?? []), ['page'])))
            ->filter(fn ($v, $k) => $v !== null && $v !== '' && !($k === 'tab' && $v === 'ALL'))
            ->isNotEmpty();
    @endphp
    <x-ui.empty title="No orders here" :description="$openCartEmptyDesc">
        @if($emptyFiltered)
            <x-slot:action>
                <x-ui.button variant="secondary" :href="isset($active_tab) ? $panelUrl(['tab' => 'ALL']) : $panelUrl()">Show all orders</x-ui.button>
            </x-slot:action>
        @endif
    </x-ui.empty>
@else
<x-ui.table caption="OpenCart orders" class="x-table--stack">
    <x-slot:head>
        <tr>
            <th scope="col" class="oc-col-products">Products</th>
            <th scope="col" class="oc-col-qty">Qty</th>
            <th scope="col" class="oc-col-price x-td-num">Price</th>
            <th scope="col" class="oc-col-total x-td-num">
                <a class="x-th-sort x-th-sort--num" href="{{ $openCartSortUrl('total') }}">
                    Total
                    @if($sort === 'total')<x-ui.icon name="{{ $dir === 'asc' ? 'chevron-up' : 'chevron-down' }}" size="12" />@endif
                </a>
            </th>
            <th scope="col" class="oc-col-status">Status</th>
            <th scope="col" class="oc-col-act"><span class="x-sr">Actions</span></th>
        </tr>
    </x-slot:head>

    @foreach($orders as $o)
        @php
            $openCartCustomer = trim($o->firstname . ' ' . $o->lastname);
            $openCartExternal = trim((string) ($o->channel_order_id ?? ''));

            $openCartItems = $o->products ? $o->products->all() : [];
            $openCartRows = $openCartItems !== [] ? $openCartItems : [null];
            $openCartLineCount = count($openCartRows);
            $openCartItemCount = count($openCartItems);

            $openCartStatusName = $o->status->name ?? '';
            $openCartTone = \App\Support\OrderStatusTone::for($openCartStatusName);
        @endphp
        <tr class="co-band">
            <td colspan="6">
                <div class="co-band__row">
                    <div class="co-band__left">
                        <span class="co-band__label">Customer</span>
                        @if($openCartCustomer !== '')
                            <span class="co-band__value">{{ $openCartCustomer }}</span>
                        @else
                            <span class="co-empty-mark">Not recorded</span>
                        @endif
                        <span class="co-band__label">Order</span>
                        @if($openCartExternal !== '')
                            <span class="co-band__value co-band__value--mono">{{ $openCartExternal }}</span>
                        @else
                            <span class="co-empty-mark">No order number</span>
                        @endif
                        @if($openCartItemCount > 0)
                            <span class="co-band__muted">({{ $openCartItemCount }} {{ $openCartItemCount === 1 ? 'item' : 'items' }})</span>
                        @endif
                        @if($canOpenOcOrders)
                            <a class="co-ref" href="{{ route('orders.show', $o->order_id) }}">Sales order {{ $o->order_id }}</a>
                        @else
                            <span class="co-band__muted">Sales order {{ $o->order_id }}</span>
                        @endif
                    </div>
                    <div class="co-band__right">
                        <span class="co-band__label">Created</span>
                        <span class="co-band__value co-band__value--mono">{{ \Illuminate\Support\Carbon::parse($o->date_added)->format('Y-m-d H:i') }}</span>
                    </div>
                </div>
            </td>
        </tr>
        @foreach($openCartRows as $openCartIdx => $openCartItem)
            @php
                $openCartImgSrc = '';
                if ($openCartItem) {
                    $openCartPath = trim((string) ($productImages[$openCartItem->order_product_id] ?? ''));
                    if ($openCartPath !== '') {
                        $openCartImgSrc = \App\Services\Media\ImageCache::url($openCartPath);
                    } else {
                        $openCartImgSrc = trim((string) ($lazadaImages[$openCartItem->order_product_id] ?? ''));
                    }
                }
            @endphp
        <tr class="co-itemrow {{ $openCartIdx === $openCartLineCount - 1 ? 'co-itemrow--last' : '' }}">
            <td class="oc-col-products" data-label="Products">
                <div class="co-item">
                    <div class="order-img-wrap">
                        @if($openCartImgSrc !== '')
                            <img class="order-img" src="{{ $openCartImgSrc }}" alt="" loading="lazy" decoding="async">
                        @else
                            <span class="co-item__none">No image</span>
                        @endif
                    </div>
                    <div class="co-item__body">
                        <span class="co-item__name">{{ trim((string) ($openCartItem?->name ?? '')) !== '' ? $openCartItem->name : 'Unnamed product' }}</span>
                        <div class="co-item__meta">
                            @if(trim((string) ($openCartItem?->sku ?? '')) !== '')
                                <span class="co-item__sku">{{ $openCartItem->sku }}</span>
                            @endif
                        </div>
                    </div>
                </div>
            </td>

            <td class="oc-col-qty" data-label="Qty">
                <span class="co-qty">&times;{{ max(1, (int) ($openCartItem?->quantity ?? 1)) }}</span>
            </td>

            <td class="oc-col-price x-td-num" data-label="Price">
                @if($openCartItem && (float) $openCartItem->price > 0)
                    <span class="co-price">
                        <x-money :php="(float) $openCartItem->price" :foreign="$openCartItem->foreign_price" :foreign-code="$o->currency_code" />
                    </span>
                @else
                    <span class="co-empty-mark">-</span>
                @endif
            </td>

            @if($openCartIdx === 0)
            <td class="oc-col-total x-td-num" rowspan="{{ $openCartLineCount }}" data-label="Total">
                <span class="co-total">
                    <x-money :php="(float) $o->total" :foreign="$o->foreign_total" :foreign-code="$o->currency_code" />
                </span>
            </td>

            <td class="oc-col-status" rowspan="{{ $openCartLineCount }}" data-label="Status">
                <div class="co-status">
                    @if($openCartStatusName !== '')
                        <x-ui.badge :tone="$openCartTone">{{ $openCartStatusName }}</x-ui.badge>
                    @else
                        <span class="co-empty-mark">-</span>
                    @endif
                </div>
            </td>


            <td class="oc-col-act" rowspan="{{ $openCartLineCount }}">
                @if(!$canManageOcOrders)
                    <span class="co-empty-mark">-</span>
                @else
                <div class="co-actions">
                    <x-ui.menu label="Order {{ $o->order_id }} actions">
                        <a class="x-menu__item" href="{{ route('orders.edit', $o->order_id) }}">Edit</a>
                        <div class="x-menu__sep"></div>
                        <button type="button" class="x-menu__item x-menu__item--danger"
                                data-confirm="Delete order {{ $o->order_id }}? This cannot be undone."
                                data-confirm-submit="oc-del-{{ $o->order_id }}">Delete</button>
                        <form id="oc-del-{{ $o->order_id }}" method="POST"
                              action="{{ route('orders.destroy', $o->order_id) }}" class="x-sr">
                            @csrf
                            @method('DELETE')
                        </form>
                    </x-ui.menu>
                </div>
                @endif
            </td>
            @endif
        </tr>
        @endforeach
    @endforeach
</x-ui.table>

<x-ui.pager :paginator="$orders" />
@endif
