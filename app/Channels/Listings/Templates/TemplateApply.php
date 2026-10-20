<?php

namespace App\Channels\Listings\Templates;

use App\Services\ActivityLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final class TemplateApply
{
    public function preview(TemplateFields $f, Model $template, array $productIds): array
    {
        $names = $this->names($productIds);
        $rows = [];
        foreach ($this->plan($f, $template, $productIds) as $key => $p) {
            $rows[$key] = [
                'label' => $p['field']->label,
                'value' => $p['field']->show($p['value']),
                'fills' => $p['fills'],
                'matches' => $p['matches'],
                'own' => array_map(fn ($pid, $own) => ['id' => $pid, 'name' => $names[$pid] ?? ('Product #' . $pid), 'own' => $p['field']->show($own)], array_keys($p['own']), $p['own']),
            ];
        }

        return $rows;
    }

    public function apply(TemplateFields $f, Model $template, array $productIds, array $replace, string $channel): int
    {
        $plan = $this->plan($f, $template, $productIds);
        $changes = 0;
        DB::transaction(function () use ($f, $plan, $productIds, $replace, &$changes) {
            foreach ($productIds as $pid) {
                $listing = $f->listing($pid);
                $attributes = $f->attributes($listing);
                $touched = false;
                foreach ($plan as $key => $p) {
                    $chosen = in_array($pid, $p['fills'], true)
                        || (array_key_exists($pid, $p['own']) && in_array($pid, array_map('intval', (array) ($replace[$key] ?? [])), true));
                    if (! $chosen) {
                        continue;
                    }
                    if (str_starts_with($key, 'attr:')) {
                        $attributes[substr($key, 5)] = $p['value'];
                    } else {
                        $p['field']->write($listing, $p['value']);
                    }
                    $touched = true;
                    $changes++;
                }
                if ($touched) {
                    $f->save($listing, $attributes);
                }
            }
        });
        ActivityLogger::log('updated', 'Listing template applied', (int) $template->id, $template->name, ['channel' => $channel, 'products' => count($productIds), 'changes' => $changes]);

        return $changes;
    }

    private function plan(TemplateFields $f, Model $template, array $productIds): array
    {
        $fields = [];
        foreach ($f->fields() as $field) {
            $fields[$field->key] = $field;
        }
        $labels = $f->attributeLabels($template);
        $plan = [];
        foreach ($f->templateValues($template) as $key => $value) {
            if (TemplateField::blank($value)) {
                continue;
            }
            $field = $fields[$key] ?? (str_starts_with($key, 'attr:')
                ? TemplateField::attribute($key, $labels[substr($key, 5)] ?? substr($key, 5))
                : null);
            if ($field === null) {
                continue;
            }
            $plan[$key] = ['field' => $field, 'value' => $value, 'fills' => [], 'matches' => [], 'own' => []];
        }

        foreach ($productIds as $pid) {
            $listing = $f->listing($pid);
            $attributes = null;
            foreach ($plan as $key => $p) {
                if (str_starts_with($key, 'attr:')) {
                    $attributes ??= $f->attributes($listing);
                    $current = $attributes[substr($key, 5)] ?? null;
                    $current = TemplateField::blank(is_array($current) ? implode('', $current) : $current) ? null : $current;
                } else {
                    $current = $p['field']->read($listing);
                }
                if ($current === null) {
                    $plan[$key]['fills'][] = $pid;
                } elseif ($p['field']->same($current, $p['value'])) {
                    $plan[$key]['matches'][] = $pid;
                } else {
                    $plan[$key]['own'][$pid] = $current;
                }
            }
        }

        return $plan;
    }

    private function names(array $productIds): array
    {
        return DB::table('product_descriptions')->whereIn('product_id', $productIds)
            ->where('language_id', (int) config('catalog.default_language_id'))
            ->pluck('name', 'product_id')->map(fn ($n) => (string) $n)->all();
    }
}
