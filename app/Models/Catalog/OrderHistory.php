<?php

namespace App\Models\Catalog;

class OrderHistory extends BaseModel
{
    protected $primaryKey = 'order_history_id';

    protected $fillable = [
        'order_id',
        'order_status_id',
        'user_id',
        'user_name',
        'notify',
        'comment',
        'date_added',
        'slug',
        'tracking_number',
        'powertrack_carrier',
        'powertrack_trackcode',
    ];

    public function getTable()
    {
        return $this->tableName('order_history');
    }

    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id', 'order_id');
    }

    public function status()
    {
        return $this->belongsTo(OrderStatus::class, 'order_status_id', 'order_status_id');
    }
}
