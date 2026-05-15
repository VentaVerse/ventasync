<?php

namespace Extensions\shopee\Services;

use Extensions\shopee\Models\ShopeeOrder;
use Extensions\shopee\Models\ShopeeOrderStatusMap;
use App\Models\Catalog\Order;
use App\Models\Catalog\OrderHistory;
use App\Models\Catalog\OrderOption;
use App\Models\Catalog\OrderProduct;
use App\Models\Catalog\OrderTotal;
use App\Services\OrderStockService;
use Illuminate\Support\Facades\DB;

use App\Services\ActivityLogger;

class ShopeeCatalogOrderSync
{
    private bool $skipStockAdjust = false;

    public function setSkipStockAdjust(bool $skip = true): self
    {
        $this->skipStockAdjust = $skip;
        return $this;
    }

    public function sync(ShopeeOrder $shopeeOrder): void
    {
        DB::transaction(function () use ($shopeeOrder) {
            $raw = is_array($shopeeOrder->raw) ? $shopeeOrder->raw : [];

            $orderStatusMap = ShopeeOrderStatusMap::where('context', 'order')->pluck('order_status_id', 'shopee_status')->all();
            $returnStatusMap = ShopeeOrderStatusMap::where('context', 'return')->pluck('order_status_id', 'shopee_status')->all();
            $shopeeStatus = $shopeeOrder->status ?? '';
            $statusId = $orderStatusMap[$shopeeStatus] ?? $returnStatusMap[$shopeeStatus] ?? 1;

            // --- Customer name ---
            $buyerUsername = trim((string) ($raw['buyer_username'] ?? ($raw['buyer_user_name'] ?? '')));
            $firstname = $buyerUsername;
            $lastname = '';

            // --- Shipping address ---
            $addr = $raw['recipient_address'] ?? $raw['shipping_address'] ?? [];
            if (!is_array($addr)) $addr = [];

            $shippingFirstname = mb_substr(trim((string) ($addr['name'] ?? $firstname)), 0, 128);
            $shippingLastname = '';
            $shippingAddress1 = mb_substr(trim((string) ($addr['full_address'] ?? ($addr['address1'] ?? ''))), 0, 256);
            $shippingAddress2 = '';
            $shippingCity = mb_substr(trim((string) ($addr['city'] ?? ($addr['town'] ?? ''))), 0, 128);
            $shippingPostcode = mb_substr(trim((string) ($addr['zipcode'] ?? ($addr['zip_code'] ?? ''))), 0, 10);
            $shippingCountry = mb_substr(trim((string) ($addr['country'] ?? '')), 0, 128);
            $shippingZone = mb_substr(trim((string) ($addr['state'] ?? ($addr['region'] ?? ''))), 0, 128);
            $telephone = mb_substr(trim((string) ($addr['phone'] ?? '')), 0, 32);

            // --- Other fields ---
            $shippingMethod = trim((string) ($raw['shipping_carrier'] ?? ($raw['checkout_shipping_carrier'] ?? '')));
            $paymentMethod = trim((string) ($raw['payment_method'] ?? ''));
            $trackingNumber = trim((string) ($raw['tracking_no'] ?? ($raw['tracking_number'] ?? '')));
            $apiTotal = (float) ($raw['total_amount'] ?? ($raw['escrow_amount'] ?? 0));
            $currencyCode = trim((string) ($raw['currency'] ?? 'PHP'));
            if ($currencyCode === '') $currencyCode = 'PHP';

            $orderSn = (string) $shopeeOrder->order_sn;

            $createdAt = $shopeeOrder->order_created_at ?? now();
            $updatedAt = $shopeeOrder->order_updated_at ?? now();

            // --- Find or create catalog order ---
            $catalogOrder = null;

            if ($shopeeOrder->catalog_order_id) {
                $catalogOrder = Order::where('order_id', $shopeeOrder->catalog_order_id)->first();
            }

            if (!$catalogOrder) {
                $catalogOrder = Order::where('marketplace_source', 'shopee')
                    ->where('marketplace_order_id', $orderSn)
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
                'email'                 => '',
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
                'total'                 => $apiTotal,
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
                'marketplace_source'    => 'shopee',
                'marketplace_order_id'  => $orderSn,
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

            // Group Shopee line items by SKU
            $grouped = [];
            foreach ($shopeeOrder->products as $sp) {
                $spRaw = is_array($sp->raw) ? $sp->raw : [];
                $key = trim((string) ($sp->sku ?? ''));
                if ($key === '') $key = '_nosku_' . $sp->id;

                $itemPrice = (float) ($sp->price ?? ($spRaw['model_discounted_price'] ?? ($spRaw['model_original_price'] ?? 0)));
                $itemQty = max(1, (int) $sp->quantity);

                $shippingTotal += (float) ($spRaw['shipping_fee'] ?? 0);

                if (!isset($grouped[$key])) {
                    $grouped[$key] = [
                        'sku'       => trim((string) ($sp->sku ?? '')),
                        'name'      => $sp->name ?? '',
                        'variation' => $sp->variation ?? '',
                        'price'     => $itemPrice,
                        'quantity'  => $itemQty,
                    ];
                } else {
                    $grouped[$key]['quantity'] += $itemQty;
                }
            }

            $pfx = (string) config('catalog.prefix');

            foreach ($grouped as $item) {
                $price = $item['price'];
                $qty = $item['quantity'];
                $lineTotal = $price * $qty;
                $subTotal += $lineTotal;

                // Resolve catalog product_id via SKU
                $resolvedProductId = 0;
                $resolvedOptionValueId = 0;
                $sellerSku = $item['sku'];
                if ($sellerSku !== '') {
                    // Try option value SKU match
                    $pov = DB::table($pfx . 'product_option_value')
                        ->where('sku', $sellerSku)
                        ->first();
                    if ($pov) {
                        $resolvedProductId = (int) $pov->product_id;
                        $resolvedOptionValueId = (int) $pov->product_option_value_id;
                    }

                    // Fallback: main product SKU
                    if ($resolvedProductId === 0) {
                        $prod = DB::table($pfx . 'product')
                            ->where('sku', $sellerSku)
                            ->first(['product_id']);
                        if ($prod) {
                            $resolvedProductId = (int) $prod->product_id;
                        }
                    }
                }

                // Fallback: resolve option value by variation name
                if ($resolvedProductId > 0 && $resolvedOptionValueId === 0) {
                    $variation = trim($item['variation'] ?? '');
                    if ($variation !== '') {
                        $resolvedOptionValueId = $this->resolveOptionByVariation($pfx, $resolvedProductId, $variation);
                    }
                }

                $productCost = 0;
                if ($resolvedProductId > 0) {
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

            // --- Compute correct revenue total ---
            // Revenue = item selling prices - seller-funded vouchers
            // Platform vouchers (voucher_from_shopee, coins) do NOT reduce revenue
            // Buyer-paid shipping is NOT revenue
            $feeData = is_array($shopeeOrder->fees) ? $shopeeOrder->fees : [];
            $orderIncome = $feeData['order_income'] ?? $feeData;

            $buyerPaidShipping = abs((float) ($orderIncome['buyer_paid_shipping_fee'] ?? 0));
            $voucherSeller = abs((float) ($orderIncome['voucher_from_seller'] ?? 0));

            // Use escrow shipping data (Shopee products don't have per-item shipping)
            if ($buyerPaidShipping > 0 && $shippingTotal == 0) {
                $shippingTotal = $buyerPaidShipping;
            }

            $total = $subTotal - $voucherSeller;
            $catalogOrder->update(['total' => $total]);

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

            if ($voucherSeller != 0) {
                OrderTotal::create([
                    'order_id'   => $catalogOrder->order_id,
                    'code'       => 'voucher',
                    'title'      => 'Seller Voucher',
                    'value'      => -$voucherSeller,
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
                    'comment'         => 'Synced from Shopee (status: ' . ($shopeeOrder->status ?? 'unknown') . ')',
                    'date_added'      => now(),
                    'user_id'         => null,
                    'user_name'       => 'Shopee Sync',
                ]);

                ActivityLogger::log('updated', 'Order', $catalogOrder->order_id, 'Shopee #' . ($shopeeOrder->order_sn ?? ''), [
                    'order_status_id' => [(string) $oldStatusId, (string) $statusId],
                ], source: 'system');

                if (!$this->skipStockAdjust) {
                    OrderStockService::adjustStock($catalogOrder, $oldStatusId, $statusId);
                }
            }

            // --- Save catalog_order_id back ---
            $shopeeOrder->catalog_order_id = $catalogOrder->order_id;
            $shopeeOrder->saveQuietly();
        });
    }

    /**
     * Resolve a product_option_value_id by parsing the variation string and
     * matching against option value names on the product.
     * Handles formats like "Red", "Color Family:Black", "Color: Red, Size: M".
     */
    private function resolveOptionByVariation(string $pfx, int $productId, string $variation): int
    {
        $langId = (int) config('catalog.default_language_id');

        // Extract candidate value names from the variation string
        // Split by comma first (handles "Color: Red, Size: M")
        $parts = array_map('trim', explode(',', $variation));
        $candidates = [];
        foreach ($parts as $part) {
            // If it contains ":" or "Family:", take the part after the last ":"
            if (str_contains($part, ':')) {
                $after = trim(substr($part, strrpos($part, ':') + 1));
                if ($after !== '') {
                    $candidates[] = $after;
                }
            }
            // Also try the whole part as-is
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
