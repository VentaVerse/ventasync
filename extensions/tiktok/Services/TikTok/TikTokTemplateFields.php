<?php

namespace Extensions\TikTok\Services\TikTok;

use App\Channels\Listings\Templates\ChannelTemplateFields;
use App\Channels\Listings\Templates\TemplateField;
use Extensions\TikTok\Models\TikTokCategory;
use Extensions\TikTok\Models\TikTokCategoryTemplate;
use Extensions\TikTok\Models\TikTokListing;
use Illuminate\Database\Eloquent\Model;

final class TikTokTemplateFields extends ChannelTemplateFields
{
    protected function listingModel(): string
    {
        return TikTokListing::class;
    }

    protected function columns(): array
    {
        return ['category' => 'tiktok_category_id'];
    }

    public function fields(): array
    {
        return [
            $this->category('tiktok_category_id', fn ($v) => TikTokCategory::query()->whereKey((string) $v)->value('name')),
            TemplateField::priceRule(),
            TemplateField::watermark(),
        ];
    }

    public function attributeLabels(Model $template): array
    {
        $category = $template->tiktok_category_id ? TikTokCategoryTemplate::query()->where('category_id', (string) $template->tiktok_category_id)->first() : null;

        return collect(app(TikTokAttributes::class)->rows($category))->mapWithKeys(fn ($r) => [(string) ($r['key'] ?? '') => (string) ($r['name'] ?? '')])->filter()->all();
    }
}
