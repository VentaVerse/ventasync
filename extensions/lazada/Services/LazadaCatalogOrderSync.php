<?php

namespace Extensions\lazada\Services;

use Extensions\lazada\Models\LazadaOrder;
use Extensions\lazada\Models\LazadaOrderStatusMap;
use Extensions\lazada\Models\LazadaProduct;
use Extensions\lazada\Models\LazadaProductVariant;
use App\Models\Catalog\Order;
use App\Models\Catalog\OrderHistory;
use App\Models\Catalog\OrderOption;
use App\Models\Catalog\OrderProduct;
use App\Models\Catalog\OrderTotal;
use App\Services\OrderStockService;
use Illuminate\Support\Facades\DB;

use App\Services\ActivityLogger;

class LazadaCatalogOrderSync
{
    private bool $skipStockAdjust = false;

    public function setSkipStockAdjust(bool $skip = true): self
    {
        $this->skipStockAdjust = $skip;
        return $this;
    }

    public function sync(LazadaOrder $lazadaOrder): void
    {
        DB::transaction(function () use ($lazadaOrder) {
            $raw = is_array($lazadaOrder->raw) ? $lazadaOrder->raw : [];
            $detail = (isset($raw['_detail']) && is_array($raw['_detail'])) ? $raw['_detail'] : [];

            // Merge: prefer detail over raw list payload
            $src = array_merge($raw, $detail);

            $orderStatusMap = LazadaOrderStatusMap::where('context', 'order')->pluck('order_status_id', 'lazada_status')->all();
            $returnStatusMap = LazadaOrderStatusMap::where('context', 'return')->pluck('order_status_id', 'lazada_status')->all();
            $lazadaStatus = strtolower($lazadaOrder->status ?? '');
            $statusId = $orderStatusMap[$lazadaStatus] ?? $returnStatusMap[$lazadaStatus] ?? 1;

            // --- Name ---
            $firstname = trim((string) ($src['customer_first_name'] ?? ''));
            $lastname = trim((string) ($src['customer_last_name'] ?? ''));
            if ($firstname === '') {
                $fullName = trim((string) ($src['customer_name'] ?? ($src['buyer_name'] ?? '')));
                $parts = preg_split('/\s+/', $fullName, 2);
                $firstname = $parts[0] ?? '';
                $lastname = $parts[1] ?? '';
            }

            // --- Shipping address ---
            $addr = $src['address_shipping'] ?? $src['shipping_address'] ?? $src['address'] ?? [];
            if (!is_array($addr)) $addr = [];

            $shippingFirstname = trim((string) ($addr['first_name'] ?? $firstname));
            $shippingLastname = trim((string) ($addr['last_name'] ?? $lastname));
            $shippingAddress1 = trim((string) ($addr['address1'] ?? ($addr['address'] ?? '')));
            $shippingAddress2 = trim((string) ($addr['address2'] ?? ''));
            $shippingCity = trim((string) ($addr['city'] ?? ''));
            $shippingPostcode = trim((string) ($addr['post_code'] ?? ($addr['zip_code'] ?? '')));
            $shippingCountry = trim((string) ($addr['country'] ?? ''));
            $shippingZone = trim((string) ($addr['state'] ?? ($addr['region'] ?? '')));
            $telephone = trim((string) ($addr['phone'] ?? ($addr['phone1'] ?? ($src['receiver_phone'] ?? ''))));

            // --- Other fields ---
            $email = trim((string) ($src['customer_email'] ?? ''));
            $shippingMethod = trim((string) ($src['shipping_provider'] ?? ($src['shipping_provider_type'] ?? '')));
            $paymentMethod = trim((string) ($src['payment_method'] ?? ''));
            $trackingNumber = trim((string) ($src['tracking_code'] ?? ($src['tracking_number'] ?? '')));
            $total = (float) ($src['price'] ?? ($src['total'] ?? 0));
            $currencyCode = trim((string) ($src['currency'] ?? 'PHP'));
            if ($currencyCode === '') $currencyCode = 'PHP';

            $lazadaOrderId = (string) $lazadaOrder->order_id;

            $createdAt = $lazadaOrder->order_created_at ?? now();
            $updatedAt = $lazadaOrder->order_updated_at ?? now();

            // --- Find or create catalog order ---
            $catalogOrder = null;

            if ($lazadaOrder->catalog_order_id) {
                $catalogOrder = Order::where('order_id', $lazadaOrder->catalog_order_id)->first();
            }

            if (!$catalogOrder) {
                $catalogOrder = Order::where('marketplace_source', 'lazada')
                    ->where('marketplace_order_id', $lazadaOrderId)
                    ->first();
            }

            $orderData = [
                'invoice_no'            => 0,
                'invoice_prefix'        => '',
                'store_id'              => 0,
                'store_name'            => '',
                'store_url'             => '',
                'customer_id'           => 0,
                'customer_group_id'     => 0,
                'firstname'             => $firstname,
                'lastname'              => $lastname,
                'email'                 => $email,
                'telephone'             => $telephone,
                'fax'                   => '',
                'custom_field'          => '',
                'payment_firstname'     => $firstname,
                'payment_lastname'      => $lastname,
                'payment_company'       => '',
                'payment_address_1'     => '',
                'payment_address_2'     => '',
                'payment_city'          => '',
                'payment_postcode'      => '',
                'payment_country'       => '',
                'payment_country_id'    => 0,
                'payment_zone'          => '',
                'payment_zone_id'       => 0,
                'payment_address_format' => '',
                'payment_custom_field'  => '',
                'payment_method'        => $paymentMethod,
                'payment_cost'          => 0,
                'payment_code'          => '',
                'shipping_firstname'    => $shippingFirstname,
                'shipping_lastname'     => $shippingLastname,
                'shipping_company'      => '',
                'shipping_address_1'    => $shippingAddress1,
                'shipping_address_2'    => $shippingAddress2,
                'shipping_city'         => $shippingCity,
                'shipping_postcode'     => $shippingPostcode,
                'shipping_country'      => $shippingCountry,
                'shipping_country_id'   => 0,
                'shipping_zone'         => $shippingZone,
                'shipping_zone_id'      => 0,
                'shipping_address_format' => '',
                'shipping_custom_field' => '',
                'shipping_method'       => $shippingMethod,
                'shipping_cost'         => 0,
                'shipping_code'         => '',
                'comment'               => '',
                'total'                 => $total,
                'extra_cost'            => 0,
                'order_status_id'       => $statusId,
                'affiliate_id'          => 0,
                'commission'            => 0,
                'marketing_id'          => 0,
                'tracking'              => '',
                'language_id'           => 1,
                'currency_id'           => 0,
                'currency_code'         => $currencyCode,
                'currency_value'        => 1,
                'ip'                    => '',
                'forwarded_ip'          => '',
                'user_agent'            => '',
                'accept_language'       => '',
                'courier_id'            => 0,
                'tracking_number'       => $trackingNumber,
                'marketplace_source'    => 'lazada',
                'marketplace_order_id'  => $lazadaOrderId,
                'oe_import'             => 0,
            ];

            if ($catalogOrder) {
                $oldStatusId = (int) $catalogOrder->order_status_id;
                $orderData['date_modified'] = $updatedAt;
                $catalogOrder->update($orderData);
            } else {
                $oldStatusId = 0;
                $orderData['date_added'] = $createdAt;
                $orderData['date_modified'] = $updatedAt;
                $catalogOrder = Order::create($orderData);
            }

            // FCM notifications dispatched centrally by App\Observers\OrderObserver
            // on Order::create / $catalogOrder->update() above.

            // --- Sync products ---
            OrderProduct::where('order_id', $catalogOrder->order_id)->delete();
            OrderOption::where('order_id', $catalogOrder->order_id)->delete();

            $subTotal = 0;
            $shippingTotal = 0;
            $voucherTotal = 0;

            // Group Lazada line items by SKU so qty 5 = one row with quantity 5
            $grouped = [];
            foreach ($lazadaOrder->products as $lp) {
                $lpRaw = is_array($lp->raw) ? $lp->raw : [];
                $key = trim((string) ($lp->sku ?? ''));
                if ($key === '') $key = '_nosku_' . $lp->id;

                $itemPrice = (float) ($lpRaw['item_price'] ?? ($lpRaw['paid_price'] ?? ($lpRaw['price'] ?? 0)));
                $itemQty = max(1, (int) $lp->quantity);

                $shippingTotal += (float) ($lpRaw['shipping_amount'] ?? ($lpRaw['shipping_fee_original'] ?? 0));
                $voucherTotal += (float) ($lpRaw['voucher_amount'] ?? ($lpRaw['voucher_seller'] ?? 0));

                if (!isset($grouped[$key])) {
                    $grouped[$key] = [
                        'sku'       => trim((string) ($lp->sku ?? '')),
                        'name'      => $lp->name ?? '',
                        'variation' => $lp->variation ?? '',
                        'price'     => $itemPrice,
                        'quantity'  => $itemQty,
                    ];
                } else {
                    $grouped[$key]['quantity'] += $itemQty;
                }
            }

            foreach ($grouped as $item) {
                $price = $item['price'];
                $qty = $item['quantity'];
                $lineTotal = $price * $qty;
                $subTotal += $lineTotal;

                // Resolve catalog product_id and option value via seller SKU
                $resolvedProductId = 0;
                $resolvedOptionValueId = 0;
                $sellerSku = $item['sku'];
                if ($sellerSku !== '') {
                    // 1. Try Lazada variant chain
                    $variant = LazadaProductVariant::where('seller_sku', $sellerSku)->first();
                    if ($variant) {
                        $lzProduct = LazadaProduct::where('id', $variant->lazada_product_id)->first();
                        if ($lzProduct && $lzProduct->product_id > 0) {
                            $resolvedProductId = (int) $lzProduct->product_id;
                        }
                        if ($variant->product_option_value_id > 0) {
                            $resolvedOptionValueId = (int) $variant->product_option_value_id;
                        }
                    }

                    // 2. Match option value by SKU on the resolved product
                    if ($resolvedProductId > 0 && $resolvedOptionValueId === 0) {
                        $pov = \App\Models\Catalog\ProductOptionValue::where('product_id', $resolvedProductId)
                            ->where('sku', $sellerSku)
                            ->first();
                        if ($pov) {
                            $resolvedOptionValueId = (int) $pov->product_option_value_id;
                        }
                    }
                }

                // 3. Fallback: resolve option value by variation name
                if ($resolvedProductId > 0 && $resolvedOptionValueId === 0) {
                    $variation = trim($item['variation'] ?? '');
                    if ($variation !== '') {
                        $resolvedOptionValueId = $this->resolveOptionByVariation($resolvedProductId, $variation);
                    }
                }

                $productCost = 0;
                if ($resolvedProductId > 0) {
                    $pfx = (string) config('catalog.prefix');
                    $baseCost = (float) DB::table($pfx . 'product')
                        ->where('product_id', $resolvedProductId)
                        ->value('cost');

                    if ($resolvedOptionValueId > 0) {
                        $optRow = DB::table($pfx . 'product_option_value')
                            ->where('product_option_value_id', $resolvedOptionValueId)
                            ->first(['cost', 'cost_prefix', 'absolute_cost']);
                        if ($optRow) {
                            if ((float) ($optRow->absolute_cost ?? 0) > 0) {
                                $productCost = (float) $optRow->absolute_cost;
                            } else {
                                $delta = (float) $optRow->cost;
                                $productCost = ($optRow->cost_prefix === '-')
                                    ? $baseCost - $delta
                                    : $baseCost + $delta;
                            }
                        } else {
                            $productCost = $baseCost;
                        }
                    } else {
                        $productCost = $baseCost;
                    }
                }

                $op = OrderProduct::create([
                    'order_id'   => $catalogOrder->order_id,
                    'product_id' => $resolvedProductId,
                    'name'       => $item['name'],
                    'model'      => $sellerSku,
                    'quantity'   => $qty,
                    'price'      => $price,
                    'total'      => $lineTotal,
                    'tax'        => 0,
                    'reward'     => 0,
                    'base_price' => $price,
                    'cost'       => $productCost,
                    'supplier_id' => 0,
                ]);

                // Sync variation as order option
                if ($item['variation'] && trim($item['variation']) !== '') {
                    OrderOption::create([
                        'order_id'                => $catalogOrder->order_id,
                        'order_product_id'        => $op->order_product_id,
                        'product_option_id'       => 0,
                        'product_option_value_id' => $resolvedOptionValueId,
                        'name'                    => 'Variation',
                        'value'                   => $item['variation'],
                        'type'                    => 'text',
                    ]);
                }
            }

            // --- Sync order totals ---
            OrderTotal::where('order_id', $catalogOrder->order_id)->delete();

            $sortOrder = 1;
            OrderTotal::create([
                'order_id'   => $catalogOrder->order_id,
                'code'       => 'sub_total',
                'title'      => 'Sub-Total',
                'value'      => $subTotal,
                'sort_order' => $sortOrder++,
            ]);

            if ($shippingTotal != 0) {
                OrderTotal::create([
                    'order_id'   => $catalogOrder->order_id,
                    'code'       => 'shipping',
                    'title'      => 'Shipping',
                    'value'      => $shippingTotal,
                    'sort_order' => $sortOrder++,
                ]);
            }

            if ($voucherTotal != 0) {
                OrderTotal::create([
                    'order_id'   => $catalogOrder->order_id,
                    'code'       => 'voucher',
                    'title'      => 'Voucher',
                    'value'      => -abs($voucherTotal),
                    'sort_order' => $sortOrder++,
                ]);
            }

            OrderTotal::create([
                'order_id'   => $catalogOrder->order_id,
                'code'       => 'total',
                'title'      => 'Total',
                'value'      => $total,
                'sort_order' => $sortOrder,
            ]);

            // --- Add history if status changed ---
            if ($oldStatusId !== $statusId) {
                OrderHistory::create([
                    'order_id'        => $catalogOrder->order_id,
                    'order_status_id' => $statusId,
                    'notify'          => 0,
                    'comment'         => 'Synced from Lazada (status: ' . ($lazadaOrder->status ?? 'unknown') . ')',
                    'date_added'      => now(),
                    'user_id'         => null,
                    'user_name'       => 'Lazada Sync',
                ]);

                ActivityLogger::log('updated', 'Order', $catalogOrder->order_id, 'Lazada #' . ($lazadaOrder->order_id ?? ''), [
                    'order_status_id' => [(string) $oldStatusId, (string) $statusId],
                ], source: 'system');

                // Adjust stock based on status transition
                if (!$this->skipStockAdjust) {
                    OrderStockService::adjustStock($catalogOrder, $oldStatusId, $statusId);
                }
            }

            // --- Save catalog_order_id back ---
            $lazadaOrder->catalog_order_id = $catalogOrder->order_id;
            $lazadaOrder->saveQuietly();
        });
    }

    /**
     * Resolve a product_option_value_id by parsing the variation string and
     * matching against option value names on the product.
     * Handles formats like "Red", "Color Family:Black", "Color: Red, Size: M".
     */
    private function resolveOptionByVariation(int $productId, string $variation): int
    {
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        // Extract candidate value names from the variation string
        $parts = array_map('trim', explode(',', $variation));
        $candidates = [];
        foreach ($parts as $part) {
            if (str_contains($part, ':')) {
                $after = trim(substr($part, strrpos($part, ':') + 1));
                if ($after !== '') {
                    $candidates[] = $after;
                }
            }
            $candidates[] = $part;
        }

        $candidates = array_unique(array_filter($candidates));
        if (empty($candidates)) {
            return 0;
        }

        $match = DB::table($pfx . 'product_option_value as pov')
            ->join($pfx . 'option_value_description as ovd', function ($j) use ($langId) {
                $j->on('pov.option_value_id', '=', 'ovd.option_value_id')
                    ->where('ovd.language_id', '=', $langId);
            })
            ->where('pov.product_id', $productId)
            ->whereIn('ovd.name', $candidates)
            ->first(['pov.product_option_value_id']);

        return $match ? (int) $match->product_option_value_id : 0;
    }
}
