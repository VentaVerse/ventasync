<?php

namespace App\Models\Catalog;

class OrderStatus extends BaseModel
{
    protected $primaryKey = 'order_status_id';

    protected $fillable = [
        'language_id',
        'name',
        'subtract_stock',
        'add_revenue',
    ];

    public function getTable()
    {
        return $this->tableName('order_status');
    }
}
