<?php

namespace Extensions\tiktok\Services\TikTok;

use Illuminate\Support\Str;

final class TikTokVariationRename
{
    public function shape(array $skus, array $liveSkus, \Closure $builtIn, ?string $skip = null): array
    {
        $liveById = [];
        $liveBySeller = [];
        foreach ($liveSkus as $live) {
            $id = trim((string) ($live['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $liveById[$id] = $live;
            $seller = trim((string) ($live['seller_sku'] ?? ''));
            if ($seller !== '' && !isset($liveBySeller[$seller])) {
                $liveBySeller[$seller] = $id;
            }
        }

        foreach ($skus as $i => $sku) {
            $id = trim((string) ($sku['id'] ?? ''));
            if ($id !== '' && isset($liveById[$id])) {
                continue;
            }
            unset($skus[$i]['id']);
            $seller = trim((string) ($sku['seller_sku'] ?? ''));
            if ($seller !== '' && isset($liveBySeller[$seller])) {
                $skus[$i]['id'] = $liveBySeller[$seller];
            }
        }

        $types = [];
        $typeIndex = [];
        $held = [];
        $valueIds = [];
        foreach ($liveById as $skuId => $live) {
            foreach ((array) ($live['sales_attributes'] ?? []) as $attr) {
                $typeId = trim((string) ($attr['id'] ?? ''));
                if ($typeId === '') {
                    continue;
                }
                if (!isset($typeIndex[$typeId])) {
                    $typeIndex[$typeId] = count($types);
                    $types[] = ['id' => $typeId, 'name' => trim((string) ($attr['name'] ?? ''))];
                }
                $valueId = trim((string) ($attr['value_id'] ?? ''));
                $valueName = trim((string) ($attr['value_name'] ?? ''));
                $held[(string) $skuId][$typeIndex[$typeId]] = ['id' => $valueId, 'name' => $valueName];
                if ($valueId !== '' && $valueName !== '') {
                    $valueIds[$typeIndex[$typeId]][$valueName] ??= $valueId;
                }
            }
        }

        $axes = array_map(fn ($a) => trim((string) ($a['name'] ?? '')), array_values((array) ($skus[0]['sales_attributes'] ?? [])));
        if ($types === [] || $axes === []) {
            return ['skus' => array_values($skus), 'renamed' => false, 'skipped' => null];
        }

        $typeNames = implode(', ', array_column($types, 'name'));
        $axisNames = implode(', ', $axes);
        $pairs = [];
        if (count($types) !== count($axes)) {
            $skip ??= 'the item on TikTok Shop has ' . count($types) . ' variation ' . Str::plural('type', count($types))
                . ' (' . $typeNames . ') and the product has ' . count($axes) . ' (' . $axisNames . ')';
        } else {
            $pairs = $this->pair($skus, $axes, $types, $held);
            if (count($pairs) < count($axes)) {
                $skip ??= 'the item\'s variation types (' . $typeNames . ') could not be matched one to one with the product\'s options (' . $axisNames . ')';
            }
        }

        $renames = [];
        if ($skip === null) {
            $builtInIds = null;
            $asked = false;
            foreach ($pairs as $axis => $type) {
                if ($axes[$axis] === $types[$type]['name']) {
                    $renames[$axis] = false;
                    continue;
                }
                if (!$asked) {
                    $builtInIds = $builtIn();
                    $asked = true;
                }
                if ($builtInIds === null) {
                    $skip = 'TikTok Shop did not say which variation types its category defines';
                    break;
                }
                $renames[$axis] = !in_array($types[$type]['id'], $builtInIds, true);
            }
        }

        if ($skip !== null) {
            return $this->kept($skus, $types, $held, $pairs, $valueIds, $skip);
        }

        $renamed = false;
        foreach ($skus as $i => $sku) {
            $sent = [];
            foreach (array_values((array) ($sku['sales_attributes'] ?? [])) as $axis => $attr) {
                $value = (string) ($attr['value_name'] ?? '');
                if ($renames[$axis]) {
                    $sent[] = ['name' => $axes[$axis], 'value_name' => $value];
                    $renamed = true;
                    continue;
                }
                $type = $pairs[$axis];
                $one = ['id' => $types[$type]['id']];
                $valueId = $valueIds[$type][trim($value)] ?? null;
                if ($valueId !== null) {
                    $one['value_id'] = $valueId;
                } else {
                    $renamed = true;
                }
                $one['value_name'] = $value;
                $sent[] = $one;
            }
            $skus[$i]['sales_attributes'] = $sent;
        }

        return ['skus' => array_values($skus), 'renamed' => $renamed, 'skipped' => null];
    }

    private function kept(array $skus, array $types, array $held, array $pairs, array $valueIds, string $skip): array
    {
        $placeable = count($pairs) === count($types) && count($pairs) === count((array) ($skus[0]['sales_attributes'] ?? []));

        $valueMap = [];
        foreach ($skus as $sku) {
            $id = (string) ($sku['id'] ?? '');
            if ($id === '' || !isset($held[$id])) {
                continue;
            }
            foreach (array_values((array) ($sku['sales_attributes'] ?? [])) as $axis => $attr) {
                if (isset($pairs[$axis], $held[$id][$pairs[$axis]])) {
                    $valueMap[$axis][(string) ($attr['value_name'] ?? '')] ??= $held[$id][$pairs[$axis]];
                }
            }
        }

        $out = [];
        $left = 0;
        foreach ($skus as $sku) {
            $id = (string) ($sku['id'] ?? '');
            if ($id !== '' && isset($held[$id])) {
                $sent = [];
                foreach ($types as $type => $t) {
                    if (isset($held[$id][$type])) {
                        $sent[] = array_filter(['id' => $t['id'], 'value_id' => $held[$id][$type]['id'], 'value_name' => $held[$id][$type]['name']], fn ($v) => $v !== '');
                    }
                }
                $sku['sales_attributes'] = $sent;
                $out[] = $sku;
                continue;
            }
            if (!$placeable) {
                $left++;
                continue;
            }
            $byType = [];
            foreach (array_values((array) ($sku['sales_attributes'] ?? [])) as $axis => $attr) {
                $type = $pairs[$axis];
                $value = (string) ($attr['value_name'] ?? '');
                $known = $valueMap[$axis][$value] ?? null;
                $valueId = $known['id'] ?? ($valueIds[$type][trim($value)] ?? '');
                $byType[$type] = array_filter(['id' => $types[$type]['id'], 'value_id' => $valueId, 'value_name' => $known['name'] ?? $value], fn ($v) => $v !== '');
            }
            ksort($byType);
            $sku['sales_attributes'] = array_values($byType);
            $out[] = $sku;
        }

        if ($left > 0) {
            $skip .= ', and ' . $left . ' new ' . Str::plural('variation', $left) . ' ' . ($left === 1 ? 'was' : 'were') . ' not added';
        }

        return ['skus' => $out, 'renamed' => false, 'skipped' => $skip];
    }

    private function pair(array $skus, array $axes, array $types, array $held): array
    {
        $pairs = [];

        foreach ($axes as $axis => $name) {
            $same = array_keys(array_filter($types, fn ($t) => strcasecmp($t['name'], $name) === 0));
            if (count($same) === 1 && !in_array($same[0], $pairs, true)) {
                $pairs[$axis] = $same[0];
            }
        }

        $shared = [];
        foreach ($skus as $i => $sku) {
            $id = trim((string) ($sku['id'] ?? ''));
            if ($id !== '' && isset($held[$id])) {
                $shared[] = [$i, $id];
            }
        }
        $fits = function (int $axis, int $type) use ($skus, $shared, $held): bool {
            $forward = [];
            $back = [];
            $seen = 0;
            foreach ($shared as [$i, $skuId]) {
                $value = $held[$skuId][$type] ?? null;
                if ($value === null) {
                    continue;
                }
                $theirs = $value['id'] !== '' ? $value['id'] : 'name:' . $value['name'];
                $ours = (string) (array_values((array) ($skus[$i]['sales_attributes'] ?? []))[$axis]['value_name'] ?? '');
                if ((isset($forward[$ours]) && $forward[$ours] !== $theirs) || (isset($back[$theirs]) && $back[$theirs] !== $ours)) {
                    return false;
                }
                $forward[$ours] = $theirs;
                $back[$theirs] = $ours;
                $seen++;
            }

            return $seen > 0;
        };

        do {
            $progress = false;
            $openAxes = array_values(array_diff(array_keys($axes), array_keys($pairs)));
            $openTypes = array_values(array_diff(array_keys($types), $pairs));
            foreach ($openAxes as $axis) {
                $fitting = array_values(array_filter($openTypes, fn ($t) => $fits($axis, $t)));
                if (count($fitting) !== 1) {
                    continue;
                }
                $rivals = array_filter($openAxes, fn ($a) => $a !== $axis && $fits($a, $fitting[0]));
                if ($rivals === []) {
                    $pairs[$axis] = $fitting[0];
                    $progress = true;
                    break;
                }
            }
            if (!$progress && count($openAxes) === 1 && count($openTypes) === 1) {
                $pairs[$openAxes[0]] = $openTypes[0];
                $progress = true;
            }
        } while ($progress && count($pairs) < count($axes));

        return $pairs;
    }
}
