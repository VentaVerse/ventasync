<?php

namespace App\Models\Catalog;

class OrderTotal extends BaseModel
{
    protected $primaryKey = 'order_total_id';

    protected $fillable = [
        'order_id',
        'code',
        'title',
        'value',
        'sort_order',
    ];

    public function getTable()
    {
        return $this->tableName('order_total');
    }

    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id', 'order_id');
    }
}
