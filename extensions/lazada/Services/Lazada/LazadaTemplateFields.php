<?php

namespace Extensions\Lazada\Services\Lazada;

use App\Channels\Listings\Templates\ChannelTemplateFields;
use App\Channels\Listings\Templates\TemplateField;
use Extensions\Lazada\Models\LazadaBrand;
use Extensions\Lazada\Models\LazadaCategory;
use Extensions\Lazada\Models\LazadaCategoryTemplate;
use Extensions\Lazada\Models\LazadaListing;
use Extensions\Lazada\Models\LazadaListingAttribute;
use Extensions\Lazada\Models\LazadaStore;
use Illuminate\Database\Eloquent\Model;

final class LazadaTemplateFields extends ChannelTemplateFields
{
    protected function listingModel(): string
    {
        return LazadaListing::class;
    }

    protected function columns(): array
    {
        return ['category' => 'primary_category_id'];
    }

    public function templateValues(Model $template): array
    {
        $values = parent::templateValues($template);
        $values['brand'] = (int) ($template->brand_id ?? 0) > 0 || trim((string) ($template->brand_name_override ?? '')) !== ''
            ? [$template->brand_id ? (int) $template->brand_id : null, $template->brand_name_override]
            : null;

        return $values;
    }

    public function fields(): array
    {
        return [
            $this->category('primary_category_id', fn ($v) => LazadaCategory::query()->where('category_id', (int) $v)->value('name')),
            new TemplateField(
                'brand',
                'Brand',
                fn (Model $m) => (int) ($m->brand_id ?? 0) > 0 || trim((string) ($m->brand_name_override ?? '')) !== ''
                    ? [$m->brand_id ? (int) $m->brand_id : null, $m->brand_name_override]
                    : null,
                function (Model $m, $value) {
                    [$m->brand_id, $m->brand_name_override] = $value;
                },
                fn ($v) => trim((string) ($v[1] ?? '')) !== '' ? (string) $v[1] : (string) (LazadaBrand::query()->where('brand_id', (int) ($v[0] ?? 0))->value('name') ?? 'Brand ' . ($v[0] ?? '')),
                true,
            ),
            TemplateField::priceRule(),
            TemplateField::watermark(),
        ];
    }

    public function attributes(Model $listing): array
    {
        return $listing->exists
            ? LazadaListingAttribute::query()->where('lazada_listing_id', $listing->id)->pluck('value', 'attribute_key')->all()
            : [];
    }

    public function attributeLabels(Model $template): array
    {
        $category = $template->primary_category_id
            ? LazadaCategoryTemplate::query()->where('region', (string) (LazadaStore::query()->whereKey($this->storeId)->value('region') ?? ''))
                ->where('primary_category_id', (int) $template->primary_category_id)->first()
            : null;
        if (! $category || ! $category->template_body) {
            return [];
        }

        return collect(app(LazadaAttributes::class)->extractAttributes($category->template_body))
            ->mapWithKeys(fn ($a) => [(string) ($a['key'] ?? '') => (string) ($a['label'] ?? $a['name'] ?? $a['key'] ?? '')])->filter()->all();
    }

    public function save(Model $listing, array $attributes): void
    {
        $listing->save();
        $own = $this->attributes($listing);
        foreach ($attributes as $key => $value) {
            if (($own[$key] ?? null) !== $value) {
                LazadaListingAttribute::query()->updateOrCreate(
                    ['lazada_listing_id' => $listing->id, 'attribute_key' => (string) $key],
                    ['value' => is_array($value) ? json_encode($value) : (string) $value]
                );
            }
        }
    }
}
