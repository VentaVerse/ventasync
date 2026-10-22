<?php

namespace Extensions\TikTok\Controllers;

use Extensions\TikTok\Models\TikTokListingTemplate;
use Extensions\TikTok\Models\TikTokListingTemplateAttribute;
use Extensions\TikTok\Models\TikTokStore;
use Extensions\TikTok\Services\TikTok\TikTokAttributes;
use Extensions\TikTok\Services\TikTok\TikTokClient;
use Extensions\TikTok\Services\TikTok\TikTokStoreSetup;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class TikTokListingTemplateController extends \App\Http\Controllers\Channels\ListingTemplateController
{
    protected function listingLists(): \App\Channels\Contracts\ListingLists
    {
        return app(\Extensions\TikTok\Services\TikTok\TikTokListingLists::class);
    }

    protected function channel(): string
    {
        return 'tiktok';
    }

    protected function model(): string
    {
        return TikTokListingTemplate::class;
    }

    protected function fieldRules(): array
    {
        return [
            'tiktok_category_id' => 'nullable|string|exists:ch_tiktok_categories,id',
            'brand_id' => 'nullable|string|max:64',
            'brand_name' => 'nullable|string|max:255',
        ];
    }

    protected function fieldValues(Request $request, array $data): array
    {
        $brandId = trim((string) ($data['brand_id'] ?? ''));

        return [
            'tiktok_category_id' => ($data['tiktok_category_id'] ?? '') !== '' ? (string) $data['tiktok_category_id'] : null,
            'brand_id' => $brandId !== '' ? $brandId : null,
            'brand_name' => $brandId !== '' ? (trim((string) ($data['brand_name'] ?? '')) ?: null) : null,
        ];
    }

    protected function applier(): \App\Channels\Listings\Templates\TemplateFields
    {
        return new \Extensions\TikTok\Services\TikTok\TikTokTemplateFields($this->storeId());
    }

    protected function fieldsData(Model $template): array
    {
        $service = app(TikTokAttributes::class);
        $categoryTemplate = null;
        $store = TikTokStore::defaultStore();
        if (! empty($template->tiktok_category_id) && $store) {
            $categoryTemplate = $service->ensureTemplate((string) $template->tiktok_category_id, app(TikTokClient::class), TikTokStoreSetup::creds($store) + ['setting' => $store]);
        }

        return [
            'categoryTemplate' => $categoryTemplate,
            'attributeRows' => $service->rows($categoryTemplate),
            'savedAttributes' => $template->exists ? $template->templateAttributes()->pluck('value', 'attribute_key')->all() : [],
        ];
    }

    protected function saveAttributes(Model $template, array $attributes): void
    {
        $template->templateAttributes()->delete();
        foreach ($attributes as $key => $value) {
            $key = trim((string) $key);
            $value = trim((string) $value);
            if ($key === '' || $value === '') {
                continue;
            }
            TikTokListingTemplateAttribute::create(['listing_template_id' => $template->id, 'attribute_key' => $key, 'value' => $value]);
        }
    }
}
