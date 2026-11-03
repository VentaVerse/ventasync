<?php

namespace App\Channels\Listings\Templates;

use Illuminate\Database\Eloquent\Model;

final class TemplateFill
{
    public static function onto(TemplateFields $fields, ?Model $listing, string $templateModel, int $storeId, mixed $chosen): array
    {
        $out = ['templates' => [], 'chosen' => null, 'name' => null, 'filled' => [], 'attributes' => null];
        if ($listing === null || ! $listing->exists || $storeId <= 0) {
            return $out;
        }

        $templates = $templateModel::query()->where('store_id', $storeId)->orderBy('name')->get();
        $out['templates'] = $templates->map(fn ($t) => ['id' => (int) $t->id, 'name' => (string) $t->name])->all();

        $id = is_scalar($chosen) && ctype_digit((string) $chosen) ? (int) $chosen : 0;
        $template = $id > 0 ? $templates->firstWhere('id', $id) : null;
        if ($template === null) {
            return $out;
        }
        $out['chosen'] = (int) $template->id;
        $out['name'] = (string) $template->name;

        $byKey = [];
        foreach ($fields->fields() as $field) {
            $byKey[$field->key] = $field;
        }

        $attributes = null;
        $attributeCount = 0;
        foreach ($fields->templateValues($template) as $key => $value) {
            if (TemplateField::blank($value)) {
                continue;
            }
            if (str_starts_with($key, 'attr:')) {
                $attributes ??= $fields->attributes($listing);
                $current = $attributes[substr($key, 5)] ?? null;
                if ($current !== null && TemplateField::attribute($key, '')->same($current, $value)) {
                    continue;
                }
                $attributes[substr($key, 5)] = $value;
                $attributeCount++;

                continue;
            }
            if (! isset($byKey[$key])) {
                continue;
            }
            $current = $byKey[$key]->read($listing);
            if ($current !== null && $byKey[$key]->same($current, $value)) {
                continue;
            }
            $byKey[$key]->write($listing, $value);
            $out['filled'][] = $byKey[$key]->label;
        }

        if ($attributeCount > 0) {
            $out['attributes'] = $attributes;
            $out['filled'][] = $attributeCount . ' category ' . ($attributeCount === 1 ? 'attribute' : 'attributes');
            if (array_key_exists('attribute_values', $listing->getCasts())) {
                $listing->attribute_values = $attributes;
            }
        }

        return $out;
    }

    public static function sentence(array $filled): string
    {
        return match (count($filled)) {
            0 => '',
            1 => $filled[0],
            default => implode(', ', array_slice($filled, 0, -1)) . ' and ' . end($filled),
        };
    }
}
