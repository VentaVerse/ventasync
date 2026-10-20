<?php

namespace App\Channels\Listings\Templates;

use Illuminate\Database\Eloquent\Model;

abstract class ChannelTemplateFields implements TemplateFields
{
    private array $listings = [];

    public function __construct(protected readonly int $storeId)
    {
    }

    abstract protected function listingModel(): string;

    abstract protected function columns(): array;

    public function templateValues(Model $template): array
    {
        $values = [];
        foreach ($this->columns() as $key => $column) {
            $values[$key] = $template->{$column};
        }
        $values['price_rule'] = $template->markup_percent === null && $template->markup_fixed === null
            ? null
            : [$template->markup_percent === null ? null : (float) $template->markup_percent, $template->markup_fixed === null ? null : (float) $template->markup_fixed];
        $values['watermark'] = $template->watermark_template_id;
        if (method_exists($template, 'templateAttributes')) {
            foreach ($template->templateAttributes()->pluck('value', 'attribute_key') as $key => $value) {
                $values['attr:' . $key] = $value;
            }
        }

        return $values;
    }

    public function listing(int $productId): Model
    {
        if (! isset($this->listings[$productId])) {
            $model = $this->listingModel();
            $this->listings[$productId] = $model::query()->withoutGlobalScopes()
                ->where('store_id', $this->storeId)->where('product_id', $productId)->orderBy('id')->first()
                ?? (new $model())->forceFill(['store_id' => $this->storeId, 'product_id' => $productId]);
        }

        return $this->listings[$productId];
    }

    public function attributes(Model $listing): array
    {
        return (array) ($listing->attribute_values ?? []);
    }

    public function attributeLabels(Model $template): array
    {
        return [];
    }

    public function save(Model $listing, array $attributes): void
    {
        if (array_key_exists('attribute_values', $listing->getCasts())) {
            $listing->attribute_values = $attributes === [] ? null : $attributes;
        }
        $listing->save();
    }

    protected function category(string $column, \Closure $name): TemplateField
    {
        return TemplateField::column('category', 'Category', $column, fn ($v) => $name($v) ?: 'Category ' . $v);
    }
}
