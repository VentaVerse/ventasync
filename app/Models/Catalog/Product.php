<?php

namespace App\Models\Catalog;

class Product extends BaseModel
{
    protected $table = 'products';

    protected $primaryKey = 'product_id';

    protected $fillable = [
        'sku','upc','ean','jan','isbn','mpn',
        'quantity','reorder_level','image','brand_id','price','cost','cost_amount','cost_percentage','cost_additional','weight','length','width','height',
        'subtract_stock','status',
        'date_added','date_modified',
    ];

    public function descriptions()
    {
        return $this->hasMany(ProductDescription::class, 'product_id', 'product_id');
    }

    public function description()
    {
        return $this->hasOne(ProductDescription::class, 'product_id', 'product_id')
            ->where('language_id', config('catalog.default_language_id'));
    }

    protected static function booted()
    {
        static::creating(function ($model) {
            if (empty($model->date_added)) {
                $model->date_added = now();
            }
            $model->date_modified = now();
        });

        static::updating(function ($model) {
            $model->date_modified = now();
        });
    }

}
