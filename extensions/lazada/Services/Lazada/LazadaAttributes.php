<?php

namespace Extensions\lazada\Services\Lazada;

use Extensions\lazada\Models\LazadaBrand;
use Extensions\lazada\Models\LazadaCategoryTemplate;
use Extensions\lazada\Models\LazadaSetting;

class LazadaAttributes
{
    public const MAP_PREFIX = '__map:';

    public const ERP_SOURCE_FIELDS = [
        'name'             => 'Product Name',
        'description'      => 'Product Description',
        'meta_title'       => 'Meta Title',
        'meta_description' => 'Meta Description',
        'model'            => 'Model',
        'sku'              => 'SKU',
        'upc'              => 'UPC',
        'ean'              => 'EAN',
        'jan'              => 'JAN',
        'isbn'             => 'ISBN',
        'mpn'              => 'MPN',
        'weight'           => 'Weight',
        'length'           => 'Length',
        'width'            => 'Width',
        'height'           => 'Height',
        'price'            => 'Price',
        'quantity'         => 'Quantity',
    ];

    public const AUTO_FILLED_ATTRIBUTES = [
        'name'              => 'name',
        'description'       => 'description',
        'short_description' => 'description',
        'model'             => 'model',
    ];

    public static function isAutoFilledAttributeKey(string $key): bool
    {
        return array_key_exists(strtolower(trim($key)), self::AUTO_FILLED_ATTRIBUTES);
    }

    public function autoFillBasics(array $attrs, ?object $productRow): array
    {
        foreach (self::AUTO_FILLED_ATTRIBUTES as $key => $erpField) {
            $current = $attrs[$key] ?? null;
            if ($current !== null && trim((string) $current) !== '') {
                continue;
            }
            $resolved = $this->resolveMappedValue($erpField, $productRow);
            if ($resolved === null || trim($resolved) === '') {
                $resolved = $key === 'short_description'
                    ? $this->resolveMappedValue('name', $productRow)
                    : null;
            }
            if ($resolved !== null && trim($resolved) !== '') {
                $attrs[$key] = $resolved;
            }
        }

        return $attrs;
    }

    public function isMapValue(?string $value): bool
    {
        return $value !== null && str_starts_with($value, self::MAP_PREFIX);
    }

    public function extractMapField(string $value): string
    {
        return substr($value, strlen(self::MAP_PREFIX));
    }

    public function resolveMappedValue(string $erpField, ?object $productRow): ?string
    {
        if (!$productRow || $erpField === '') return null;

        $val = match ($erpField) {
            'name'             => $productRow->name ?? null,
            'description'      => $productRow->description ?? null,
            'meta_title'       => $productRow->meta_title ?? null,
            'meta_description' => $productRow->meta_description ?? null,
            'model'            => $productRow->model ?? null,
            'sku'              => $productRow->sku ?? null,
            'upc'              => $productRow->upc ?? null,
            'ean'              => $productRow->ean ?? null,
            'jan'              => $productRow->jan ?? null,
            'isbn'             => $productRow->isbn ?? null,
            'mpn'              => $productRow->mpn ?? null,
            'weight'           => isset($productRow->weight) ? (string)$productRow->weight : null,
            'length'           => isset($productRow->length) ? (string)$productRow->length : null,
            'width'            => isset($productRow->width) ? (string)$productRow->width : null,
            'height'           => isset($productRow->height) ? (string)$productRow->height : null,
            'price'            => isset($productRow->price) ? (string)$productRow->price : null,
            'quantity'         => isset($productRow->quantity) ? (string)$productRow->quantity : null,
            default            => null,
        };

        if ($val !== null && in_array($erpField, ['description', 'name', 'meta_title', 'meta_description'], true)) {
            if (str_contains($val, '&lt;') || str_contains($val, '&gt;') || str_contains($val, '&amp;')) {
                $val = html_entity_decode($val, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }

        return $val;
    }

    public function resolveMapAttributes(array $attrs, ?object $productRow): array
    {
        foreach ($attrs as $key => $value) {
            if ($this->isMapValue($value)) {
                $erpField = $this->extractMapField($value);
                $resolved = $this->resolveMappedValue($erpField, $productRow);
                $attrs[$key] = $resolved ?? '';
            }
        }
        return $attrs;
    }

    public function isLockedProductAttributeKey(string $key, array $savedAttrs = []): bool
    {
        $k = trim($key);
        if ($k === '') return false;

        $value = $savedAttrs[$k] ?? null;
        return $this->isMapValue($value);
    }

    public function isSkuLevelRequiredKey(string $key): bool
    {
        $k = strtolower(trim($key));
        return in_array($k, [
            'sellersku', 'seller_sku',
            'price', 'quantity',
            'package_height', 'package_length', 'package_width', 'package_weight',
            'package_content',
            'special_price', 'special_from_date', 'special_to_date',
            'coming_soon', 'delay_delivery_days',
        ], true);
    }

    public function extractAttributes($body, bool $questionsOnly = true, bool $withRaw = false): array
    {
        if (!is_array($body)) {
            return [];
        }

        $data = $body['data'] ?? $body;
        if (is_array($data) && isset($data['attributes']) && is_array($data['attributes'])) {
            return $this->normalizeAttributes($data['attributes'], $questionsOnly, $withRaw);
        }
        if (is_array($data)) {
            if (array_is_list($data)) {
                return $this->normalizeAttributes($data, $questionsOnly, $withRaw);
            }
        }
        return [];
    }

    public function fieldLabeller($templateBody): callable
    {
        $byId = [];
        foreach ($this->extractAttributes($templateBody, false, true) as $attr) {
            $raw = $attr['raw'] ?? [];
            $id = $raw['id'] ?? $raw['attribute_id'] ?? null;
            $name = (string) ($raw['label'] ?? $attr['name'] ?? $attr['key'] ?? '');
            if (is_scalar($id) && $name !== '') {
                $byId[(string) $id] = $name;
            }
        }

        return function (string $field) use ($byId): ?string {
            $id = preg_replace('/^[ps]-/i', '', trim($field));

            return $byId[$id] ?? $byId[trim($field)] ?? null;
        };
    }

    public function fieldKeyMapper($templateBody): callable
    {
        $byId = [];
        $byName = [];
        foreach ($this->extractAttributes($templateBody, false, true) as $attr) {
            $key = (string) ($attr['key'] ?? $attr['name'] ?? '');
            if ($key === '') {
                continue;
            }
            $raw = $attr['raw'] ?? [];
            $id = $raw['id'] ?? $raw['attribute_id'] ?? null;
            if (is_scalar($id)) {
                $byId[(string) $id] = $key;
            }
            $byName[strtolower($key)] = $key;
            if (!empty($raw['label'])) {
                $byName[strtolower((string) $raw['label'])] = $key;
            }
        }

        return function (string $field) use ($byId, $byName): ?string {
            $field = trim($field);
            $id = preg_replace('/^[ps]-/i', '', $field);

            return $byId[$id] ?? $byName[strtolower($field)] ?? null;
        };
    }

    public static function keysNamedIn(string $text, array $keys): array
    {
        $found = [];
        foreach ($keys as $key) {
            $key = (string) $key;
            if ($key !== '' && preg_match('/(?<![A-Za-z0-9_])' . preg_quote($key, '/') . '(?![A-Za-z0-9_])/i', $text)) {
                $found[] = $key;
            }
        }

        return $found;
    }

    public function normalizeAttributes(array $raw, bool $questionsOnly = true, bool $withRaw = false): array
    {
        $out = [];
        foreach ($raw as $a) {
            if (!is_array($a)) {
                continue;
            }
            if ($questionsOnly && !empty($a['is_sale_prop'])) {
                continue;
            }
            $name = (string)($a['name'] ?? $a['attribute_name'] ?? '');
            if ($questionsOnly && $this->isSkuLevelRequiredKey($name)) {
                continue;
            }
            $id = $a['id'] ?? $a['attribute_id'] ?? null;
            $required = (bool)($a['is_mandatory'] ?? $a['isMandatory'] ?? $a['mandatory'] ?? $a['is_required'] ?? $a['required'] ?? false);
            $inputType = (string)($a['input_type'] ?? $a['type'] ?? $a['inputType'] ?? 'text');
            $options = $a['options'] ?? $a['option_values'] ?? $a['values'] ?? null;

            $key = $name !== '' ? $name : (is_scalar($id) ? (string)$id : '');
            if ($key === '' || ($questionsOnly && str_starts_with($key, '__'))) {
                continue;
            }

            $row = [
                'key' => $key,
                'name' => $name !== '' ? $name : $key,
                'required' => $required,
                'input_type' => $inputType,
                'options' => is_array($options) ? $options : [],
            ];
            if ($withRaw) {
                $row['raw'] = $a;
            }
            $out[] = $row;
        }
        return $out;
    }

    public function getLazadaSalePropKeys(int $primaryCategoryId, object $setting, LazadaClient $client): array
    {
        $creds = LazadaSetting::activeCredentials($setting);

        if ($primaryCategoryId <= 0 || empty($creds['app_key']) || empty($creds['app_secret']) || empty($setting->region)) {
            return [];
        }

        try {
            $apiPath = '/category/attributes/get';
            $timestamp = (string) round(microtime(true) * 1000);
            $params = [
                'app_key' => (string) $creds['app_key'],
                'sign_method' => 'sha256',
                'timestamp' => $timestamp,
                'primary_category_id' => (string) $primaryCategoryId,
            ];
            $params['sign'] = $client->sign($apiPath, $params, (string) $creds['app_secret']);
            $result = $client->get((string) $setting->region, $apiPath, $params);

            $body = $result['body'] ?? null;
            if (!is_array($body)) {
                return [];
            }
            $data = $body['data'] ?? null;
            if (!is_array($data)) {
                return [];
            }
            $attrs = $data['attributes'] ?? $data;
            if (!is_array($attrs)) {
                return [];
            }

            $keys = [];
            foreach ($attrs as $a) {
                if (!is_array($a)) {
                    continue;
                }
                $isSale = (bool)($a['is_sale_prop'] ?? $a['isSaleProp'] ?? $a['sale_prop'] ?? false);
                if (!$isSale) {
                    continue;
                }
                $k = (string)($a['name'] ?? $a['attribute_name'] ?? $a['key'] ?? '');
                $k = trim($k);
                if ($k === '') {
                    continue;
                }
                $keys[] = $k;
                if (count($keys) >= 2) {
                    break;
                }
            }
            return $keys;
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function cachedSalePropKeys(int $primaryCategoryId): array
    {
        if ($primaryCategoryId <= 0) {
            return [];
        }
        $template = LazadaCategoryTemplate::query()
            ->where('region', $this->region())
            ->where('primary_category_id', $primaryCategoryId)
            ->first();
        if (!$template || empty($template->template_body)) {
            return [];
        }
        $body = is_string($template->template_body)
            ? json_decode($template->template_body, true)
            : (array) $template->template_body;
        if (!is_array($body)) {
            return [];
        }
        $attrs = $body['data']['attributes'] ?? $body['data'] ?? $body['attributes'] ?? $body;
        if (!is_array($attrs)) {
            return [];
        }
        $keys = [];
        foreach ($attrs as $a) {
            if (!is_array($a)) continue;
            $isSale = (bool)($a['is_sale_prop'] ?? $a['isSaleProp'] ?? $a['sale_prop'] ?? false);
            if (!$isSale) continue;
            $k = trim((string)($a['name'] ?? $a['attribute_name'] ?? $a['key'] ?? ''));
            if ($k !== '') {
                $keys[] = $k;
            }
        }
        return $keys;
    }

    public function cachedSalePropLabels(int $primaryCategoryId): array
    {
        if ($primaryCategoryId <= 0) {
            return [];
        }
        $template = LazadaCategoryTemplate::query()
            ->where('region', $this->region())
            ->where('primary_category_id', $primaryCategoryId)
            ->first();
        if (!$template || empty($template->template_body)) {
            return [];
        }
        $body = is_string($template->template_body)
            ? json_decode($template->template_body, true)
            : (array) $template->template_body;
        if (!is_array($body)) {
            return [];
        }
        $attrs = $body['data']['attributes'] ?? $body['data'] ?? $body['attributes'] ?? $body;
        if (!is_array($attrs)) {
            return [];
        }
        $labels = [];
        foreach ($attrs as $a) {
            if (!is_array($a)) continue;
            if (empty($a['is_sale_prop'] ?? $a['isSaleProp'] ?? $a['sale_prop'] ?? false)) continue;
            $k = trim((string)($a['name'] ?? $a['attribute_name'] ?? $a['key'] ?? ''));
            $label = trim((string)($a['label'] ?? $a['display_name'] ?? ''));
            if ($k !== '' && $label !== '') {
                $labels[$k] = $label;
            }
        }
        return $labels;
    }

    public function guessLazadaVariantKeysFromOptionName(string $optionName): array
    {
        $n = strtolower(trim($optionName));
        if ($n === '') {
            return [];
        }
        if (str_contains($n, 'color')) {
            return ['color_family'];
        }
        if (str_contains($n, 'size')) {
            return ['size'];
        }
        if (str_contains($n, 'length') || str_contains($n, 'cable')) {
            return ['length'];
        }
        return [];
    }

    public function suggestForAttributes(array $attributes, ?object $productRow, array $savedAttrs = []): array
    {
        if (!$productRow) {
            return [];
        }

        $out = [];

        foreach ($attributes as $a) {
            $key = (string)($a['key'] ?? '');
            if ($key === '') continue;

            $savedVal = $savedAttrs[$key] ?? null;
            if ($this->isMapValue($savedVal)) {
                $erpField = $this->extractMapField($savedVal);
                $resolved = $this->resolveMappedValue($erpField, $productRow);
                if ($resolved !== null) {
                    $out[$key] = $resolved;
                }
            }
        }

        return $out;
    }

    public function normalizeName(string $s): string
    {
        $s = mb_strtolower(trim($s), 'UTF-8');
        $s = preg_replace('/[^a-z0-9]+/i', ' ', $s) ?? $s;
        $s = trim(preg_replace('/\s+/', ' ', $s) ?? $s);
        return $s;
    }

    public function suggestBrandFromManufacturer(?string $manufacturerName): array
    {
        $manufacturerName = trim((string)$manufacturerName);
        if ($manufacturerName === '') {
            return ['brand_id' => null, 'brand_name' => null];
        }

        $region = $this->region();
        $needle = $this->normalizeName($manufacturerName);

        $all = LazadaBrand::query()
            ->where('region', $region)
            ->select(['brand_id', 'name'])
            ->get();

        $best = null;
        foreach ($all as $b) {
            $bn = $this->normalizeName((string)$b->name);
            if ($bn === $needle) {
                $best = $b;
                break;
            }
        }

        if (!$best) {
            foreach ($all as $b) {
                $bn = $this->normalizeName((string)$b->name);
                if ($bn === '') continue;
                if (str_contains($needle, $bn) || str_contains($bn, $needle)) {
                    $best = $b;
                    break;
                }
            }
        }

        if ($best) {
            return ['brand_id' => (int)$best->brand_id, 'brand_name' => (string)$best->name];
        }

        foreach ($all as $b) {
            if ($this->normalizeName((string)$b->name) === 'no brand') {
                return ['brand_id' => (int)$b->brand_id, 'brand_name' => (string)$b->name];
            }
        }

        return ['brand_id' => null, 'brand_name' => null];
    }

    private function region(): string
    {
        $setting = LazadaSetting::defaultStore();
        return (string)($setting->region ?? '');
    }
}
