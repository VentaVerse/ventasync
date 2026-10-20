<?php

namespace App\Models\Catalog;

class Order extends BaseModel
{
    protected $table = 'orders';

    protected static function booted(): void
    {
        static::creating(fn () => \App\Plans\Quota::refuseOrder());
    }

    protected $primaryKey = 'order_id';

    protected $fillable = [
        'store_id',
        'store_name',
        'firstname',
        'lastname',
        'email',
        'telephone',
        'customer_tin',
        'payment_firstname',
        'payment_lastname',
        'payment_company',
        'payment_address_1',
        'payment_address_2',
        'payment_city',
        'payment_postcode',
        'payment_country',
        'payment_zone',
        'payment_method',
        'payment_cost',
        'shipping_firstname',
        'shipping_lastname',
        'shipping_company',
        'shipping_address_1',
        'shipping_address_2',
        'shipping_city',
        'shipping_postcode',
        'shipping_country',
        'shipping_zone',
        'shipping_method',
        'shipping_cost',
        'customer_note',
        'total',
        'foreign_total',
        'extra_cost',
        'order_status_id',
        'currency_id',
        'currency_code',
        'currency_value',
        'ip',
        'courier_id',
        'tracking_number',
        'tracking_url',
        'date_added',
        'date_modified',
        'channel',
        'channel_order_id',
        'track_payments',
    ];

    protected $casts = [
        'track_payments' => 'boolean',
    ];

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

    public static function cleanTrackingUrl(mixed $url): ?string
    {
        if (! is_string($url)) {
            return null;
        }

        $url = trim($url);
        if ($url === '' || strlen($url) > 500 || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        return in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true) ? $url : null;
    }

    public function trackingHref(): ?string
    {
        if (trim((string) $this->tracking_number) === '') {
            return null;
        }

        return self::cleanTrackingUrl($this->tracking_url);
    }
}
