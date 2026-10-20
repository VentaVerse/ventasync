<?php

namespace Extensions\VentaCart\Controllers;

use Extensions\VentaCart\Models\VentaCartListingTemplate;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class VentaCartListingTemplateController extends \App\Http\Controllers\Channels\ListingTemplateController
{
    protected function channel(): string
    {
        return 'ventacart';
    }

    protected function model(): string
    {
        return VentaCartListingTemplate::class;
    }

    protected function fieldRules(): array
    {
        return ['ventacart_category_id' => ['nullable', 'integer', Rule::exists('ch_ventacart_categories', 'ventacart_category_id')->where('store_id', $this->storeId())]];
    }

    protected function fieldValues(Request $request, array $data): array
    {
        return ['ventacart_category_id' => $data['ventacart_category_id'] ?? null];
    }

    protected function fieldsView(): string
    {
        return 'ext-ventacart::templates._fields';
    }
}
