<?php

namespace App\Models\Catalog;

class Product extends BaseModel
{
    protected $primaryKey = 'product_id';

    protected $fillable = [
        'model','sku','upc','ean','jan','isbn','mpn','location',
        'quantity','reorder_level','stock_status_id','image','manufacturer_id','shipping','price','cost','cost_amount','cost_percentage','cost_additional','points','tax_class_id','date_available','weight','weight_class_id','length','width','height','length_class_id',
        'subtract','minimum','sort_order','status','viewed',
        'config_background_image_position','config_background_image',
        'date_added','date_modified',
        'add_cart','product_additionals','meta_robots','seo_canonical'
    ];

    public function getTable()
    {
        return $this->tableName('product');
    }

    protected static function booted()
    {
        static::creating(function ($model) {
            // OpenCart schema requires these (no DB default)
            if (empty($model->date_added)) {
                $model->date_added = now();
            }
            // Always set date_modified on create
            $model->date_modified = now();
        });

        static::updating(function ($model) {
            // Always bump date_modified on update
            $model->date_modified = now();
        });
    }

}
