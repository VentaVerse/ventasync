<?php

namespace Extensions\Lazada\Controllers;

use Extensions\Lazada\Models\LazadaBrand;
use Extensions\Lazada\Models\LazadaCategoryTemplate;
use Extensions\Lazada\Models\LazadaListingTemplate;
use Extensions\Lazada\Models\LazadaListingTemplateAttribute;
use Extensions\Lazada\Models\LazadaStore;
use Extensions\Lazada\Services\Lazada\LazadaAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class LazadaListingTemplateController extends \App\Http\Controllers\Channels\ListingTemplateController
{
    protected function listingLists(): \App\Channels\Contracts\ListingLists
    {
        return app(\Extensions\Lazada\Services\Lazada\LazadaListingLists::class);
    }

    protected function channel(): string
    {
        return 'lazada';
    }

    protected function model(): string
    {
        return LazadaListingTemplate::class;
    }

    protected function fieldRules(): array
    {
        return [
            'primary_category_id' => 'nullable|integer|min:1',
            'brand_id' => 'nullable|integer|min:1',
            'no_brand' => 'nullable',
        ];
    }

    protected function fieldValues(Request $request, array $data): array
    {
        return [
            'primary_category_id' => $data['primary_category_id'] ?? null,
            'brand_id' => $data['brand_id'] ?? null,
            'brand_name_override' => ! empty($request->input('no_brand')) ? 'No Brand' : null,
        ];
    }

    protected function applier(): \App\Channels\Listings\Templates\TemplateFields
    {
        return new \Extensions\Lazada\Services\Lazada\LazadaTemplateFields($this->storeId());
    }

    protected function fieldsData(Model $template): array
    {
        $categoryTemplate = null;
        $attributes = [];
        $saved = [];
        if ($template->primary_category_id) {
            $categoryTemplate = LazadaCategoryTemplate::query()
                ->where('region', (string) (LazadaStore::defaultStore()->region ?? ''))
                ->where('primary_category_id', (int) $template->primary_category_id)
                ->first();
            if ($categoryTemplate && $categoryTemplate->template_body) {
                $attributes = app(LazadaAttributes::class)->extractAttributes($categoryTemplate->template_body);
            }
            if ($template->exists) {
                $saved = $template->templateAttributes()->pluck('value', 'attribute_key')->all();
            }
        }

        return [
            'categoryTemplate' => $categoryTemplate,
            'attributes' => $attributes,
            'saved' => $saved,
            'selectedBrandName' => $template->brand_id ? (string) LazadaBrand::query()->where('brand_id', $template->brand_id)->value('name') : '',
            'catalogSourceFields' => LazadaAttributes::CATALOG_SOURCE_FIELDS,
        ];
    }

    protected function saveAttributes(Model $template, array $attributes): void
    {
        foreach ($attributes as $key => $value) {
            $key = trim((string) $key);
            if ($key === '' || strtolower($key) === 'brand') {
                continue;
            }
            if ($value === null || $value === '') {
                LazadaListingTemplateAttribute::query()->where('listing_template_id', $template->id)->where('attribute_key', $key)->delete();
            } else {
                LazadaListingTemplateAttribute::query()->updateOrCreate(
                    ['listing_template_id' => $template->id, 'attribute_key' => $key],
                    ['value' => (string) $value]
                );
            }
        }
    }
}
