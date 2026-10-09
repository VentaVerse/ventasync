<?php

namespace App\Models\Catalog;

class OrderStatus extends BaseModel
{
    protected $table = 'order_statuses';

    protected $primaryKey = 'order_status_id';

    protected $fillable = [
        'language_id',
        'name',
        'subtract_stock',
        'add_revenue',
    ];
}
