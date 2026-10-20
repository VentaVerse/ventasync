<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Models\Catalog\Order;
use App\Models\Catalog\OrderHistory;
use App\Models\Catalog\OrderProduct;
use App\Models\Catalog\OrderStatus;
use App\Models\Catalog\Product;
use App\Channels\ChannelRegistry;
use App\Models\OrderPayment;
use App\Services\ActivityLogger;
use App\Services\OrderCurrencyService;
use App\Services\OrderStockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Support\LineCost;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class OrderController extends Controller
{
    private function currentUser(): array
    {
        $user = Auth::user();
        return [
            'user_id'   => $user?->id,
            'user_name' => $user ? ($user->name ?? $user->username ?? 'User #' . $user->id) : null,
        ];
    }

    public function index(Request $request)
    {
        $q = trim((string) $request->get('q', ''));
        $statusId = (int) $request->get('status', 0);
        $source = trim((string) $request->get('source', ''));

        $sortable = ['order_id', 'date_added', 'total', 'firstname'];
        $sort = in_array($request->get('sort'), $sortable) ? $request->get('sort') : 'date_added';
        $dir = $request->get('dir') === 'asc' ? 'asc' : 'desc';

        $orders = Order::query()
            ->with(['products', 'latestHistory'])
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($sub) use ($q) {
                    $sub->where('firstname', 'like', '%' . $q . '%')
                        ->orWhere('lastname', 'like', '%' . $q . '%')
                        ->orWhere('email', 'like', '%' . $q . '%')
                        ->orWhere('channel_order_id', 'like', '%' . $q . '%')
                        ->orWhere('tracking_number', 'like', '%' . $q . '%')
                        ->orWhereHas('products', function ($p) use ($q) {
                            $p->where('name', 'like', '%' . $q . '%')->orWhere('sku', 'like', '%' . $q . '%');
                        })
                        ->orWhere('order_id', '=', (int) $q > 0 ? (int) $q : 0);
                });
            })
            ->when($statusId > 0, function ($query) use ($statusId) {
                $query->where('order_status_id', $statusId);
            })
            ->when($source !== '', function ($query) use ($source) {
                if ($source === 'manual') {
                    $query->where('channel', '');
                } else {
                    $query->where('channel', $source);
                }
            })
            ->orderBy($sort, $dir)
            ->paginate(50)
            ->withQueryString();

        $productImages = self::resolveOrderProductImages($orders->getCollection());
        $lazadaImages = self::resolveLazadaImages($orders->getCollection());

        $statuses = OrderStatus::orderBy('order_status_id')->get();

        $sourceOptions = app(\App\Channels\ChannelRegistry::class)
            ->availableChannelOptions();
        $sourceLabelsMap = [];
        foreach ($sourceOptions as $opt) {
            $sourceLabelsMap[$opt['value']] = $opt;
        }

        return view('sales.orders.index-v2', compact('orders', 'q', 'statusId', 'statuses', 'source', 'productImages', 'lazadaImages', 'sort', 'dir', 'sourceOptions', 'sourceLabelsMap'));
    }

    public function create()
    {
        $statuses = OrderStatus::orderBy('order_status_id')->get();
        $currencies = DB::table('currencies')->where('status', 1)->orderByDesc('is_default')->orderBy('code')->get();

        return view('sales.orders.create', compact('statuses', 'currencies'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'firstname'        => 'required|string|max:32',
            'lastname'         => 'nullable|string|max:32',
            'email'            => 'nullable|string|max:96',
            'telephone'        => 'nullable|string|max:32',
            'customer_tin'     => 'nullable|string|max:32',
            'payment_method'   => 'nullable|string|max:128',
            'shipping_method'  => 'nullable|string',
            'total'            => 'nullable|numeric|min:0',
            'order_status_id'  => 'required|integer|min:0',
            'customer_note'    => 'nullable|string',
            'currency_code'    => ['nullable', 'string', 'max:3', Rule::exists('currencies', 'code')],
            'currency_rate'    => 'nullable|numeric|gt:0',
        ]);

        $now = now();

        $currencySvc = app(OrderCurrencyService::class);
        $currency = $currencySvc->resolve(
            $request->currency_code ?? $currencySvc->defaultCode(),
            $request->filled('currency_rate') ? (float) $request->currency_rate : null
        );
        $rate = $currency['rate'];
        $isDefault = $currency['is_default'];

        $rawTotal = (float) ($request->total ?? 0);

        DB::transaction(function () use ($request, $now, $rawTotal, $rate, $isDefault, $currency) {
            $order = Order::create([
                'store_id'             => 0,
                'store_name'           => '',
                'firstname'            => $request->firstname,
                'lastname'             => $request->lastname ?? '',
                'email'                => $request->email ?? '',
                'telephone'            => $request->telephone ?? '',
                'customer_tin'         => trim((string) $request->customer_tin),
                'payment_firstname'    => $request->payment_firstname ?? $request->firstname,
                'payment_lastname'     => $request->payment_lastname ?? ($request->lastname ?? ''),
                'payment_company'      => $request->payment_company ?? '',
                'payment_address_1'    => $request->payment_address_1 ?? '',
                'payment_address_2'    => $request->payment_address_2 ?? '',
                'payment_city'         => $request->payment_city ?? '',
                'payment_postcode'     => $request->payment_postcode ?? '',
                'payment_country'      => $request->payment_country ?? '',
                'payment_zone'         => $request->payment_zone ?? '',
                'payment_method'       => $request->payment_method ?? '',
                'payment_cost'         => 0,
                'shipping_firstname'   => $request->shipping_firstname ?? $request->firstname,
                'shipping_lastname'    => $request->shipping_lastname ?? ($request->lastname ?? ''),
                'shipping_company'     => $request->shipping_company ?? '',
                'shipping_address_1'   => $request->shipping_address_1 ?? '',
                'shipping_address_2'   => $request->shipping_address_2 ?? '',
                'shipping_city'        => $request->shipping_city ?? '',
                'shipping_postcode'    => $request->shipping_postcode ?? '',
                'shipping_country'     => $request->shipping_country ?? '',
                'shipping_zone'        => $request->shipping_zone ?? '',
                'shipping_method'      => $request->shipping_method ?? '',
                'shipping_cost'        => 0,
                'customer_note'        => $request->customer_note ?? '',
                'total'                => OrderCurrencyService::toBase($rawTotal, $rate),
                'foreign_total'        => $isDefault ? null : round($rawTotal, 4),
                'extra_cost'           => 0,
                'order_status_id'      => $request->order_status_id,
                'currency_id'          => $currency['id'],
                'currency_code'        => $currency['code'],
                'currency_value'       => $currency['rate'],
                'ip'                   => $request->ip() ?? '',
                'courier_id'           => 0,
                'tracking_number'      => '',
                'date_added'           => $now,
                'date_modified'        => $now,
            ]);

            OrderHistory::create(array_merge($this->currentUser(), [
                'order_id'        => $order->order_id,
                'order_status_id' => $request->order_status_id,
                'notify'          => 0,
                'note'            => $request->customer_note ?? '',
                'date_added'      => $now,
            ]));

            ActivityLogger::log('created', 'Order', (int) $order->order_id, '#' . $order->order_id . ' ' . trim($request->firstname . ' ' . $request->lastname));

            $products = $request->input('products', []);
            if (!empty($products)) {
                $orderTotal = 0;
                $orderForeignTotal = 0;

                foreach ($products as $p) {
                    $qty = (int) ($p['quantity'] ?? 1);
                    $foreignPrice = (float) ($p['price'] ?? 0);
                    $foreignTotal = $foreignPrice * $qty;
                    $foreignCost = (float) ($p['cost'] ?? 0);

                    $price = OrderCurrencyService::toBase($foreignPrice, $rate);
                    $lineTotal = OrderCurrencyService::toBase($foreignTotal, $rate);
                    $cost = OrderCurrencyService::toBase($foreignCost, $rate);

                    $orderTotal += $lineTotal;
                    $orderForeignTotal += $foreignTotal;

                    $orderProductId = DB::table('order_products')->insertGetId([
                        'order_id'      => $order->order_id,
                        'product_id'    => (int) ($p['product_id'] ?? 0),
                        'name'          => $p['name'] ?? '',
                        'sku'           => $p['sku'] ?? '',
                        'quantity'      => $qty,
                        'price'         => $price,
                        'foreign_price' => $isDefault ? null : round($foreignPrice, 4),
                        'total'         => $lineTotal,
                        'foreign_total' => $isDefault ? null : round($foreignTotal, 4),
                        'cost'          => $cost,
                    ]);

                    $povId = (int) ($p['variation_value_id'] ?? 0);
                    if ($povId > 0 && $orderProductId) {
                        $pov = DB::table('product_variation_values')
                            ->where('product_variation_value_id', $povId)
                            ->first(['product_variation_type_id', 'variation_value_id']);

                        DB::table('order_variations')->insert([
                            'order_id'                 => $order->order_id,
                            'order_product_id'         => $orderProductId,
                            'product_variation_type_id'        => $pov->product_variation_type_id ?? 0,
                            'product_variation_value_id'  => $povId,
                            'name'                     => $p['variation_type_name'] ?? '',
                            'value'                    => $p['variation_value'] ?? '',
                            'type'                     => 'select',
                        ]);
                    }
                }

                $order->update([
                    'total'         => $orderTotal,
                    'foreign_total' => $isDefault ? null : round($orderForeignTotal, 4),
                ]);

                DB::table('order_totals')->insert([
                    'order_id'   => $order->order_id,
                    'code'       => 'sub_total',
                    'title'      => 'Sub-Total',
                    'value'      => $orderTotal,
                    'sort_order' => 1,
                ]);
                DB::table('order_totals')->insert([
                    'order_id'   => $order->order_id,
                    'code'       => 'total',
                    'title'      => 'Total',
                    'value'      => $orderTotal,
                    'sort_order' => 9,
                ]);
            }

            $order->load('products');
            OrderStockService::adjustStock($order, 0, (int) $request->order_status_id);
        });

        return redirect()->route('orders.index')->with('status', 'Order created.');
    }

    public function searchProducts(Request $request)
    {
        $langId = (int) config('catalog.default_language_id');
        $q = trim((string) $request->input('q'));

        if (strlen($q) < 2) {
            return response()->json(['items' => []]);
        }

        $rows = DB::table('products as p')
            ->join('product_descriptions as pd', function ($j) use ($langId) {
                $j->on('p.product_id', '=', 'pd.product_id')
                    ->where('pd.language_id', '=', $langId);
            })
            ->where(function ($w) use ($q) {
                $w->where('pd.name', 'like', "%{$q}%")
                    ->orWhere('p.sku', 'like', "%{$q}%");
            })
            ->select('p.product_id', 'pd.name', 'p.sku', 'p.price', 'p.cost', 'p.quantity', 'p.image')
            ->orderBy('p.product_id', 'desc')
            ->limit(30)
            ->get();

        $productIds = $rows->pluck('product_id')->toArray();
        $combinationsByProduct = [];
        $variationValuesByProduct = [];

        if (!empty($productIds)) {
            $combos = DB::table('product_variations as poc')
                ->whereIn('poc.product_id', $productIds)
                ->select('poc.id', 'poc.product_id', 'poc.sku', 'poc.quantity', 'poc.absolute_price', 'poc.absolute_cost', 'poc.cost_amount')
                ->orderBy('poc.sort_order')
                ->get();

            if ($combos->isNotEmpty()) {
                $comboIds = $combos->pluck('id')->toArray();
                $comboValues = DB::table('product_variation_parts as pocv')
                    ->join('product_variation_values as pov', 'pocv.product_variation_value_id', '=', 'pov.product_variation_value_id')
                    ->join('variation_value_descriptions as ovd', function ($j) use ($langId) {
                        $j->on('pov.variation_value_id', '=', 'ovd.variation_value_id')
                            ->where('ovd.language_id', '=', $langId);
                    })
                    ->whereIn('pocv.product_variation_id', $comboIds)
                    ->select('pocv.product_variation_id', 'ovd.name as value_name')
                    ->get()
                    ->groupBy('product_variation_id');

                $comboPovMap = DB::table('product_variation_parts')
                    ->whereIn('product_variation_id', $comboIds)
                    ->get(['product_variation_id', 'product_variation_value_id'])
                    ->groupBy('product_variation_id');

                foreach ($combos as $c) {
                    $valueNames = ($comboValues->get($c->id) ?? collect())->pluck('value_name')->implode(' / ');
                    $povIds = ($comboPovMap->get($c->id) ?? collect())->pluck('product_variation_value_id')->toArray();
                    $combinationsByProduct[$c->product_id][] = (object) [
                        'product_variation_id'          => $c->id,
                        'product_variation_value_id' => $povIds[0] ?? null,
                        'product_id'              => $c->product_id,
                        'variation_sku'              => $c->sku,
                        'variation_qty'              => (int) $c->quantity,
                        'absolute_price'          => (float) $c->absolute_price,
                        'absolute_cost'           => LineCost::ownCombinationCost($c) ?? 0.0,
                        'variation_price'            => 0,
                        'price_prefix'            => '+',
                        'variation_cost'             => 0,
                        'cost_prefix'             => '+',
                        'value_name'              => $valueNames,
                        'variation_type_name'             => 'Variation',
                        'is_combination'          => true,
                    ];
                }
            }

            $optRows = DB::table('product_variation_values as pov')
                ->join('variation_value_descriptions as ovd', function ($j) use ($langId) {
                    $j->on('pov.variation_value_id', '=', 'ovd.variation_value_id')
                        ->where('ovd.language_id', '=', $langId);
                })
                ->join('variation_type_descriptions as od', function ($j) use ($langId) {
                    $j->on('pov.variation_type_id', '=', 'od.variation_type_id')
                        ->where('od.language_id', '=', $langId);
                })
                ->whereIn('pov.product_id', $productIds)
                ->select(
                    'pov.product_variation_value_id', 'pov.product_id',
                    'pov.sku as variation_sku', 'pov.quantity as variation_qty',
                    'pov.price as variation_price', 'pov.price_prefix',
                    'pov.cost as variation_cost', 'pov.cost_prefix',
                    'pov.absolute_price', 'pov.absolute_cost',
                    'pov.cost_amount', 'pov.cost_percentage', 'pov.cost_additional',
                    'ovd.name as value_name', 'od.name as variation_type_name'
                )
                ->orderBy('pov.product_variation_value_id')
                ->get();

            foreach ($optRows as $o) {
                $o->is_combination = false;
                $o->absolute_cost = LineCost::ownCost($o) ?? 0.0;
                unset($o->cost_amount, $o->cost_percentage, $o->cost_additional);
                $variationValuesByProduct[$o->product_id][] = $o;
            }
        }

        $items = $rows->map(function ($p) use ($combinationsByProduct, $variationValuesByProduct) {
            $p->variations = $combinationsByProduct[$p->product_id]
                ?? $variationValuesByProduct[$p->product_id]
                ?? [];
            return $p;
        });

        return response()->json(['items' => $items]);
    }

    public function show($id)
    {
        $order = Order::where('order_id', (int) $id)->firstOrFail();
        $statuses = OrderStatus::orderBy('order_status_id')->get();
        $history = OrderHistory::where('order_id', (int) $id)
            ->orderByDesc('date_added')
            ->get();
        $products = $order->products()->with('variations')->get();
        $orderTotals = $order->totals()->orderBy('sort_order')->get();

        $catalogProducts = self::resolveOrderCatalogProducts($products);
        $orderLineImages = self::resolveLazadaImages(collect([$order]));

        $orderProfit = \App\Services\Orders\OrderProfit::of($order);

        $payments = OrderPayment::where('order_id', (int) $id)->orderBy('paid_at')->get();
        $totalPaid = $payments->sum('amount');

        $defaultCurrency = \App\Models\Currency::where('is_default', 1)->first();

        $invoice = \App\Models\Invoice::where('order_id', $order->order_id)->first();
        $nextInvoice = $invoice ? null : app(\App\Services\Sales\InvoiceNumbers::class)->preview();

        return view('sales.orders.show', compact('order', 'statuses', 'history', 'products', 'orderTotals', 'catalogProducts', 'orderLineImages', 'orderProfit', 'payments', 'totalPaid', 'defaultCurrency', 'invoice', 'nextInvoice'));
    }

    public function updateStatus(Request $request, $id)
    {
        $request->validate([
            'order_status_id' => 'required|integer|min:1',
            'note'            => 'nullable|string',
        ]);

        $order = Order::where('order_id', (int) $id)->firstOrFail();
        $oldStatusId = (int) $order->order_status_id;
        $newStatusId = (int) $request->order_status_id;

        $order->update([
            'order_status_id' => $newStatusId,
            'date_modified'   => now(),
        ]);

        OrderHistory::create(array_merge($this->currentUser(), [
            'order_id'        => $order->order_id,
            'order_status_id' => $newStatusId,
            'notify'          => 0,
            'note'            => $request->note ?? '',
            'date_added'      => now(),
        ]));

        if ($oldStatusId !== $newStatusId) {
            OrderStockService::adjustStock($order, $oldStatusId, $newStatusId);
        }

        $oldStatusName = OrderStatus::where('order_status_id', $oldStatusId)->value('name') ?? $oldStatusId;
        $newStatusName = OrderStatus::where('order_status_id', $newStatusId)->value('name') ?? $newStatusId;
        ActivityLogger::log(
            'updated',
            'Order',
            (int) $order->order_id,
            '#' . $order->order_id . ' ' . trim($order->firstname . ' ' . $order->lastname),
            ['status' => [(string) $oldStatusName, (string) $newStatusName]]
        );

        return redirect()->route('orders.show', $id)->with('status', 'Order status updated.');
    }

    public function toggleOverride($id)
    {
        $order = Order::where('order_id', (int) $id)->firstOrFail();
        $new = (int) ($order->sync_override ?? 0) === 1 ? 0 : 1;
        $order->sync_override = $new;
        $order->save();

        ActivityLogger::log(
            'updated',
            'Order',
            $order->order_id,
            '#' . $order->order_id,
            ['sync_override' => [(string) ($new === 1 ? 0 : 1), (string) $new]]
        );

        return redirect()->route('orders.show', $id)->with(
            'status',
            $new === 1
                ? 'Sync Override ON - this order is now manually controlled; channel syncs won\'t change its status or stock.'
                : 'Sync Override OFF - the channel sync will manage this order again.'
        );
    }

    public function edit($id)
    {
        $order = Order::where('order_id', (int) $id)->firstOrFail();
        $statuses = OrderStatus::orderBy('order_status_id')->get();
        $currencies = DB::table('currencies')->where('status', 1)->orderByDesc('is_default')->orderBy('code')->get();
        $history = OrderHistory::where('order_id', (int) $id)
            ->orderByDesc('date_added')
            ->get();
        $products = $order->products()->with('variations')->get();
        $orderTotals = $order->totals()->orderBy('sort_order')->get();

        // Pass price and cost as stored (default currency); the page converts them, pre-converting breaks currency switching.

        return view('sales.orders.edit', compact('order', 'statuses', 'currencies', 'history', 'products', 'orderTotals'));
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'firstname'        => 'required|string|max:32',
            'lastname'         => 'nullable|string|max:32',
            'email'            => 'nullable|string|max:96',
            'telephone'        => 'nullable|string|max:32',
            'customer_tin'     => 'nullable|string|max:32',
            'payment_method'   => 'nullable|string|max:128',
            'shipping_method'  => 'nullable|string',
            'total'            => 'nullable|numeric|min:0',
            'order_status_id'  => 'required|integer|min:0',
            'customer_note'    => 'nullable|string',
            'currency_code'    => ['nullable', 'string', 'max:3', Rule::exists('currencies', 'code')],
            'currency_rate'    => 'nullable|numeric|gt:0',
        ]);

        $order = Order::where('order_id', (int) $id)->firstOrFail();
        $oldStatusId = $order->order_status_id;
        $original = $order->getAttributes();

        $currencySvc = app(OrderCurrencyService::class);
        $currency = $currencySvc->resolve(
            $request->currency_code ?? $order->currency_code,
            $request->filled('currency_rate') ? (float) $request->currency_rate : null
        );
        $rate = $currency['rate'];
        $isDefault = $currency['is_default'];

        $rawTotal = (float) ($request->total ?? 0);

        $order->update([
            'firstname'          => $request->firstname,
            'lastname'           => $request->lastname ?? '',
            'email'              => $request->email ?? '',
            'telephone'          => $request->telephone ?? '',
            'customer_tin'       => trim((string) $request->customer_tin),
            'payment_firstname'  => $request->payment_firstname ?? $request->firstname,
            'payment_lastname'   => $request->payment_lastname ?? ($request->lastname ?? ''),
            'payment_company'    => $request->payment_company ?? '',
            'payment_address_1'  => $request->payment_address_1 ?? '',
            'payment_address_2'  => $request->payment_address_2 ?? '',
            'payment_city'       => $request->payment_city ?? '',
            'payment_postcode'   => $request->payment_postcode ?? '',
            'payment_country'    => $request->payment_country ?? '',
            'payment_zone'       => $request->payment_zone ?? '',
            'payment_method'     => $request->payment_method ?? '',
            'shipping_firstname' => $request->shipping_firstname ?? $request->firstname,
            'shipping_lastname'  => $request->shipping_lastname ?? ($request->lastname ?? ''),
            'shipping_company'   => $request->shipping_company ?? '',
            'shipping_address_1' => $request->shipping_address_1 ?? '',
            'shipping_address_2' => $request->shipping_address_2 ?? '',
            'shipping_city'      => $request->shipping_city ?? '',
            'shipping_postcode'  => $request->shipping_postcode ?? '',
            'shipping_country'   => $request->shipping_country ?? '',
            'shipping_zone'      => $request->shipping_zone ?? '',
            'shipping_method'    => $request->shipping_method ?? '',
            'customer_note'      => $request->customer_note ?? '',
            'total'              => OrderCurrencyService::toBase($rawTotal, $rate),
            'foreign_total'      => $isDefault ? null : round($rawTotal, 4),
            'order_status_id'    => $request->order_status_id,
            'tracking_number'    => $request->tracking_number ?? '',
            'currency_id'        => $currency['id'],
            'currency_code'      => $currency['code'],
            'currency_value'     => $currency['rate'],
            'date_modified'      => now(),
        ]);

        $stockBefore = null;
        if ($request->has('products')) {
            $stockBefore = OrderStockService::snapshot($order);
            $products = $request->input('products', []);

            $existingProductIds = DB::table('order_products')
                ->where('order_id', $order->order_id)
                ->pluck('order_product_id')
                ->toArray();

            if (!empty($existingProductIds)) {
                DB::table('order_variations')
                    ->whereIn('order_product_id', $existingProductIds)
                    ->delete();
            }
            DB::table('order_products')
                ->where('order_id', $order->order_id)
                ->delete();

            $orderTotal = 0;
            $orderForeignTotal = 0;
            if (!empty($products)) {
                foreach ($products as $p) {
                    $qty = (int) ($p['quantity'] ?? 1);
                    $foreignPrice = (float) ($p['price'] ?? 0);
                    $foreignTotal = $foreignPrice * $qty;
                    $foreignCost = (float) ($p['cost'] ?? 0);

                    $price = OrderCurrencyService::toBase($foreignPrice, $rate);
                    $lineTotal = OrderCurrencyService::toBase($foreignTotal, $rate);
                    $cost = OrderCurrencyService::toBase($foreignCost, $rate);

                    $orderTotal += $lineTotal;
                    $orderForeignTotal += $foreignTotal;

                    $orderProductId = DB::table('order_products')->insertGetId([
                        'order_id'      => $order->order_id,
                        'product_id'    => (int) ($p['product_id'] ?? 0),
                        'name'          => $p['name'] ?? '',
                        'sku'           => $p['sku'] ?? '',
                        'quantity'      => $qty,
                        'price'         => $price,
                        'foreign_price' => $isDefault ? null : round($foreignPrice, 4),
                        'total'         => $lineTotal,
                        'foreign_total' => $isDefault ? null : round($foreignTotal, 4),
                        'cost'          => $cost,
                    ]);

                    if (!empty($p['variation_value_id'])) {
                        DB::table('order_variations')->insert([
                            'order_id'                  => $order->order_id,
                            'order_product_id'          => $orderProductId,
                            'product_variation_type_id'         => 0,
                            'product_variation_value_id'   => (int) $p['variation_value_id'],
                            'name'                      => $p['variation_type_name'] ?? '',
                            'value'                     => $p['variation_value'] ?? '',
                            'type'                      => 'select',
                        ]);
                    }
                }

                $order->update([
                    'total'         => $orderTotal,
                    'foreign_total' => $isDefault ? null : round($orderForeignTotal, 4),
                ]);

                DB::table('order_totals')->where('order_id', $order->order_id)->delete();
                DB::table('order_totals')->insert([
                    'order_id'   => $order->order_id,
                    'code'       => 'sub_total',
                    'title'      => 'Sub-Total',
                    'value'      => $orderTotal,
                    'sort_order' => 1,
                ]);
                DB::table('order_totals')->insert([
                    'order_id'   => $order->order_id,
                    'code'       => 'total',
                    'title'      => 'Total',
                    'value'      => $orderTotal,
                    'sort_order' => 9,
                ]);
            }
        }

        OrderHistory::create(array_merge($this->currentUser(), [
            'order_id'        => $order->order_id,
            'order_status_id' => (int) $request->order_status_id,
            'notify'          => 0,
            'note'            => $request->customer_note ?? '',
            'date_added'      => now(),
        ]));

        if ($stockBefore !== null) {
            OrderStockService::reconcile($order, $stockBefore, (int) $oldStatusId, (int) $request->order_status_id);
        } elseif ((int) $oldStatusId !== (int) $request->order_status_id) {
            OrderStockService::adjustStock($order, (int) $oldStatusId, (int) $request->order_status_id);
        }

        $changes = ActivityLogger::diff($original, $order->getAttributes(), [
            'firstname', 'lastname', 'email', 'telephone', 'customer_tin', 'total',
            'order_status_id', 'tracking_number', 'payment_method', 'shipping_method',
        ]);
        ActivityLogger::log('updated', 'Order', (int) $id, '#' . $id . ' ' . trim($request->firstname . ' ' . ($request->lastname ?? '')), $changes);

        return redirect()->route('orders.edit', $id)->with('status', 'Order updated.');
    }

    public function updateProductCost(Request $request, $id)
    {
        $request->validate([
            'order_product_id' => 'required|integer',
            'cost'             => 'required|numeric|min:0',
        ]);

        $updated = DB::table('order_products')
            ->where('order_id', (int) $id)
            ->where('order_product_id', (int) $request->order_product_id)
            ->update(['cost' => (float) $request->cost]);

        return response()->json(['ok' => $updated > 0]);
    }

    public function updateShippingCost(Request $request, $id)
    {
        $request->validate([
            'shipping_cost' => 'required|numeric|min:0',
        ]);

        $order = Order::where('order_id', (int) $id)->firstOrFail();
        $order->update(['shipping_cost' => (float) $request->shipping_cost]);

        return response()->json(['ok' => true]);
    }

    public function storeFee(Request $request, $id)
    {
        $request->validate([
            'label'  => 'required|string|max:128',
            'amount' => 'required|numeric|min:0.01',
        ]);

        Order::where('order_id', (int) $id)->firstOrFail();

        DB::table('order_fees')->insert([
            'order_id'   => (int) $id,
            'label'      => $request->label,
            'amount'     => (float) $request->amount,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return redirect()->route('orders.show', $id)->with('status', 'Fee added.');
    }

    public function updateFee(Request $request, $id, $feeId)
    {
        $request->validate([
            'amount' => 'required|numeric|min:0',
            'label'  => 'nullable|string|max:128',
        ]);

        $updated = DB::table('order_fees')
            ->where('id', (int) $feeId)
            ->where('order_id', (int) $id)
            ->update(array_filter([
                'amount'     => (float) $request->amount,
                'label'      => $request->label,
                'updated_at' => now(),
            ]));

        if ($request->expectsJson()) {
            return response()->json(['ok' => $updated > 0]);
        }

        return redirect()->route('orders.show', $id)->with('status', 'Fee updated.');
    }

    public function destroyFee(Request $request, $id, $feeId)
    {
        DB::table('order_fees')
            ->where('id', (int) $feeId)
            ->where('order_id', (int) $id)
            ->delete();

        if ($request->expectsJson()) {
            return response()->json(['ok' => true]);
        }

        return redirect()->route('orders.show', $id)->with('status', 'Fee removed.');
    }

    public function backfillCosts($id)
    {
        $order = Order::where('order_id', (int) $id)->firstOrFail();

        $products = DB::table('order_products')
            ->where('order_id', $order->order_id)
            ->where(function ($q) {
                $q->whereNull('cost')->orWhere('cost', 0);
            })
            ->get();

        $updated = 0;

        foreach ($products as $op) {
            $variationValueId = (int) (DB::table('order_variations')
                ->where('order_product_id', $op->order_product_id)
                ->where('product_variation_value_id', '>', 0)
                ->orderBy('order_variation_id')
                ->value('product_variation_value_id') ?? 0);
            $catalogCost = LineCost::resolve((int) $op->product_id, $variationValueId);

            if ($catalogCost && $catalogCost > 0) {
                DB::table('order_products')
                    ->where('order_product_id', $op->order_product_id)
                    ->update(['cost' => $catalogCost]);
                $updated++;
            }
        }

        return redirect()->route('orders.show', $order->order_id)
            ->with('status', $updated . ' product cost(s) updated from catalog data.');
    }

    public function destroy($id)
    {
        $orderId = (int) $id;
        $order = Order::with('products.variations')->where('order_id', $orderId)->first();

        if ($order) {
            $currentStatusId = (int) $order->order_status_id;
            if ($currentStatusId > 0) {
                OrderStockService::adjustStock($order, $currentStatusId, 0);
            }

            $order->delete();
        }

        OrderHistory::where('order_id', $orderId)->delete();

        ActivityLogger::log('deleted', 'Order', $orderId, '#' . $orderId);

        return redirect()->route('orders.index')->with('status', 'Order deleted.');
    }

    public function bulkAction(Request $request)
    {
        $ids = $request->input('ids', []);
        if (!is_array($ids)) {
            $ids = [];
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn ($v) => $v > 0)));

        if (empty($ids)) {
            return redirect()->route('orders.index')->with('status', 'No items selected.');
        }

        $orders = Order::with('products.variations')->whereIn('order_id', $ids)->get();
        foreach ($orders as $order) {
            $currentStatusId = (int) $order->order_status_id;
            if ($currentStatusId > 0) {
                OrderStockService::adjustStock($order, $currentStatusId, 0);
            }
        }

        Order::whereIn('order_id', $ids)->delete();
        OrderHistory::whereIn('order_id', $ids)->delete();

        foreach ($ids as $orderId) {
            ActivityLogger::log('deleted', 'Order', $orderId, '#' . $orderId);
        }

        return redirect()->route('orders.index')->with('status', 'Deleted selected orders. Stock restored.');
    }

    private static function resolveProductIdMap($orderProducts): array
    {
        $map = [];
        $skusToResolve = [];

        foreach ($orderProducts as $op) {
            if ($op->product_id > 0) {
                $map[$op->order_product_id] = $op->product_id;
            } else {
                $sku = trim((string) $op->sku);
                if ($sku !== '') {
                    $skusToResolve[$op->order_product_id] = $sku;
                }
            }
        }

        if ($skusToResolve) {
            $resolvers = app(ChannelRegistry::class)->skuResolvers();
            foreach ($skusToResolve as $opId => $sku) {
                foreach ($resolvers as $resolver) {
                    $resolved = $resolver->resolveCatalogProduct($sku);
                    if ($resolved !== null && !empty($resolved['product_id'])) {
                        $map[$opId] = (int) $resolved['product_id'];
                        break;
                    }
                }
            }
        }

        return $map;
    }

    public static function resolveOrderProductImages($orders): array
    {
        $allProducts = $orders->flatMap(fn ($o) => $o->products);
        $idMap = self::resolveProductIdMap($allProducts);

        $productIds = array_unique(array_values($idMap));
        $images = $productIds
            ? Product::whereIn('product_id', $productIds)->pluck('image', 'product_id')->toArray()
            : [];

        $bySku = \App\Support\CatalogImages::forSkus($allProducts->pluck('sku')->map(fn ($m) => trim((string) $m))->filter()->all());

        $result = [];
        foreach ($allProducts as $op) {
            $sku = trim((string) $op->sku);
            if ($sku !== '' && isset($bySku[$sku])) {
                $result[$op->order_product_id] = $bySku[$sku];
            }
        }
        foreach ($idMap as $opId => $pid) {
            if (!isset($result[$opId]) && isset($images[$pid]) && trim($images[$pid]) !== '') {
                $result[$opId] = $images[$pid];
            }
        }

        return $result;
    }

    private static function resolveOrderCatalogProducts($orderProducts): array
    {
        $idMap = self::resolveProductIdMap($orderProducts);

        $productIds = array_unique(array_values($idMap));
        $catalogProducts = $productIds
            ? Product::whereIn('product_id', $productIds)->get()->keyBy('product_id')->toArray()
            : [];

        $result = [];
        foreach ($idMap as $opId => $pid) {
            if (isset($catalogProducts[$pid])) {
                $result[$opId] = (object) $catalogProducts[$pid];
            }
        }

        return $result;
    }

    public static function resolveLazadaImages($orders): array
    {
        $salesOrderIds = [];
        foreach ($orders as $o) {
            $salesOrderIds[] = (int) $o->order_id;
        }
        if (empty($salesOrderIds)) {
            return [];
        }

        $merged = [];
        foreach (app(ChannelRegistry::class)->orderImagesContributors() as $contributor) {
            $merged += $contributor->imagesForSalesOrders($salesOrderIds);
        }
        return $merged;
    }

    public function storePayment(Request $request, $id)
    {
        $order = Order::where('order_id', (int) $id)->firstOrFail();

        $request->validate([
            'amount'         => 'required|numeric|min:0.0001',
            'payment_method' => 'required|string|max:64',
            'paid_at'        => 'required|date',
            'reference_no'   => 'nullable|string|max:128',
            'note'          => 'nullable|string',
        ]);

        OrderPayment::create([
            'order_id'       => $order->order_id,
            'amount'         => $request->amount,
            'payment_method' => $request->payment_method,
            'paid_at'        => $request->paid_at,
            'reference_no'   => $request->reference_no,
            'note'          => $request->note,
            'created_by'     => auth()->id(),
            'created_at'     => now(),
        ]);

        ActivityLogger::log('created', 'Order Payment', $order->order_id,
            '#' . $order->order_id . ' - ' . number_format((float) $request->amount, 2) . ' via ' . $request->payment_method);

        return redirect()->route('orders.show', $order->order_id)->with('status', 'Payment recorded.');
    }

    public function destroyPayment($id, $paymentId)
    {
        $order = Order::where('order_id', (int) $id)->firstOrFail();
        $payment = OrderPayment::where('order_id', $order->order_id)->findOrFail($paymentId);

        $amount = $payment->amount;
        $payment->delete();

        ActivityLogger::log('deleted', 'Order Payment', $order->order_id,
            '#' . $order->order_id . ' - ' . number_format((float) $amount, 2) . ' removed');

        return redirect()->route('orders.show', $order->order_id)->with('status', 'Payment deleted.');
    }

    public function togglePayments($id)
    {
        $order = Order::where('order_id', (int) $id)->firstOrFail();
        $newState = !$order->track_payments;

        if (!$newState && $order->payments()->count() > 0) {
            return redirect()->route('orders.show', $order->order_id)
                ->with('error', "Delete this order's payments before removing it from receivables.");
        }

        $order->update(['track_payments' => $newState]);

        ActivityLogger::log('updated', 'Order', $order->order_id,
            '#' . $order->order_id . ' - ' . ($newState ? 'added to receivables' : 'removed from receivables'));

        return redirect()->route('orders.show', $order->order_id)
            ->with('status', $newState ? 'Added to receivables.' : 'Removed from receivables.');
    }

    public function paymentsReport(Request $request)
    {
        $query = Order::where('track_payments', true);

        $filter = $request->input('filter', 'all');
        $search = trim($request->input('search', ''));

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('firstname', 'like', "%{$search}%")
                  ->orWhere('lastname', 'like', "%{$search}%")
                  ->orWhere('order_id', $search);
            });
        }

        $orders = $query->orderByDesc('date_added')->get();

        $orderIds = $orders->pluck('order_id');
        $paymentsByOrder = OrderPayment::whereIn('order_id', $orderIds)
            ->get()
            ->groupBy('order_id');

        $rows = $orders->map(function ($order) use ($paymentsByOrder) {
            $payments = $paymentsByOrder->get($order->order_id, collect());
            $totalPaid = (float) $payments->sum('amount');
            $orderTotal = (float) $order->total;
            $balance = $orderTotal - $totalPaid;
            $isPaid = $balance <= 0 && $orderTotal > 0;

            return (object) [
                'order' => $order,
                'total_paid' => $totalPaid,
                'balance' => $balance,
                'is_paid' => $isPaid,
                'payment_count' => $payments->count(),
            ];
        });

        $counts = [
            'all' => $rows->count(),
            'unpaid' => $rows->filter(fn ($r) => !$r->is_paid)->count(),
            'paid' => $rows->filter(fn ($r) => $r->is_paid)->count(),
        ];

        if ($filter === 'paid') {
            $rows = $rows->filter(fn ($r) => $r->is_paid);
        } elseif ($filter === 'unpaid') {
            $rows = $rows->filter(fn ($r) => !$r->is_paid);
        }

        return view('sales.orders.payments_report', [
            'rows' => $rows,
            'counts' => $counts,
            'filter' => $filter,
            'search' => $search,
        ]);
    }
}
