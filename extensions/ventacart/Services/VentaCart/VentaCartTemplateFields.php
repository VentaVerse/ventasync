<?php

namespace Extensions\VentaCart\Services\VentaCart;

use App\Channels\Listings\Templates\ChannelTemplateFields;
use App\Channels\Listings\Templates\TemplateField;
use Extensions\VentaCart\Models\VentaCartCategory;
use Extensions\VentaCart\Models\VentaCartListing;

final class VentaCartTemplateFields extends ChannelTemplateFields
{
    protected function listingModel(): string
    {
        return VentaCartListing::class;
    }

    protected function columns(): array
    {
        return ['category' => 'ventacart_category_id'];
    }

    public function fields(): array
    {
        return [
            $this->category('ventacart_category_id', fn ($v) => VentaCartCategory::query()->where('store_id', $this->storeId)->where('ventacart_category_id', (int) $v)->value('name')),
            TemplateField::priceRule(),
            TemplateField::watermark(),
        ];
    }
}
