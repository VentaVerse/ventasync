<?php

namespace Extensions\VentaCart\Controllers;

class VentaCartCategoryController extends \App\Http\Controllers\Channels\CategoryController
{
    protected function channel(): string
    {
        return 'ventacart';
    }

    protected function categoryModel(): string
    {
        return \Extensions\VentaCart\Models\VentaCartCategory::class;
    }

    protected function idColumn(): string
    {
        return 'ventacart_category_id';
    }

    protected function listingLists(): \App\Channels\Contracts\ListingLists
    {
        return app(\Extensions\VentaCart\Services\VentaCart\VentaCartListingLists::class);
    }
}
