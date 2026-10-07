<?php

namespace App\Support;

class ChannelStatusTone
{
    private const TONES = [
        'shopee.orders' => [
            'UNPAID'             => 'warning',
            'READY_TO_SHIP'      => 'warning',
            'PROCESSED'          => 'warning',
            'SHIPPED'            => 'info',
            'TO_CONFIRM_RECEIVE' => 'info',
            'COMPLETED'          => 'success',
            'CANCELLED'          => 'danger',
            'IN_CANCEL'          => 'danger',
            'RETRY_SHIP'         => 'warning',
        ],

        'shopee.returns' => [
            'REQUESTED'           => 'warning',
            'PROCESSING'          => 'warning',
            'ACCEPTED'            => 'warning',
            'JUDGING'             => 'info',
            'SELLER_DISPUTE'      => 'info',
            'REFUND_PAID'         => 'success',
            'SELLER_COMPENSATION' => 'success',
            'CLOSED'              => 'neutral',
            'CANCELLED'           => 'danger',
        ],

        'lazada.orders' => [
            'UNPAID'               => 'warning',
            'PENDING'              => 'warning',
            'REPACKED'             => 'warning',
            'PACKED'               => 'warning',
            'READY_TO_SHIP'        => 'info',
            'SHIPPED'              => 'info',
            'DELIVERED'            => 'success',
            'CONFIRMED'            => 'success',
            'CANCELED'             => 'danger',
            'CANCELLED'            => 'danger',
            'FAILED_DELIVERY'      => 'danger',
            'LOST_BY_3PL'          => 'danger',
            'DAMAGED_BY_3PL'       => 'danger',
            'SHIPPED_BACK'         => 'warning',
            'SHIPPED_BACK_SUCCESS' => 'neutral',
        ],

        'tiktok.orders' => [
            'UNPAID'               => 'warning',
            'AWAITING_SHIPMENT'    => 'warning',
            'AWAITING_COLLECTION'  => 'info',
            'IN_TRANSIT'           => 'info',
            'DELIVERED'            => 'success',
            'COMPLETED'            => 'success',
            'CANCELLED'            => 'danger',
        ],

        'pedallion.orders' => [
            'PENDING'            => 'warning',
            'PROCESSING'         => 'warning',
            'AWAITING_SHIPMENT'  => 'warning',
            'READY_TO_SHIP'      => 'warning',
            'SHIPPED'            => 'info',
            'DELIVERED'          => 'success',
            'COMPLETED'          => 'success',
            'PAID'               => 'success',
            'CANCELLED'          => 'danger',
            'REFUNDED'           => 'danger',
            'FAILED'             => 'danger',
        ],

        'shopify.payment' => [
            'PAID'               => 'success',
            'AUTHORIZED'         => 'info',
            'PENDING'            => 'info',
            'PARTIALLY_PAID'     => 'info',
            'REFUNDED'           => 'danger',
            'PARTIALLY_REFUNDED' => 'danger',
            'VOIDED'             => 'danger',
        ],

        'shopify.fulfilment' => [
            'UNFULFILLED' => 'warning',
            'PARTIAL'     => 'warning',
            'FULFILLED'   => 'success',
        ],

        'woocommerce.orders' => [
            'PENDING'    => 'info',
            'ON-HOLD'    => 'info',
            'PROCESSING' => 'warning',
            'COMPLETED'  => 'success',
            'REFUNDED'   => 'success',
            'CANCELLED'  => 'danger',
            'FAILED'     => 'danger',
        ],
        'ventacart.orders' => [
            'PENDING'              => 'info',
            'FOR_VERIFICATION'     => 'warning',
            'PROCESSING'           => 'warning',
            'START_PICKUP'         => 'warning',
            'SHIPPED'              => 'info',
            'IN_TRANSIT_TO_MANILA' => 'info',
            'TO_CONFIRM_RECEIVE'   => 'info',
            'DELIVERED'            => 'success',
            'COMPLETE'             => 'success',
            'COMPLETED'            => 'success',
            'REFUNDED'             => 'success',
            'RETURN_IN_PROGRESS'   => 'warning',
            'RETURNED'             => 'info',
            'CANCELED'             => 'danger',
            'CANCELLED'            => 'danger',
            'DENIED'               => 'danger',
            'FAILED'               => 'danger',
            'REVERSED'             => 'danger',
        ],

        'lazada.returns' => [
            'RETURN_INITIATED'   => 'warning',
            'REQUESTED'          => 'warning',
            'PENDING'            => 'warning',
            'IN_PROGRESS'        => 'warning',
            'RETURN_IN_PROGRESS' => 'warning',
            'PROCESSING'         => 'warning',
            'RECEIVED'           => 'warning',
            'APPROVED'           => 'info',
            'SHIPPED_BACK'       => 'info',
            'DISPUTE'            => 'info',
            'DISPUTE_IN_PROGRESS' => 'info',
            'REFUND_ISSUED'      => 'success',
            'REFUND_PAID'        => 'success',
            'REFUNDED'           => 'success',
            'REFUND_SUCCESS'     => 'success',
            'REQUEST_CANCEL'     => 'danger',
            'REFUND_REJECT'      => 'danger',
            'CLOSED'             => 'neutral',
            'COMPLETED'          => 'success',
            'REJECTED'           => 'danger',
            'CANCELLED'          => 'danger',
            'CANCELED'           => 'danger',
        ],

        'purchasing.orders' => [
            'DRAFT'              => 'neutral',
            'APPROVED'           => 'success',
            'ORDERED'            => 'warning',
            'PARTIALLY_RECEIVED' => 'success',
            'RECEIVED'           => 'neutral',
            'CANCELLED'          => 'danger',
            'VOIDED'             => 'danger',
        ],

        'warehousing.transfers' => [
            'DRAFT'       => 'neutral',
            'IN_PROGRESS' => 'success',
            'COMPLETED'   => 'neutral',
            'CANCELLED'   => 'danger',
            'VOIDED'      => 'danger',
        ],

        'opencart.reviews' => [
            'PENDING' => 'success',
            'PUSHED'  => 'neutral',
            'SKIPPED' => 'neutral',
            'ERROR'   => 'danger',
        ],

        'reports.payouts' => [
            'PAID'       => 'neutral',
            'RELEASING'  => 'info',
            'IN_TRANSIT' => 'info',
            'UNPAID'     => 'warning',
            'NOT_SHIPPED' => 'warning',
            'PAYMENT_FAILED' => 'warning',
            'NO_PAYOUT_FOUND' => 'warning',
            'CANCELLED'  => 'danger',
            'RETURNED'   => 'danger',
            'UNKNOWN'    => 'neutral',
        ],
    ];

    private const LABELS = [
        'shopee.orders' => [
            'UNPAID'             => 'Unpaid',
            'READY_TO_SHIP'      => 'Ready to ship',
            'PROCESSED'          => 'To hand over',
            'SHIPPED'            => 'Shipped',
            'TO_CONFIRM_RECEIVE' => 'Awaiting confirmation',
            'COMPLETED'          => 'Completed',
            'CANCELLED'          => 'Cancelled',
            'IN_CANCEL'          => 'Cancelling',
            'RETRY_SHIP'         => 'Delivery failed',
        ],

        'shopee.returns' => [
            'REQUESTED'           => 'Requested',
            'PROCESSING'          => 'Processing',
            'ACCEPTED'            => 'Accepted',
            'JUDGING'             => 'Under review',
            'SELLER_DISPUTE'      => 'Disputed',
            'REFUND_PAID'         => 'Refunded',
            'SELLER_COMPENSATION' => 'Compensated',
            'CLOSED'              => 'Closed',
            'CANCELLED'           => 'Cancelled',
        ],

        'woocommerce.orders' => [
            'PENDING'    => 'Pending payment',
            'ON-HOLD'    => 'On hold',
            'PROCESSING' => 'Processing',
            'COMPLETED'  => 'Completed',
            'CANCELLED'  => 'Cancelled',
            'REFUNDED'   => 'Refunded',
            'FAILED'     => 'Failed',
        ],
        'ventacart.orders' => [
            'PENDING'              => 'Unpaid',
            'FOR_VERIFICATION'     => 'Checking payment',
            'PROCESSING'           => 'To pack',
            'START_PICKUP'         => 'Ready for pickup',
            'SHIPPED'              => 'Shipped',
            'IN_TRANSIT_TO_MANILA' => 'In transit',
            'DELIVERED'            => 'Delivered',
            'COMPLETE'             => 'Completed',
            'COMPLETED'            => 'Completed',
            'TO_CONFIRM_RECEIVE'   => 'Awaiting confirmation',
            'CANCELED'             => 'Cancelled',
            'CANCELLED'            => 'Cancelled',
            'DENIED'               => 'Refused',
            'FAILED'               => 'Failed',
            'REVERSED'             => 'Reversed',
            'RETURN_IN_PROGRESS'   => 'Return in progress',
            'RETURNED'             => 'Returned',
            'REFUNDED'             => 'Refunded',
        ],

        'lazada.payment' => [
            'COD' => 'Cash on delivery',
            'GCASH_PP' => 'GCash',
            'MIXEDCARD' => 'Card',
            'PAY_LATER' => 'Pay later',
            'QRPH' => 'QR Ph',
            'WALLET_PAYMAYA2C2P' => 'Maya wallet',
        ],

        'lazada.reverse_type' => [
            'RETURN' => 'Return',
            'CANCEL' => 'Cancellation',
        ],

        'lazada.reverse_logistics' => [
            'RETURN_DELIVERED' => 'Back with us',
            'RETURN_CANCELED'  => 'Return cancelled',
            'RETURN_SCRAPPED'  => 'Disposed by Lazada',
        ],

        'shopee.return_reason' => [
            'CHANGE_MIND'       => 'Changed mind',
            'CHANGE_OF_MIND'    => 'Changed mind',
            'WRONG_ITEM'        => 'Wrong item sent',
            'ITEM_MISSING'      => 'Item missing',
            'FUNCTIONAL_DMG'    => 'Item not working',
            'DAMAGED_OTHERS'    => 'Item damaged',
            'NOT_RECEIPT'       => 'Never arrived',
            'SUSPICIOUS_PARCEL' => 'Parcel looked tampered with',
        ],

        'shopee.reverse_logistics' => [
            'LOGISTICS_NOT_STARTED'       => 'Not shipped back yet',
            'LOGISTICS_PENDING_ARRANGE'   => 'Pickup being arranged',
            'LOGISTICS_REQUEST_CREATED'   => 'Pickup booked',
            'LOGISTICS_PICKUP_DONE'       => 'Picked up',
            'LOGISTICS_DELIVERY_DONE'     => 'Back with us',
            'LOGISTICS_REQUEST_CANCELED'  => 'Return cancelled',
            'DELIVERED'                   => 'Back with us',
        ],

        'lazada.orders' => [
            'UNPAID'               => 'Unpaid',
            'PENDING'              => 'To pack',
            'REPACKED'             => 'To pack again',
            'PACKED'               => 'To arrange shipment',
            'READY_TO_SHIP'        => 'To hand over',
            'SHIPPED'              => 'Shipped',
            'DELIVERED'            => 'Delivered',
            'CONFIRMED'            => 'Received by buyer',
            'CANCELED'             => 'Cancelled',
            'CANCELLED'            => 'Cancelled',
            'FAILED_DELIVERY'      => 'Delivery failed',
            'LOST_BY_3PL'          => 'Lost by courier',
            'DAMAGED_BY_3PL'       => 'Damaged by courier',
            'SHIPPED_BACK'         => 'On its way back',
            'SHIPPED_BACK_SUCCESS' => 'Returned to you',
        ],

        'tiktok.orders' => [
            'UNPAID'               => 'Unpaid',
            'AWAITING_SHIPMENT'    => 'To pack',
            'AWAITING_COLLECTION'  => 'To hand over',
            'IN_TRANSIT'           => 'Shipped',
            'DELIVERED'            => 'Delivered',
            'COMPLETED'            => 'Completed',
            'CANCELLED'            => 'Cancelled',
        ],

        'pedallion.orders' => [
            'PENDING'            => 'To process',
            'PROCESSING'         => 'Processing',
            'AWAITING_SHIPMENT'  => 'To pack',
            'READY_TO_SHIP'      => 'To hand over',
            'SHIPPED'            => 'Shipped',
            'DELIVERED'          => 'Delivered',
            'COMPLETED'          => 'Completed',
            'PAID'               => 'Paid',
            'CANCELLED'          => 'Cancelled',
            'REFUNDED'           => 'Refunded',
            'FAILED'             => 'Failed',
        ],

        'shopify.payment' => [
            'PAID'               => 'Paid',
            'AUTHORIZED'         => 'Authorised',
            'PENDING'            => 'Unpaid',
            'PARTIALLY_PAID'     => 'Partly paid',
            'REFUNDED'           => 'Refunded',
            'PARTIALLY_REFUNDED' => 'Partly refunded',
            'VOIDED'             => 'Voided',
        ],

        'shopify.fulfilment' => [
            'UNFULFILLED' => 'To pack',
            'PARTIAL'     => 'Partly packed',
            'FULFILLED'   => 'Packed',
        ],

        'lazada.returns' => [
            'RETURN_INITIATED'    => 'Return started',
            'REQUESTED'           => 'Requested',
            'PENDING'             => 'Awaiting review',
            'IN_PROGRESS'         => 'In progress',
            'RETURN_IN_PROGRESS'  => 'In progress',
            'PROCESSING'          => 'Processing',
            'RECEIVED'            => 'Item received',
            'APPROVED'            => 'Approved',
            'SHIPPED_BACK'        => 'On its way back',
            'DISPUTE'             => 'Disputed',
            'DISPUTE_IN_PROGRESS' => 'Disputed',
            'REFUND_ISSUED'       => 'Refunded',
            'REFUND_PAID'         => 'Refunded',
            'REFUNDED'            => 'Refunded',
            'CLOSED'              => 'Closed',
            'COMPLETED'           => 'Completed',
            'REJECTED'            => 'Rejected',
            'CANCELLED'           => 'Cancelled',
            'CANCELED'            => 'Cancelled',
                    'REFUND_SUCCESS'     => 'Refunded',
            'REQUEST_CANCEL'     => 'Cancellation requested',
            'REFUND_REJECT'      => 'Refund refused',
],

        'purchasing.orders' => [
            'DRAFT'              => 'Draft',
            'APPROVED'           => 'Approved',
            'ORDERED'            => 'Ordered',
            'PARTIALLY_RECEIVED' => 'Partly received',
            'RECEIVED'           => 'Received',
            'CANCELLED'          => 'Cancelled',
            'VOIDED'             => 'Voided',
        ],

        'warehousing.transfers' => [
            'DRAFT'       => 'Draft',
            'IN_PROGRESS' => 'In progress',
            'COMPLETED'   => 'Completed',
            'CANCELLED'   => 'Cancelled',
            'VOIDED'      => 'Voided',
        ],

        'opencart.reviews' => [
            'PENDING' => 'Not pushed',
            'PUSHED'  => 'Pushed',
            'SKIPPED' => 'Skipped',
            'ERROR'   => 'Push failed',
        ],

        'reports.payouts' => [
            'PAID'       => 'Paid',
            'RELEASING'  => 'Releasing',
            'IN_TRANSIT' => 'In transit',
            'UNPAID'     => 'Unpaid',
            'NOT_SHIPPED' => 'Not shipped',
            'PAYMENT_FAILED' => 'Payment failed',
            'NO_PAYOUT_FOUND' => 'No payout found',
            'CANCELLED'  => 'Cancelled',
            'RETURNED'   => 'Returned',
            'UNKNOWN'    => 'Unknown',
        ],
    ];

    private static function key(?string $status): string
    {
        return strtoupper(str_replace(' ', '_', trim((string) $status)));
    }

    public static function toneFor(string $surface, ?string $status): string
    {
        return self::TONES[$surface][self::key($status)] ?? 'neutral';
    }

    public static function labelFor(string $surface, ?string $status): string
    {
        $raw = (string) $status;
        $key = self::key($raw);

        if (isset(self::LABELS[$surface][$key])) {
            return self::LABELS[$surface][$key];
        }

        return $raw !== ''
            ? ucfirst(strtolower(str_replace('_', ' ', $key)))
            : 'Unknown';
    }
}
