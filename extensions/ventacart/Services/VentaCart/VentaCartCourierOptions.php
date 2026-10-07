<?php

namespace Extensions\ventacart\Services\VentaCart;

final class VentaCartCourierOptions
{
    private const OPTIONS = [
        'quadx' => [
            'name' => 'QuadX',
            'service_types' => [
                ['value' => 'same_day_pickup', 'label' => 'Same day pickup'],
                ['value' => 'next_day', 'label' => 'Next day pickup'],
            ],
            'parcel_options' => [
                ['value' => 'small-pouch', 'label' => 'White small pouch'],
                ['value' => 'medium-pouch', 'label' => 'Blue medium pouch'],
                ['value' => 'big-pouch', 'label' => 'Red large pouch'],
                ['value' => 'box', 'label' => 'Box'],
                ['value' => 'oversized', 'label' => 'Oversized'],
            ],
            'shipping_payment_options' => [
                ['value' => 'cash', 'label' => 'Cash on pickup'],
                ['value' => 'gcash', 'label' => 'GCash at pickup'],
                ['value' => 'net_off', 'label' => 'Deduct from remittance, or bill me'],
            ],
            'defaults' => ['service' => 'next_day', 'parcel' => 'box', 'shipping_payment' => 'cash'],
        ],
        'spx' => [
            'name' => 'SPX Express',
            'service_types' => [
                ['value' => 'standard', 'label' => 'Standard service'],
            ],
            'parcel_options' => [],
            'shipping_payment_options' => [
                ['value' => 'sender_pay', 'label' => 'Sender pays the shipping fee'],
                ['value' => 'receiver_pay', 'label' => 'Customer pays the shipping fee'],
            ],
            'defaults' => ['service' => 'standard', 'parcel' => null, 'shipping_payment' => 'sender_pay'],
        ],
        'lbc' => [
            'name' => 'LBC',
            'service_types' => [],
            'parcel_options' => [],
            'shipping_payment_options' => [],
            'defaults' => ['service' => null, 'parcel' => null, 'shipping_payment' => null],
        ],
    ];

    public static function for(string $key, array $fromStore = []): array
    {
        $key = strtolower(trim($key));
        $known = self::OPTIONS[$key] ?? [
            'name' => VentaCartCouriers::name($key),
            'service_types' => [], 'parcel_options' => [], 'shipping_payment_options' => [],
            'defaults' => ['service' => null, 'parcel' => null, 'shipping_payment' => null],
        ];

        $lists = [];
        foreach (['service_types', 'parcel_options', 'shipping_payment_options'] as $list) {
            $sent = $fromStore[$list] ?? null;
            $lists[$list] = is_array($sent) && $sent !== [] ? array_values($sent) : $known[$list];
        }

        $defaults = $known['defaults'];
        if (is_array($fromStore['defaults'] ?? null)) {
            $defaults = array_merge($defaults, array_filter($fromStore['defaults'], fn ($v) => $v !== null && $v !== ''));
        }
        foreach (['service' => 'service_types', 'parcel' => 'parcel_options', 'shipping_payment' => 'shipping_payment_options'] as $field => $list) {
            $values = array_column($lists[$list], 'value');
            if ($values !== [] && ! in_array($defaults[$field], $values, true)) {
                $defaults[$field] = $values[0];
            }
        }

        return [
            'name' => (string) ($fromStore['name'] ?? $known['name']),
            'service_types' => $lists['service_types'],
            'parcel_options' => $lists['parcel_options'],
            'shipping_payment_options' => $lists['shipping_payment_options'],
            'defaults' => $defaults,
        ];
    }
}
