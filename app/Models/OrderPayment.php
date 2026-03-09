<?php

namespace App\Models;

use App\Models\Catalog\Order;
use Illuminate\Database\Eloquent\Model;

class OrderPayment extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'order_id',
        'amount',
        'payment_method',
        'paid_at',
        'reference_no',
        'notes',
        'created_by',
        'created_at',
    ];

    protected $casts = [
        'amount' => 'decimal:4',
        'paid_at' => 'date',
        'created_at' => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id', 'order_id');
    }
}
