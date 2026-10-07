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

            $buyerUsername = trim((string) ($raw['buyer_username'] ?? ($raw['buyer_user_name'] ?? '')));
            $firstname = $buyerUsername;
            $lastname = '';

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

            $shippingMethod = trim((string) ($raw['shipping_carrier'] ?? ($raw['checkout_shipping_carrier'] ?? '')));
            $paymentMethod = trim((string) ($raw['payment_method'] ?? ''));
            $trackingNumber = trim((string) ($raw['tracking_no'] ?? ($raw['tracking_number'] ?? '')));
            $apiTotal = (float) ($raw['total_amount'] ?? ($raw['escrow_amount'] ?? 0));
            $currencyCode = trim((string) ($raw['currency'] ?? 'PHP'));
            if ($currencyCode === '') $currencyCode = 'PHP';

            $currencySvc = app(\App\Services\OrderCurrencyService::class);
            try {
                $currency = $currencySvc->resolve($currencyCode);
            } catch (\InvalidArgumentException $e) {
                \Log::warning("Unknown marketplace currency '{$currencyCode}', defaulting.", ['order' => $shopeeOrder->order_sn]);
                $currency = $currencySvc->resolve($currencySvc->defaultCode());
            }

            $orderSn = (string) $shopeeOrder->order_sn;

            $createdAt = $shopeeOrder->order_created_at ?? now();
            $updatedAt = $shopeeOrder->order_updated_at ?? now();

            $catalogOrder = null;

            if ($shopeeOrder->catalog_order_id) {
                $catalogOrder = Order::where('order_id', $shopeeOrder->catalog_order_id)->first();
            }

            if (!$catalogOrder) {
                $catalogOrder = Order::where('marketplace_source', 'shopee')
                    ->where('marketplace_order_id', $orderSn)
                    ->first();
            }

            // Once an order is normalized its currency_value is a frozen rate; a re-sync must not overwrite it
            // with today's rate, nor apply it if the order is no longer foreign.
            $rate = $currency['rate'];
            $isDefault = $currency['is_default'];
            if ($catalogOrder && !$isDefault && $catalogOrder->foreign_total !== null) {
                $rate = (float) $catalogOrder->currency_value;
            }

            $storeId = (int) $shopeeOrder->shopee_setting_id;
            $storeName = mb_substr((string) (\Extensions\shopee\Models\ShopeeSetting::query()->find($storeId)?->store_name ?? ''), 0, 64);

            $orderData = [
                'invoice_no'            => 0,
                'invoice_prefix'        => '',
                'store_id'              => $storeId,
                'store_name'            => $storeName,
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
                'currency_id'           => $currency['id'],
                'currency_code'         => $currency['code'],
                'currency_value'        => $rate,
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
                if ((int) ($catalogOrder->sync_override ?? 0) === 1) {
                    $statusId = $oldStatusId;
                    $orderData['order_status_id'] = $oldStatusId;
                }
                $orderData['date_modified'] = $updatedAt;
                $catalogOrder->update($orderData);
            } else {
                $oldStatusId = 0;
                $orderData['date_added'] = $createdAt;
                $orderData['date_modified'] = $updatedAt;
                $catalogOrder = Order::create($orderData);
            }

            OrderProduct::where('order_id', $catalogOrder->order_id)->delete();
            OrderOption::where('order_id', $catalogOrder->order_id)->delete();

            $subTotal = 0;
            $shippingTotal = 0;

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

                $priceBase = \App\Services\OrderCurrencyService::toBase($price, $rate);
                $lineTotalBase = \App\Services\OrderCurrencyService::toBase($lineTotal, $rate);

                $resolvedProductId = 0;
                $resolvedOptionValueId = 0;
                $sellerSku = $item['sku'];
                if ($sellerSku !== '') {
                    $pov = DB::table($pfx . 'product_option_value')
                        ->where('sku', $sellerSku)
                        ->first();
                    if ($pov) {
                        $resolvedProductId = (int) $pov->product_id;
                        $resolvedOptionValueId = (int) $pov->product_option_value_id;
                    }

                    if ($resolvedProductId === 0) {
                        $prod = DB::table($pfx . 'product')
                            ->where('sku', $sellerSku)
                            ->first(['product_id']);
                        if ($prod) {
                            $resolvedProductId = (int) $prod->product_id;
                        }
                    }
                }

                if ($resolvedProductId > 0 && $resolvedOptionValueId === 0) {
                    $variation = trim($item['variation'] ?? '');
                    if ($variation !== '') {
                        $resolvedOptionValueId = $this->resolveOptionByVariation($pfx, $resolvedProductId, $variation);
                    }
                }

                $productCost = \App\Support\LineCost::resolve((int) $resolvedProductId, (int) $resolvedOptionValueId);

                $op = OrderProduct::create([
                    'order_id'      => $catalogOrder->order_id,
                    'product_id'    => $resolvedProductId,
                    'name'          => $item['name'],
                    'model'         => $sellerSku,
                    'quantity'      => $qty,
                    'price'         => $priceBase,
                    'foreign_price' => $isDefault ? null : round($price, \App\Services\OrderCurrencyService::MONEY_SCALE),
                    'total'         => $lineTotalBase,
                    'foreign_total' => $isDefault ? null : round($lineTotal, \App\Services\OrderCurrencyService::MONEY_SCALE),
                    'tax'           => 0,
                    'reward'        => 0,
                    'base_price'    => $priceBase,
                    'cost'          => $productCost,
                    'supplier_id'   => 0,
                ]);

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

            $feeData = is_array($shopeeOrder->fees) ? $shopeeOrder->fees : [];
            $orderIncome = $feeData['order_income'] ?? $feeData;

            $buyerPaidShipping = abs((float) ($orderIncome['buyer_paid_shipping_fee'] ?? 0));
            $voucherSeller = abs((float) ($orderIncome['voucher_from_seller'] ?? 0));

            if ($buyerPaidShipping > 0 && $shippingTotal == 0) {
                $shippingTotal = $buyerPaidShipping;
            }

            $total = $subTotal - $voucherSeller;
            $catalogOrder->update([
                'total'         => \App\Services\OrderCurrencyService::toBase($total, $rate),
                'foreign_total' => $isDefault ? null : round($total, \App\Services\OrderCurrencyService::MONEY_SCALE),
            ]);

            OrderTotal::where('order_id', $catalogOrder->order_id)->delete();

            $sortOrder = 1;
            foreach (\Extensions\shopee\Services\ShopeeSaleLines::lines(is_array($orderIncome) ? $orderIncome : [], $subTotal, $shippingTotal) as $line) {
                OrderTotal::create([
                    'order_id'   => $catalogOrder->order_id,
                    'code'       => $line['code'],
                    'title'      => $line['title'],
                    'value'      => \App\Services\OrderCurrencyService::toBase($line['value'], $rate),
                    'sort_order' => $sortOrder++,
                ]);
            }

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

            $shopeeOrder->catalog_order_id = $catalogOrder->order_id;
            $shopeeOrder->saveQuietly();

            app(\App\Services\Orders\MarketplaceFeeNormalizer::class)->forOrder((int) $catalogOrder->order_id);

        });
    }

    private function resolveOptionByVariation(string $pfx, int $productId, string $variation): int
    {
        $langId = (int) config('catalog.default_language_id');

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
