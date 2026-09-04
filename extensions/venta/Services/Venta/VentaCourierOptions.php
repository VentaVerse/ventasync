<?php

namespace Extensions\venta\Services\Venta;

/**
 * What each of the storefront's couriers lets a booking choose, until
 * the storefront's serviceability answer carries the lists itself (#169,
 * addendum item 4). The values are the couriers' own, copied from the
 * storefront's courier modules, so a booking made from the ERP sends
 * exactly what its admin queue would.
 *
 * When the storefront does send the lists, they win and these are never
 * read; a courier the ERP has no lists for offers nothing to choose and
 * the storefront's defaults apply.
 */
final class VentaCourierOptions
{
    private const OPTIONS = [
        'quadx' => [
            'name' => 'QuadX',
            'service_types' => [
                // Same day pickup leads (the owner, 2026-09-04), and both name
                // their pickup for unity.
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
            // Cash on pickup by default (the owner, 2026-08-28).
            'defaults' => ['service' => 'next_day', 'parcel' => 'box', 'shipping_payment' => 'cash'],
        ],
        'spx' => [
            'name' => 'SPX Express',
            'service_types' => [
                ['value' => 'standard', 'label' => 'Standard service'],
            ],
            // SPX's parcel presets are configured per store on the
            // storefront; the storefront must list them (#169).
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

    /**
     * The courier's lists and defaults, with whatever the storefront sent
     * taking precedence field by field.
     *
     * @param  array<string, mixed>  $fromStore  the courier's serviceability entry
     * @return array{name: string, service_types: list<array>, parcel_options: list<array>, shipping_payment_options: list<array>, defaults: array{service: ?string, parcel: ?string, shipping_payment: ?string}}
     */
    public static function for(string $key, array $fromStore = []): array
    {
        $key = strtolower(trim($key));
        $known = self::OPTIONS[$key] ?? [
            'name' => VentaCouriers::name($key),
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
        // A default the list does not offer is no default: the first
        // offered value stands in, so the selects never open on nothing.
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
