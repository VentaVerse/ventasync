<?php

namespace App\Http\Controllers;

use App\Models\Catalog\Order;
use App\Models\Catalog\OrderHistory;
use App\Models\Catalog\OrderProduct;
use App\Models\Catalog\OrderStatus;
use App\Models\Catalog\Product;
use App\Integrations\IntegrationRegistry;
use App\Models\OrderPayment;
use App\Services\ActivityLogger;
use App\Services\OrderStockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

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
                        ->orWhere('marketplace_order_id', 'like', '%' . $q . '%')
                        ->orWhere('order_id', '=', (int) $q > 0 ? (int) $q : 0);
                });
            })
            ->when($statusId > 0, function ($query) use ($statusId) {
                $query->where('order_status_id', $statusId);
            })
            ->when($source !== '', function ($query) use ($source) {
                if ($source === 'manual') {
                    $query->where('marketplace_source', '');
                } else {
                    $query->where('marketplace_source', $source);
                }
            })
            ->orderBy($sort, $dir)
            ->paginate(50)
            ->withQueryString();

        // Resolve catalog product images (handles product_id=0 via Lazada variant chain)
        $productImages = self::resolveOrderProductImages($orders->getCollection());
        $lazadaImages = self::resolveLazadaImages($orders->getCollection());

        $statuses = OrderStatus::orderBy('order_status_id')->get();

        // Source options (filter dropdown) and value→option map (source column
        // badge lookup) come from the integration registry — every installed
        // marketplace extension contributes via MarketplaceSourceOptionsProvider.
        // Core has no knowledge of which marketplaces exist.
        $sourceOptions = app(\App\Integrations\IntegrationRegistry::class)
            ->availableMarketplaceSourceOptions();
        $sourceLabelsMap = [];
        foreach ($sourceOptions as $opt) {
            $sourceLabelsMap[$opt['value']] = $opt;
        }

        return view('orders.index', compact('orders', 'q', 'statusId', 'statuses', 'source', 'productImages', 'lazadaImages', 'sort', 'dir', 'sourceOptions', 'sourceLabelsMap'));
    }

    public function create()
    {
        $statuses = OrderStatus::orderBy('order_status_id')->get();
        $currencies = DB::table('currencies')->where('status', 1)->orderByDesc('is_default')->orderBy('code')->get();

        return view('orders.create', compact('statuses', 'currencies'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'firstname'        => 'required|string|max:32',
            'lastname'         => 'nullable|string|max:32',
            'email'            => 'nullable|string|max:96',
            'telephone'        => 'nullable|string|max:32',
            'payment_method'   => 'nullable|string|max:128',
            'shipping_method'  => 'nullable|string',
            'total'            => 'nullable|numeric|min:0',
            'order_status_id'  => 'required|integer|min:0',
            'comment'          => 'nullable|string',
            'currency_code'    => 'nullable|string|max:3',
        ]);

        $now = now();

        $order = Order::create([
            'invoice_no'           => 0,
            'invoice_prefix'       => '',
            'store_id'             => 0,
            'store_name'           => '',
            'store_url'            => '',
            'customer_id'          => 0,
            'customer_group_id'    => 0,
            'firstname'            => $request->firstname,
            'lastname'             => $request->lastname ?? '',
            'email'                => $request->email ?? '',
            'telephone'            => $request->telephone ?? '',
            'fax'                  => '',
            'custom_field'         => '',
            'payment_firstname'    => $request->payment_firstname ?? $request->firstname,
            'payment_lastname'     => $request->payment_lastname ?? ($request->lastname ?? ''),
            'payment_company'      => $request->payment_company ?? '',
            'payment_address_1'    => $request->payment_address_1 ?? '',
            'payment_address_2'    => $request->payment_address_2 ?? '',
            'payment_city'         => $request->payment_city ?? '',
            'payment_postcode'     => $request->payment_postcode ?? '',
            'payment_country'      => $request->payment_country ?? '',
            'payment_country_id'   => 0,
            'payment_zone'         => $request->payment_zone ?? '',
            'payment_zone_id'      => 0,
            'payment_address_format' => '',
            'payment_custom_field' => '',
            'payment_method'       => $request->payment_method ?? '',
            'payment_cost'         => 0,
            'payment_code'         => '',
            'shipping_firstname'   => $request->shipping_firstname ?? $request->firstname,
            'shipping_lastname'    => $request->shipping_lastname ?? ($request->lastname ?? ''),
            'shipping_company'     => $request->shipping_company ?? '',
            'shipping_address_1'   => $request->shipping_address_1 ?? '',
            'shipping_address_2'   => $request->shipping_address_2 ?? '',
            'shipping_city'        => $request->shipping_city ?? '',
            'shipping_postcode'    => $request->shipping_postcode ?? '',
            'shipping_country'     => $request->shipping_country ?? '',
            'shipping_country_id'  => 0,
            'shipping_zone'        => $request->shipping_zone ?? '',
            'shipping_zone_id'     => 0,
            'shipping_address_format' => '',
            'shipping_custom_field' => '',
            'shipping_method'      => $request->shipping_method ?? '',
            'shipping_cost'        => 0,
            'shipping_code'        => '',
            'comment'              => $request->comment ?? '',
            'total'                => $request->total ?? 0,
            'extra_cost'           => 0,
            'order_status_id'      => $request->order_status_id,
            'affiliate_id'         => 0,
            'commission'           => 0,
            'marketing_id'         => 0,
            'tracking'             => '',
            'language_id'          => 1,
            'currency_id'          => 0,
            'currency_code'        => $request->currency_code ?? 'PHP',
            'currency_value'       => 1,
            'ip'                   => $request->ip() ?? '',
            'forwarded_ip'         => '',
            'user_agent'           => '',
            'accept_language'      => '',
            'courier_id'           => 0,
            'tracking_number'      => '',
            'date_added'           => $now,
            'date_modified'        => $now,
            'oe_import'            => 0,
        ]);

        // Add initial history entry
        OrderHistory::create(array_merge($this->currentUser(), [
            'order_id'        => $order->order_id,
            'order_status_id' => $request->order_status_id,
            'notify'          => 0,
            'comment'         => $request->comment ?? '',
            'date_added'      => $now,
        ]));

        ActivityLogger::log('created', 'Order', (int) $order->order_id, '#' . $order->order_id . ' ' . trim($request->firstname . ' ' . $request->lastname));

        // FCM notification dispatched centrally by App\Observers\OrderObserver
        // on Order::create above.

        // Create order products
        $products = $request->input('products', []);
        if (!empty($products)) {
            $pfx = (string) config('catalog.prefix');
            $orderTotal = 0;

            foreach ($products as $p) {
                $qty = (int) ($p['quantity'] ?? 1);
                $price = (float) ($p['price'] ?? 0);
                $lineTotal = $price * $qty;
                $orderTotal += $lineTotal;

                $orderProductId = DB::table($pfx . 'order_product')->insertGetId([
                    'order_id'   => $order->order_id,
                    'product_id' => (int) ($p['product_id'] ?? 0),
                    'name'       => $p['name'] ?? '',
                    'model'      => $p['model'] ?? '',
                    'quantity'   => $qty,
                    'price'      => $price,
                    'total'      => $lineTotal,
                    'tax'        => 0,
                    'reward'     => 0,
                    'cost'       => (float) ($p['cost'] ?? 0),
                ]);

                // Save option value if selected
                $povId = (int) ($p['option_value_id'] ?? 0);
                if ($povId > 0 && $orderProductId) {
                    $pov = DB::table($pfx . 'product_option_value')
                        ->where('product_option_value_id', $povId)
                        ->first(['product_option_id', 'option_value_id']);

                    DB::table($pfx . 'order_option')->insert([
                        'order_id'                 => $order->order_id,
                        'order_product_id'         => $orderProductId,
                        'product_option_id'        => $pov->product_option_id ?? 0,
                        'product_option_value_id'  => $povId,
                        'name'                     => $p['option_name'] ?? '',
                        'value'                    => $p['option_value'] ?? '',
                        'type'                     => 'select',
                    ]);
                }
            }

            // Update order total from line items
            $order->update(['total' => $orderTotal]);

            // Create order totals
            DB::table($pfx . 'order_total')->insert([
                'order_id'   => $order->order_id,
                'code'       => 'sub_total',
                'title'      => 'Sub-Total',
                'value'      => $orderTotal,
                'sort_order' => 1,
            ]);
            DB::table($pfx . 'order_total')->insert([
                'order_id'   => $order->order_id,
                'code'       => 'total',
                'title'      => 'Total',
                'value'      => $orderTotal,
                'sort_order' => 9,
            ]);
        }

        // Adjust stock based on new status (after products are inserted)
        $order->load('products');
        OrderStockService::adjustStock($order, 0, (int) $request->order_status_id);

        return redirect()->route('orders.index')->with('status', 'Order created.');
    }

    public function searchProducts(Request $request)
    {
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');
        $q = trim((string) $request->input('q'));

        if (strlen($q) < 2) {
            return response()->json(['items' => []]);
        }

        $rows = DB::table($pfx . 'product as p')
            ->join($pfx . 'product_description as pd', function ($j) use ($langId) {
                $j->on('p.product_id', '=', 'pd.product_id')
                    ->where('pd.language_id', '=', $langId);
            })
            ->where(function ($w) use ($q) {
                $w->where('pd.name', 'like', "%{$q}%")
                    ->orWhere('p.sku', 'like', "%{$q}%")
                    ->orWhere('p.model', 'like', "%{$q}%");
            })
            ->select('p.product_id', 'pd.name', 'p.sku', 'p.model', 'p.price', 'p.cost', 'p.quantity', 'p.image')
            ->orderBy('p.product_id', 'desc')
            ->limit(30)
            ->get();

        // Attach variations for each product
        // Priority: product_option_combinations (multi-option combos with real prices)
        // Fallback: product_option_value (single options)
        $productIds = $rows->pluck('product_id')->toArray();
        $combinationsByProduct = [];
        $optionsByProduct = [];

        if (!empty($productIds)) {
            // Check for combinations first (these have the real prices/quantities)
            $combos = DB::table('product_option_combinations as poc')
                ->whereIn('poc.product_id', $productIds)
                ->select('poc.id', 'poc.product_id', 'poc.sku', 'poc.quantity', 'poc.absolute_price', 'poc.absolute_cost')
                ->orderBy('poc.sort_order')
                ->get();

            if ($combos->isNotEmpty()) {
                $comboIds = $combos->pluck('id')->toArray();
                $comboValues = DB::table('product_option_combination_values as pocv')
                    ->join($pfx . 'product_option_value as pov', 'pocv.product_option_value_id', '=', 'pov.product_option_value_id')
                    ->join($pfx . 'option_value_description as ovd', function ($j) use ($langId) {
                        $j->on('pov.option_value_id', '=', 'ovd.option_value_id')
                            ->where('ovd.language_id', '=', $langId);
                    })
                    ->whereIn('pocv.combination_id', $comboIds)
                    ->select('pocv.combination_id', 'ovd.name as value_name')
                    ->get()
                    ->groupBy('combination_id');

                // Also get POV IDs for each combination
                $comboPovMap = DB::table('product_option_combination_values')
                    ->whereIn('combination_id', $comboIds)
                    ->get(['combination_id', 'product_option_value_id'])
                    ->groupBy('combination_id');

                foreach ($combos as $c) {
                    $valueNames = ($comboValues->get($c->id) ?? collect())->pluck('value_name')->implode(' / ');
                    $povIds = ($comboPovMap->get($c->id) ?? collect())->pluck('product_option_value_id')->toArray();
                    $combinationsByProduct[$c->product_id][] = (object) [
                        'combination_id'          => $c->id,
                        'product_option_value_id' => $povIds[0] ?? null,
                        'product_id'              => $c->product_id,
                        'option_sku'              => $c->sku,
                        'option_qty'              => (int) $c->quantity,
                        'absolute_price'          => (float) $c->absolute_price,
                        'absolute_cost'           => (float) $c->absolute_cost,
                        'option_price'            => 0,
                        'price_prefix'            => '+',
                        'option_cost'             => 0,
                        'cost_prefix'             => '+',
                        'value_name'              => $valueNames,
                        'option_name'             => 'Option',
                        'is_combination'          => true,
                    ];
                }
            }

            // Also get single option values for products without combinations
            $optRows = DB::table($pfx . 'product_option_value as pov')
                ->join($pfx . 'option_value_description as ovd', function ($j) use ($langId) {
                    $j->on('pov.option_value_id', '=', 'ovd.option_value_id')
                        ->where('ovd.language_id', '=', $langId);
                })
                ->join($pfx . 'option_description as od', function ($j) use ($langId) {
                    $j->on('pov.option_id', '=', 'od.option_id')
                        ->where('od.language_id', '=', $langId);
                })
                ->whereIn('pov.product_id', $productIds)
                ->select(
                    'pov.product_option_value_id', 'pov.product_id',
                    'pov.sku as option_sku', 'pov.quantity as option_qty',
                    'pov.price as option_price', 'pov.price_prefix',
                    'pov.cost as option_cost', 'pov.cost_prefix',
                    'pov.absolute_price', 'pov.absolute_cost',
                    'ovd.name as value_name', 'od.name as option_name'
                )
                ->orderBy('pov.product_option_value_id')
                ->get();

            foreach ($optRows as $o) {
                $o->is_combination = false;
                $optionsByProduct[$o->product_id][] = $o;
            }
        }

        $items = $rows->map(function ($p) use ($combinationsByProduct, $optionsByProduct) {
            // Use combinations if available, otherwise fall back to raw option values
            $p->options = $combinationsByProduct[$p->product_id]
                ?? $optionsByProduct[$p->product_id]
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
        $products = $order->products()->with('options')->get();
        $orderTotals = $order->totals()->orderBy('sort_order')->get();

        // Resolve catalog products (handles product_id=0 via Lazada variant chain)
        $catalogProducts = self::resolveOrderCatalogProducts($products);
        $lazadaImages = self::resolveLazadaImages(collect([$order]));

        // Look up marketplace fees from the source order
        $marketplaceFees = self::resolveMarketplaceFees($order);

        $payments = OrderPayment::where('order_id', (int) $id)->orderBy('paid_at')->get();
        $totalPaid = $payments->sum('amount');

        return view('orders.show', compact('order', 'statuses', 'history', 'products', 'orderTotals', 'catalogProducts', 'lazadaImages', 'marketplaceFees', 'payments', 'totalPaid'));
    }

    public function updateStatus(Request $request, $id)
    {
        $request->validate([
            'order_status_id' => 'required|integer|min:1',
            'comment'         => 'nullable|string',
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
            'comment'         => $request->comment ?? '',
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

        // FCM notification dispatched centrally by App\Observers\OrderObserver
        // on $order->update() above.

        return redirect()->route('orders.show', $id)->with('status', 'Order status updated.');
    }

    public function edit($id)
    {
        $order = Order::where('order_id', (int) $id)->firstOrFail();
        $statuses = OrderStatus::orderBy('order_status_id')->get();
        $currencies = DB::table('currencies')->where('status', 1)->orderByDesc('is_default')->orderBy('code')->get();
        $history = OrderHistory::where('order_id', (int) $id)
            ->orderByDesc('date_added')
            ->get();
        $products = $order->products()->with('options')->get();
        $orderTotals = $order->totals()->orderBy('sort_order')->get();

        return view('orders.edit', compact('order', 'statuses', 'currencies', 'history', 'products', 'orderTotals'));
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'firstname'        => 'required|string|max:32',
            'lastname'         => 'nullable|string|max:32',
            'email'            => 'nullable|string|max:96',
            'telephone'        => 'nullable|string|max:32',
            'payment_method'   => 'nullable|string|max:128',
            'shipping_method'  => 'nullable|string',
            'total'            => 'nullable|numeric|min:0',
            'order_status_id'  => 'required|integer|min:0',
            'comment'          => 'nullable|string',
            'currency_code'    => 'nullable|string|max:3',
        ]);

        $order = Order::where('order_id', (int) $id)->firstOrFail();
        $oldStatusId = $order->order_status_id;
        $original = $order->getAttributes();

        $order->update([
            'firstname'          => $request->firstname,
            'lastname'           => $request->lastname ?? '',
            'email'              => $request->email ?? '',
            'telephone'          => $request->telephone ?? '',
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
            'comment'            => $request->comment ?? '',
            'total'              => $request->total ?? 0,
            'order_status_id'    => $request->order_status_id,
            'tracking_number'    => $request->tracking_number ?? '',
            'currency_code'      => $request->currency_code ?? $order->currency_code,
            'date_modified'      => now(),
        ]);

        // Rebuild order products from submitted data
        $products = $request->input('products', []);
        $pfx = (string) config('catalog.prefix');

        // Delete existing order products and their options
        $existingProductIds = DB::table($pfx . 'order_product')
            ->where('order_id', $order->order_id)
            ->pluck('order_product_id')
            ->toArray();

        if (!empty($existingProductIds)) {
            DB::table($pfx . 'order_option')
                ->whereIn('order_product_id', $existingProductIds)
                ->delete();
        }
        DB::table($pfx . 'order_product')
            ->where('order_id', $order->order_id)
            ->delete();

        // Recreate from submitted data
        $orderTotal = 0;
        if (!empty($products)) {
            foreach ($products as $p) {
                $qty = (int) ($p['quantity'] ?? 1);
                $price = (float) ($p['price'] ?? 0);
                $lineTotal = $price * $qty;
                $orderTotal += $lineTotal;

                $orderProductId = DB::table($pfx . 'order_product')->insertGetId([
                    'order_id'   => $order->order_id,
                    'product_id' => (int) ($p['product_id'] ?? 0),
                    'name'       => $p['name'] ?? '',
                    'model'      => $p['model'] ?? '',
                    'quantity'   => $qty,
                    'price'      => $price,
                    'total'      => $lineTotal,
                    'tax'        => 0,
                    'reward'     => 0,
                    'cost'       => (float) ($p['cost'] ?? 0),
                ]);

                // Create order option if present
                if (!empty($p['option_value_id'])) {
                    DB::table($pfx . 'order_option')->insert([
                        'order_id'                  => $order->order_id,
                        'order_product_id'          => $orderProductId,
                        'product_option_id'         => 0,
                        'product_option_value_id'   => (int) $p['option_value_id'],
                        'name'                      => $p['option_name'] ?? '',
                        'value'                     => $p['option_value'] ?? '',
                        'type'                      => 'select',
                    ]);
                }
            }

            // Update order total and totals table
            $order->update(['total' => $orderTotal]);

            DB::table($pfx . 'order_total')->where('order_id', $order->order_id)->delete();
            DB::table($pfx . 'order_total')->insert([
                'order_id'   => $order->order_id,
                'code'       => 'sub_total',
                'title'      => 'Sub-Total',
                'value'      => $orderTotal,
                'sort_order' => 1,
            ]);
            DB::table($pfx . 'order_total')->insert([
                'order_id'   => $order->order_id,
                'code'       => 'total',
                'title'      => 'Total',
                'value'      => $orderTotal,
                'sort_order' => 9,
            ]);
        }

        // Always log history entry on update
        OrderHistory::create(array_merge($this->currentUser(), [
            'order_id'        => $order->order_id,
            'order_status_id' => (int) $request->order_status_id,
            'notify'          => 0,
            'comment'         => $request->comment ?? '',
            'date_added'      => now(),
        ]));

        // Adjust stock if status changed
        if ((int) $oldStatusId !== (int) $request->order_status_id) {
            // Adjust stock based on status transition
            OrderStockService::adjustStock($order, (int) $oldStatusId, (int) $request->order_status_id);
        }

        $changes = ActivityLogger::diff($original, $order->getAttributes(), [
            'firstname', 'lastname', 'email', 'telephone', 'total',
            'order_status_id', 'tracking_number', 'payment_method', 'shipping_method',
        ]);
        ActivityLogger::log('updated', 'Order', (int) $id, '#' . $id . ' ' . trim($request->firstname . ' ' . ($request->lastname ?? '')), $changes);

        return redirect()->route('orders.edit', $id)->with('status', 'Order updated.');
    }

    /**
     * Update a single order product's cost via AJAX.
     */
    public function updateProductCost(Request $request, $id)
    {
        $request->validate([
            'order_product_id' => 'required|integer',
            'cost'             => 'required|numeric|min:0',
        ]);

        $pfx = (string) config('catalog.prefix');

        $updated = DB::table($pfx . 'order_product')
            ->where('order_id', (int) $id)
            ->where('order_product_id', (int) $request->order_product_id)
            ->update(['cost' => (float) $request->cost]);

        return response()->json(['ok' => $updated > 0]);
    }

    /**
     * Update an order's shipping cost via AJAX.
     */
    public function updateShippingCost(Request $request, $id)
    {
        $request->validate([
            'shipping_cost' => 'required|numeric|min:0',
        ]);

        $order = Order::where('order_id', (int) $id)->firstOrFail();
        $order->update(['shipping_cost' => (float) $request->shipping_cost]);

        return response()->json(['ok' => true]);
    }

    /**
     * Add a fee to an order.
     */
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

    /**
     * Update a fee amount via AJAX.
     */
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

    /**
     * Delete a fee.
     */
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

    /**
     * Backfill missing costs for an order's products from current catalog data.
     * Only updates products where cost is null or zero.
     */
    public function backfillCosts($id)
    {
        $pfx = (string) config('catalog.prefix');
        $order = Order::where('order_id', (int) $id)->firstOrFail();

        $products = DB::table($pfx . 'order_product')
            ->where('order_id', $order->order_id)
            ->where(function ($q) {
                $q->whereNull('cost')->orWhere('cost', 0);
            })
            ->get();

        $updated = 0;

        foreach ($products as $op) {
            $catalogCost = null;

            if ((int) $op->product_id > 0) {
                // Try option value cost first (via order_option)
                $orderOption = DB::table($pfx . 'order_option')
                    ->where('order_product_id', $op->order_product_id)
                    ->first();

                if ($orderOption && (int) $orderOption->product_option_value_id > 0) {
                    // Check combinations first
                    $combo = DB::table('product_option_combinations as poc')
                        ->join('product_option_combination_values as pocv', 'poc.id', '=', 'pocv.combination_id')
                        ->where('poc.product_id', $op->product_id)
                        ->where('pocv.product_option_value_id', $orderOption->product_option_value_id)
                        ->first(['poc.absolute_cost']);

                    if ($combo && (float) $combo->absolute_cost > 0) {
                        $catalogCost = (float) $combo->absolute_cost;
                    } else {
                        // Fallback to option value cost
                        $optVal = DB::table($pfx . 'product_option_value')
                            ->where('product_option_value_id', $orderOption->product_option_value_id)
                            ->first(['cost', 'cost_prefix', 'absolute_cost']);

                        if ($optVal) {
                            if ((float) ($optVal->absolute_cost ?? 0) > 0) {
                                $catalogCost = (float) $optVal->absolute_cost;
                            } else {
                                $baseCost = (float) DB::table($pfx . 'product')
                                    ->where('product_id', $op->product_id)
                                    ->value('cost');
                                $delta = (float) ($optVal->cost ?? 0);
                                $catalogCost = ($optVal->cost_prefix === '-')
                                    ? $baseCost - $delta
                                    : $baseCost + $delta;
                            }
                        }
                    }
                }

                // Fallback to main product cost
                if ($catalogCost === null || $catalogCost <= 0) {
                    $catalogCost = (float) DB::table($pfx . 'product')
                        ->where('product_id', $op->product_id)
                        ->value('cost');
                }
            }

            if ($catalogCost && $catalogCost > 0) {
                DB::table($pfx . 'order_product')
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
        $order = Order::with('products.options')->where('order_id', $orderId)->first();

        if ($order) {
            // Restore stock if order was in a subtract status
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

        // Restore stock for each order before deleting
        $orders = Order::with('products.options')->whereIn('order_id', $ids)->get();
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

    /**
     * Resolve catalog product_id for order products (handles Lazada product_id=0 via variant chain).
     * Returns [ order_product_id => product_id ]
     */
    private static function resolveProductIdMap($orderProducts): array
    {
        $map = [];
        $skusToResolve = [];

        foreach ($orderProducts as $op) {
            if ($op->product_id > 0) {
                $map[$op->order_product_id] = $op->product_id;
            } else {
                $sku = trim((string) $op->model);
                if ($sku !== '') {
                    $skusToResolve[$op->order_product_id] = $sku;
                }
            }
        }

        if ($skusToResolve) {
            // Iterate the SKU resolvers from each marketplace extension
            // (Lazada implements one; others can opt in). First match wins.
            $resolvers = app(IntegrationRegistry::class)->skuResolvers();
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

    /**
     * Build order_product_id → image path map for a collection of orders.
     *
     * Public so marketplace extensions (e.g. OpenCart's per-store order list
     * view) can reuse the same image-resolution pipeline as the unified
     * Orders index. TODO: extract this and resolveLazadaImages into a
     * dedicated OrderViewSupport service so extensions don't reach back
     * into a core controller's static API.
     */
    public static function resolveOrderProductImages($orders): array
    {
        $allProducts = $orders->flatMap(fn ($o) => $o->products);
        $idMap = self::resolveProductIdMap($allProducts);

        $productIds = array_unique(array_values($idMap));
        $images = $productIds
            ? Product::whereIn('product_id', $productIds)->pluck('image', 'product_id')->toArray()
            : [];

        // Re-key by order_product_id so views can look up directly
        $result = [];
        foreach ($idMap as $opId => $pid) {
            if (isset($images[$pid]) && trim($images[$pid]) !== '') {
                $result[$opId] = $images[$pid];
            }
        }

        return $result;
    }

    /**
     * Build catalog product_id → Product map for an order's products (show page).
     */
    private static function resolveOrderCatalogProducts($orderProducts): array
    {
        $idMap = self::resolveProductIdMap($orderProducts);

        $productIds = array_unique(array_values($idMap));
        $catalogProducts = $productIds
            ? Product::whereIn('product_id', $productIds)->get()->keyBy('product_id')->toArray()
            : [];

        // Re-key by order_product_id so views can look up by order product
        $result = [];
        foreach ($idMap as $opId => $pid) {
            if (isset($catalogProducts[$pid])) {
                $result[$opId] = (object) $catalogProducts[$pid];
            }
        }

        return $result;
    }

    /**
     * Build order_product_id → marketplace image URL map by asking every
     * registered OrderImagesContributor for its contribution. Lazada is the
     * canonical contributor (its catalog orders typically have product_id=0
     * with the Lazada SKU as the model, so the catalog image lookup misses).
     *
     * Public so marketplace extensions can reuse the same image-resolution
     * pipeline (see resolveOrderProductImages docblock).
     */
    public static function resolveLazadaImages($orders): array
    {
        $catalogOrderIds = [];
        foreach ($orders as $o) {
            $catalogOrderIds[] = (int) $o->order_id;
        }
        if (empty($catalogOrderIds)) {
            return [];
        }

        $merged = [];
        foreach (app(IntegrationRegistry::class)->orderImagesContributors() as $contributor) {
            $merged += $contributor->imagesForCatalogOrders($catalogOrderIds);
        }
        return $merged;
    }

    /**
     * Resolve marketplace fees by asking each registered OrderFeesContributor
     * if it can identify the order. Manual fees (from the order_fees table)
     * are merged in regardless of which marketplace, if any, owns the order.
     */
    private static function resolveMarketplaceFees(Order $order): array
    {
        $source = (string) $order->marketplace_source;

        // Manual fees from the order_fees table apply to every order.
        $manualFees = DB::table('order_fees')
            ->where('order_id', $order->order_id)
            ->get();

        $manualItems = [];
        $manualTotal = 0;
        foreach ($manualFees as $f) {
            $amt = abs((float) $f->amount);
            if ($amt > 0) {
                $manualItems[] = ['label' => $f->label, 'amount' => $amt, 'fee_id' => $f->id];
                $manualTotal += $amt;
            }
        }

        // Ask the registry for the marketplace breakdown. First non-null wins.
        $marketplaceFees = null;
        foreach (app(IntegrationRegistry::class)->orderFeesContributors() as $contributor) {
            $marketplaceFees = $contributor->feesForOrder($order);
            if ($marketplaceFees !== null) {
                break;
            }
        }

        if ($marketplaceFees === null) {
            return [
                'total'  => round($manualTotal, 2),
                'items'  => $manualItems,
                'source' => $source ?: 'manual',
            ];
        }

        return [
            'total'  => round(((float) ($marketplaceFees['total'] ?? 0)) + $manualTotal, 2),
            'items'  => array_merge($marketplaceFees['items'] ?? [], $manualItems),
            'source' => $marketplaceFees['source'] ?? $source,
        ];
    }

    // ── Order Payments ──────────────────────────────────────

    public function storePayment(Request $request, $id)
    {
        $order = Order::where('order_id', (int) $id)->firstOrFail();

        $request->validate([
            'amount'         => 'required|numeric|min:0.0001',
            'payment_method' => 'required|string|max:64',
            'paid_at'        => 'required|date',
            'reference_no'   => 'nullable|string|max:128',
            'notes'          => 'nullable|string',
        ]);

        OrderPayment::create([
            'order_id'       => $order->order_id,
            'amount'         => $request->amount,
            'payment_method' => $request->payment_method,
            'paid_at'        => $request->paid_at,
            'reference_no'   => $request->reference_no,
            'notes'          => $request->notes,
            'created_by'     => auth()->id(),
            'created_at'     => now(),
        ]);

        ActivityLogger::log('created', 'Order Payment', $order->order_id,
            '#' . $order->order_id . ' — ' . number_format((float) $request->amount, 2) . ' via ' . $request->payment_method);

        return redirect()->route('orders.show', $order->order_id)->with('status', 'Payment recorded.');
    }

    public function destroyPayment($id, $paymentId)
    {
        $order = Order::where('order_id', (int) $id)->firstOrFail();
        $payment = OrderPayment::where('order_id', $order->order_id)->findOrFail($paymentId);

        $amount = $payment->amount;
        $payment->delete();

        ActivityLogger::log('deleted', 'Order Payment', $order->order_id,
            '#' . $order->order_id . ' — ' . number_format((float) $amount, 2) . ' removed');

        return redirect()->route('orders.show', $order->order_id)->with('status', 'Payment deleted.');
    }

    public function togglePayments($id)
    {
        $order = Order::where('order_id', (int) $id)->firstOrFail();
        $newState = !$order->track_payments;

        // If disabling and payments exist, prevent it
        if (!$newState && $order->payments()->count() > 0) {
            return redirect()->route('orders.show', $order->order_id)
                ->with('error', 'Cannot disable payment tracking while payments exist. Delete all payments first.');
        }

        $order->update(['track_payments' => $newState]);

        $action = $newState ? 'enabled' : 'disabled';
        ActivityLogger::log('updated', 'Order', $order->order_id,
            '#' . $order->order_id . ' — payment tracking ' . $action);

        return redirect()->route('orders.show', $order->order_id)
            ->with('status', 'Payment tracking ' . $action . '.');
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

        // Eager load payments and compute balances
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

        if ($filter === 'paid') {
            $rows = $rows->filter(fn ($r) => $r->is_paid);
        } elseif ($filter === 'unpaid') {
            $rows = $rows->filter(fn ($r) => !$r->is_paid);
        }

        return view('orders.payments_report', [
            'rows' => $rows,
            'filter' => $filter,
            'search' => $search,
        ]);
    }
}
