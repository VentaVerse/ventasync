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

            $src = array_merge($raw, $detail);

            $orderStatusMap = LazadaOrderStatusMap::where('context', 'order')->pluck('order_status_id', 'lazada_status')->all();
            $returnStatusMap = LazadaOrderStatusMap::where('context', 'return')->pluck('order_status_id', 'lazada_status')->all();
            $lazadaStatus = strtolower($lazadaOrder->status ?? '');
            $statusId = $orderStatusMap[$lazadaStatus] ?? $returnStatusMap[$lazadaStatus] ?? 1;

            $firstname = trim((string) ($src['customer_first_name'] ?? ''));
            $lastname = trim((string) ($src['customer_last_name'] ?? ''));
            if ($firstname === '') {
                $fullName = trim((string) ($src['customer_name'] ?? ($src['buyer_name'] ?? '')));
                $parts = preg_split('/\s+/', $fullName, 2);
                $firstname = $parts[0] ?? '';
                $lastname = $parts[1] ?? '';
            }

            $addr = $src['address_shipping'] ?? $src['shipping_address'] ?? $src['address'] ?? [];
            if (!is_array($addr)) $addr = [];

            $shippingFirstname = trim((string) ($addr['first_name'] ?? $firstname));
            $shippingLastname = trim((string) ($addr['last_name'] ?? $lastname));

            [$firstname, $lastname, $shippingFirstname, $shippingLastname] = array_map(
                fn (string $name) => mb_substr($name, 0, 32),
                [$firstname, $lastname, $shippingFirstname, $shippingLastname]
            );
            $shippingAddress1 = trim((string) ($addr['address1'] ?? ($addr['address'] ?? '')));
            $shippingAddress2 = trim((string) ($addr['address2'] ?? ''));
            $shippingCity = trim((string) ($addr['city'] ?? ''));
            $shippingPostcode = mb_substr(trim((string) ($addr['post_code'] ?? ($addr['zip_code'] ?? ''))), 0, 10);
            $shippingCountry = trim((string) ($addr['country'] ?? ''));
            $shippingZone = trim((string) ($addr['state'] ?? ($addr['region'] ?? '')));
            $telephone = trim((string) ($addr['phone'] ?? ($addr['phone1'] ?? ($src['receiver_phone'] ?? ''))));

            $email = trim((string) ($src['customer_email'] ?? ''));
            $shippingMethod = trim((string) ($src['shipping_provider'] ?? ($src['shipping_provider_type'] ?? '')));
            $paymentMethod = trim((string) ($src['payment_method'] ?? ''));
            $trackingNumber = trim((string) ($src['tracking_code'] ?? ($src['tracking_number'] ?? '')));
            $total = \App\Support\Money::parse($src['price'] ?? ($src['total'] ?? 0));
            $currencyCode = trim((string) ($src['currency'] ?? 'PHP'));
            if ($currencyCode === '') $currencyCode = 'PHP';

            $currencySvc = app(\App\Services\OrderCurrencyService::class);
            try {
                $currency = $currencySvc->resolve($currencyCode);
            } catch (\InvalidArgumentException $e) {
                \Log::warning("Unknown marketplace currency '{$currencyCode}', defaulting.", ['order' => $lazadaOrder->order_id]);
                $currency = $currencySvc->resolve($currencySvc->defaultCode());
            }

            $lazadaOrderId = (string) $lazadaOrder->order_id;

            $createdAt = $lazadaOrder->order_created_at ?? now();
            $updatedAt = $lazadaOrder->order_updated_at ?? now();

            $catalogOrder = null;

            if ($lazadaOrder->catalog_order_id) {
                $catalogOrder = Order::where('order_id', $lazadaOrder->catalog_order_id)->first();
            }

            if (!$catalogOrder) {
                $catalogOrder = Order::where('marketplace_source', 'lazada')
                    ->where('marketplace_order_id', $lazadaOrderId)
                    ->first();
            }

            // Never overwrite a frozen as-transacted currency_value on re-sync, or it drifts from the stored totals.
            $rate = $currency['rate'];
            $isDefault = $currency['is_default'];
            if ($catalogOrder && !$isDefault && $catalogOrder->foreign_total !== null) {
                $rate = (float) $catalogOrder->currency_value;
            }

            $storeId = (int) $lazadaOrder->lazada_setting_id;
            $storeName = mb_substr((string) (\Extensions\lazada\Models\LazadaSetting::query()->find($storeId)?->store_name ?? ''), 0, 64);

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
                'total'                 => \App\Services\OrderCurrencyService::toBase($total, $rate),
                'foreign_total'         => $isDefault ? null : round($total, \App\Services\OrderCurrencyService::MONEY_SCALE),
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
                'marketplace_source'    => 'lazada',
                'marketplace_order_id'  => $lazadaOrderId,
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
            $voucherTotal = 0;

            $grouped = [];
            foreach ($lazadaOrder->products as $lp) {
                $lpRaw = is_array($lp->raw) ? $lp->raw : [];
                $key = trim((string) ($lp->sku ?? ''));
                if ($key === '') $key = '_nosku_' . $lp->id;

                $itemPrice = \App\Support\Money::parse($lpRaw['item_price'] ?? ($lpRaw['paid_price'] ?? ($lpRaw['price'] ?? 0)));
                $itemQty = max(1, (int) $lp->quantity);

                $shippingTotal += \App\Support\Money::parse($lpRaw['shipping_amount'] ?? ($lpRaw['shipping_fee_original'] ?? 0));
                $voucherTotal += \App\Support\Money::parse($lpRaw['voucher_amount'] ?? ($lpRaw['voucher_seller'] ?? 0));

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

                $priceBase = \App\Services\OrderCurrencyService::toBase($price, $rate);
                $lineTotalBase = \App\Services\OrderCurrencyService::toBase($lineTotal, $rate);

                $resolvedProductId = 0;
                $resolvedOptionValueId = 0;
                $sellerSku = $item['sku'];
                if ($sellerSku !== '') {
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

                    if ($resolvedProductId > 0 && $resolvedOptionValueId === 0) {
                        $pov = \App\Models\Catalog\ProductOptionValue::where('product_id', $resolvedProductId)
                            ->where('sku', $sellerSku)
                            ->first();
                        if ($pov) {
                            $resolvedOptionValueId = (int) $pov->product_option_value_id;
                        }
                    }
                }

                if ($resolvedProductId > 0 && $resolvedOptionValueId === 0) {
                    $variation = trim($item['variation'] ?? '');
                    if ($variation !== '') {
                        $resolvedOptionValueId = $this->resolveOptionByVariation($resolvedProductId, $variation);
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

            OrderTotal::where('order_id', $catalogOrder->order_id)->delete();

            $sortOrder = 1;
            foreach (\Extensions\lazada\Services\LazadaSaleLines::lines($raw, $subTotal, $shippingTotal) as $line) {
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
                    'comment'         => 'Synced from Lazada (status: ' . ($lazadaOrder->status ?? 'unknown') . ')',
                    'date_added'      => now(),
                    'user_id'         => null,
                    'user_name'       => 'Lazada Sync',
                ]);

                ActivityLogger::log('updated', 'Order', $catalogOrder->order_id, 'Lazada #' . ($lazadaOrder->order_id ?? ''), [
                    'order_status_id' => [(string) $oldStatusId, (string) $statusId],
                ], source: 'system');

                if (!$this->skipStockAdjust) {
                    OrderStockService::adjustStock($catalogOrder, $oldStatusId, $statusId);
                }
            }

            $lazadaOrder->catalog_order_id = $catalogOrder->order_id;
            $lazadaOrder->saveQuietly();

            app(\App\Services\Orders\MarketplaceFeeNormalizer::class)->forOrder((int) $catalogOrder->order_id);

        });
    }

    private function resolveOptionByVariation(int $productId, string $variation): int
    {
        $pfx = (string) config('catalog.prefix');
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
