<?php

namespace Extensions\Shopee\Services\Shopee;

use App\Channels\Listings\Templates\ChannelTemplateFields;
use App\Channels\Listings\Templates\TemplateField;
use Extensions\Shopee\Models\ShopeeBrand;
use Extensions\Shopee\Models\ShopeeCategory;
use Extensions\Shopee\Models\ShopeeCategoryTemplate;
use Extensions\Shopee\Models\ShopeeListing;
use Extensions\Shopee\Models\ShopeeLogistic;
use Illuminate\Database\Eloquent\Model;

final class ShopeeTemplateFields extends ChannelTemplateFields
{
    protected function listingModel(): string
    {
        return ShopeeListing::class;
    }

    protected function columns(): array
    {
        return ['category' => 'shopee_category_id', 'brand' => 'shopee_brand_id', 'couriers' => 'logistic_ids'];
    }

    public function fields(): array
    {
        return [
            $this->category('shopee_category_id', fn ($v) => ShopeeCategory::query()->where('category_id', (int) $v)->value('name')),
            TemplateField::column('brand', 'Brand', 'shopee_brand_id', fn ($v) => (string) (ShopeeBrand::query()->where('brand_id', (int) $v)->value('name') ?? 'Brand ' . $v)),
            TemplateField::column('couriers', 'Delivery options', 'logistic_ids', fn ($v) => ShopeeLogistic::query()->where('store_id', $this->storeId)
                ->whereIn('logistics_channel_id', array_map('intval', (array) $v))->pluck('logistics_channel_name')->implode(', ') ?: implode(', ', (array) $v)),
            TemplateField::priceRule(),
            TemplateField::watermark(),
        ];
    }

    public function attributeLabels(Model $template): array
    {
        $category = $template->shopee_category_id ? ShopeeCategoryTemplate::query()->where('category_id', (int) $template->shopee_category_id)->first() : null;

        return collect($category ? ShopeeCategoryAttributes::extract($category->attributes) : [])->pluck('name', 'key')->all();
    }
}
