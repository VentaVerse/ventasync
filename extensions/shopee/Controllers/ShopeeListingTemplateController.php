<?php

namespace Extensions\Shopee\Controllers;

use Extensions\Shopee\Models\ShopeeBrand;
use Extensions\Shopee\Models\ShopeeCategoryTemplate;
use Extensions\Shopee\Models\ShopeeListingTemplate;
use Extensions\Shopee\Models\ShopeeListingTemplateAttribute;
use Extensions\Shopee\Models\ShopeeLogistic;
use Extensions\Shopee\Services\Shopee\ShopeeCategoryAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class ShopeeListingTemplateController extends \App\Http\Controllers\Channels\ListingTemplateController
{
    protected function channel(): string
    {
        return 'shopee';
    }

    protected function model(): string
    {
        return ShopeeListingTemplate::class;
    }

    protected function fieldRules(): array
    {
        return [
            'shopee_category_id' => 'nullable|integer|min:1',
            'shopee_brand_id' => 'nullable|integer|min:0',
            'logistic_ids' => 'nullable|array',
            'logistic_ids.*' => 'integer',
        ];
    }

    protected function fieldValues(Request $request, array $data): array
    {
        $logistics = array_values(array_map('intval', array_filter($data['logistic_ids'] ?? [])));

        return [
            'shopee_category_id' => $data['shopee_category_id'] ?? null,
            'shopee_brand_id' => ((int) ($data['shopee_brand_id'] ?? 0)) ?: null,
            'logistic_ids' => $logistics ?: null,
        ];
    }

    protected function applier(): \App\Channels\Listings\Templates\TemplateFields
    {
        return new \Extensions\Shopee\Services\Shopee\ShopeeTemplateFields($this->storeId());
    }

    protected function fieldsView(): string
    {
        return 'ext-shopee::templates._fields';
    }

    protected function fieldsData(Model $template): array
    {
        $categoryTemplate = null;
        $attributes = [];
        $saved = [];
        if ($template->shopee_category_id) {
            $categoryTemplate = ShopeeCategoryTemplate::query()->where('category_id', (int) $template->shopee_category_id)->first();
            if ($categoryTemplate && $categoryTemplate->attributes) {
                $attributes = ShopeeCategoryAttributes::extract($categoryTemplate->attributes);
            }
            if ($template->exists) {
                $saved = $template->templateAttributes()->pluck('value', 'attribute_key')->all();
            }
        }

        return [
            'categoryTemplate' => $categoryTemplate,
            'attributes' => $attributes,
            'saved' => $saved,
            'brandName' => $template->shopee_brand_id
                ? (string) ShopeeBrand::query()->where('category_id', (int) $template->shopee_category_id)->where('brand_id', (int) $template->shopee_brand_id)->value('name')
                : '',
            'logistics' => ShopeeLogistic::query()->orderByDesc('enabled')->orderBy('logistics_channel_name')->get()
                ->map(fn ($l) => ['logistic_id' => (int) $l->logistics_channel_id, 'logistic_name' => (string) $l->logistics_channel_name])->all(),
        ];
    }

    protected function saveAttributes(Model $template, array $attributes): void
    {
        foreach ($attributes as $key => $value) {
            $key = trim((string) $key);
            if ($key === '') {
                continue;
            }
            if ($value === null || $value === '') {
                ShopeeListingTemplateAttribute::query()->where('listing_template_id', $template->id)->where('attribute_key', $key)->delete();
            } else {
                ShopeeListingTemplateAttribute::query()->updateOrCreate(
                    ['listing_template_id' => $template->id, 'attribute_key' => $key],
                    ['value' => (string) $value]
                );
            }
        }
    }
}
