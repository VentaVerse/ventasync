<?php

namespace App\Models\Catalog;

class Order extends BaseModel
{
    protected $primaryKey = 'order_id';

    protected $fillable = [
        'invoice_no',
        'invoice_prefix',
        'store_id',
        'store_name',
        'store_url',
        'customer_id',
        'customer_group_id',
        'firstname',
        'lastname',
        'email',
        'telephone',
        'fax',
        'custom_field',
        'payment_firstname',
        'payment_lastname',
        'payment_company',
        'payment_address_1',
        'payment_address_2',
        'payment_city',
        'payment_postcode',
        'payment_country',
        'payment_country_id',
        'payment_zone',
        'payment_zone_id',
        'payment_address_format',
        'payment_custom_field',
        'payment_method',
        'payment_cost',
        'payment_code',
        'shipping_firstname',
        'shipping_lastname',
        'shipping_company',
        'shipping_address_1',
        'shipping_address_2',
        'shipping_city',
        'shipping_postcode',
        'shipping_country',
        'shipping_country_id',
        'shipping_zone',
        'shipping_zone_id',
        'shipping_address_format',
        'shipping_custom_field',
        'shipping_method',
        'shipping_cost',
        'shipping_code',
        'comment',
        'total',
        'extra_cost',
        'order_status_id',
        'affiliate_id',
        'commission',
        'marketing_id',
        'tracking',
        'language_id',
        'currency_id',
        'currency_code',
        'currency_value',
        'ip',
        'forwarded_ip',
        'user_agent',
        'accept_language',
        'courier_id',
        'tracking_number',
        'date_added',
        'date_modified',
        'oe_import',
        'marketplace_source',
        'marketplace_order_id',
        'track_payments',
    ];

    protected $casts = [
        'track_payments' => 'boolean',
    ];

    public function getTable()
    {
        return $this->tableName('order');
    }

    public function status()
    {
        return $this->belongsTo(OrderStatus::class, 'order_status_id', 'order_status_id');
    }

    public function products()
    {
        return $this->hasMany(OrderProduct::class, 'order_id', 'order_id');
    }

    public function history()
    {
        return $this->hasMany(OrderHistory::class, 'order_id', 'order_id');
    }

    public function latestHistory()
    {
        return $this->hasOne(OrderHistory::class, 'order_id', 'order_id')
            ->whereNotNull('user_name')
            ->orderByDesc('date_added')
            ->orderByDesc('order_history_id');
    }

    public function totals()
    {
        return $this->hasMany(OrderTotal::class, 'order_id', 'order_id');
    }

    public function payments()
    {
        return $this->hasMany(\App\Models\OrderPayment::class, 'order_id', 'order_id');
    }
}
