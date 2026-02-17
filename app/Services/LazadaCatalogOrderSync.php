<?php

namespace App\Services;

use App\Models\LazadaOrder;
use App\Models\LazadaProduct;
use App\Models\LazadaProductVariant;
use App\Models\Catalog\Order;
use App\Models\Catalog\OrderHistory;
use App\Models\Catalog\OrderOption;
use App\Models\Catalog\OrderProduct;
use App\Models\Catalog\OrderTotal;
use App\Services\OrderStockService;
use Illuminate\Support\Facades\DB;

class LazadaCatalogOrderSync
{
    /**
     * Lazada status → OpenCart order_status_id mapping.
     */
    private const STATUS_MAP = [
        'unpaid'        => 11, // Awaiting Payment
        'pending'       => 1,  // Pending
        'packed'        => 2,  // Processing
        'ready_to_ship' => 2,  // Processing
        'shipped'       => 3,  // Shipped
        'delivered'     => 9,  // Delivered
        'canceled'      => 5,  // Cancelled
        'cancelled'     => 5,  // Cancelled
        'returned'      => 7,  // Reversed
    ];

    public function sync(LazadaOrder $lazadaOrder): void
    {
        DB::transaction(function () use ($lazadaOrder) {
            $raw = is_array($lazadaOrder->raw) ? $lazadaOrder->raw : [];
            $detail = (isset($raw['_detail']) && is_array($raw['_detail'])) ? $raw['_detail'] : [];

            // Merge: prefer detail over raw list payload
            $src = array_merge($raw, $detail);

            $statusId = self::STATUS_MAP[strtolower($lazadaOrder->status ?? '')] ?? 1;

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

            // --- Sync products ---
            OrderProduct::where('order_id', $catalogOrder->order_id)->delete();
            OrderOption::where('order_id', $catalogOrder->order_id)->delete();

            $subTotal = 0;
            $shippingTotal = 0;
            $voucherTotal = 0;

            foreach ($lazadaOrder->products as $lp) {
                $lpRaw = is_array($lp->raw) ? $lp->raw : [];
                $price = (float) ($lpRaw['item_price'] ?? ($lpRaw['paid_price'] ?? ($lpRaw['price'] ?? 0)));
                $qty = max(1, (int) $lp->quantity);
                $lineTotal = $price * $qty;
                $subTotal += $lineTotal;

                $shippingTotal += (float) ($lpRaw['shipping_amount'] ?? ($lpRaw['shipping_fee_original'] ?? 0));
                $voucherTotal += (float) ($lpRaw['voucher_amount'] ?? ($lpRaw['voucher_seller'] ?? 0));

                // Resolve catalog product_id and option value via seller SKU
                $resolvedProductId = 0;
                $resolvedOptionValueId = 0;
                $sellerSku = trim((string) ($lp->sku ?? ''));
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

                $op = OrderProduct::create([
                    'order_id'   => $catalogOrder->order_id,
                    'product_id' => $resolvedProductId,
                    'name'       => $lp->name ?? '',
                    'model'      => $lp->sku ?? '',
                    'quantity'   => $qty,
                    'price'      => $price,
                    'total'      => $lineTotal,
                    'tax'        => 0,
                    'reward'     => 0,
                    'base_price' => $price,
                    'cost'       => 0,
                    'supplier_id' => 0,
                ]);

                // Sync variation as order option
                if ($lp->variation && trim($lp->variation) !== '') {
                    OrderOption::create([
                        'order_id'                => $catalogOrder->order_id,
                        'order_product_id'        => $op->order_product_id,
                        'product_option_id'       => 0,
                        'product_option_value_id' => $resolvedOptionValueId,
                        'name'                    => 'Variation',
                        'value'                   => $lp->variation,
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
                ]);

                // Adjust stock based on status transition
                OrderStockService::adjustStock($catalogOrder, $oldStatusId, $statusId);
            }

            // --- Save catalog_order_id back ---
            $lazadaOrder->catalog_order_id = $catalogOrder->order_id;
            $lazadaOrder->saveQuietly();
        });
    }
}
