<?php

namespace Extensions\lazada\Services\Lazada;

use Extensions\lazada\Models\LazadaBrand;
use Extensions\lazada\Models\LazadaCategoryTemplate;
use Extensions\lazada\Models\LazadaSetting;

/**
 * The attribute vocabulary, out of the god controller (Phase C, #184).
 *
 * Everything that decides what a category template MEANS lives here: which
 * rows are questions and which are wire plumbing, which the catalogue
 * answers by itself (#175), which are SKU-level, how a saved __map: value
 * resolves against the product row, and the sale-prop keys a category
 * varies on. One copy - the group controller carried its own pre-#175
 * fork of the extractor that still showed sale props as questions, and
 * the readiness service had to resolve the whole controller to reach
 * three of these methods.
 */
class LazadaAttributes
{
    /**
     * Prefix used to store "mapped to ERP field" attribute values.
     * e.g. "__map:name" means "pull value from product_description.name".
     */
    public const MAP_PREFIX = '__map:';

    /**
     * ERP product fields available as mapping sources (dropdown options).
     */
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

    /**
     * Template attributes the catalogue can answer by itself, keyed by
     * Lazada's machine name => the ERP field that answers it (#175).
     *
     * Lazada's category template lists name and description in the same
     * mandatory list as Colour Family, because its API has no top-level
     * content fields: EVERYTHING travels inside Attributes. The old design
     * (CedCommerce's shape) read that as "the operator must answer every
     * row". Shopee and TikTok were rebuilt to fill the obvious fields from
     * the catalogue and only ask the category's own questions; this set is
     * Lazada catching up. A saved value still wins: auto-fill only steps in
     * where the operator said nothing.
     */
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

    /**
     * Fill the blanks the catalogue can fill, BEFORE any mandatory gate
     * runs. This used to exist as a fallback below the gate, where it could
     * never fire: the gate threw "name is mandatory" first (#175).
     */
    public function autoFillBasics(array $attrs, ?object $productRow): array
    {
        foreach (self::AUTO_FILLED_ATTRIBUTES as $key => $erpField) {
            $current = $attrs[$key] ?? null;
            if ($current !== null && trim((string) $current) !== '') {
                continue;   // the operator's own value always wins
            }
            $resolved = $this->resolveMappedValue($erpField, $productRow);
            if ($resolved === null || trim($resolved) === '') {
                // short_description falls back to the name rather than
                // staying blank: Lazada wants highlights, and the product's
                // name is a poor highlight but an honest one.
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

    /**
     * Check if a stored attribute value is a mapping reference.
     */
    public function isMapValue(?string $value): bool
    {
        return $value !== null && str_starts_with($value, self::MAP_PREFIX);
    }

    /**
     * Extract the ERP field name from a __map: prefixed value.
     */
    public function extractMapField(string $value): string
    {
        return substr($value, strlen(self::MAP_PREFIX));
    }

    /**
     * Resolve an ERP field identifier to the actual value from a product row.
     */
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

        // HTML-entity decode for description fields (ERP may store HTML-escaped)
        if ($val !== null && in_array($erpField, ['description', 'name', 'meta_title', 'meta_description'], true)) {
            if (str_contains($val, '&lt;') || str_contains($val, '&gt;') || str_contains($val, '&amp;')) {
                $val = html_entity_decode($val, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }

        return $val;
    }

    /**
     * Resolve all __map: attribute values to actual product field values.
     * Returns a new array with mappings replaced by resolved values.
     */
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

    /**
     * Check if a Lazada attribute is mapped (auto-filled from ERP via __map: prefix).
     */
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
        // Lazada mixes "product"-level and "sku"-level fields.
        // Many category templates mark packaging & promo fields as mandatory at SKU level.
        // If we send these under Attributes, Lazada QC may still treat them as blank.
        // Keep this whitelist strict and explicit.
        return in_array($k, [
            'sellersku', 'seller_sku',
            'price', 'quantity',
            // Packaging (common QC requirements)
            'package_height', 'package_length', 'package_width', 'package_weight',
            'package_content',
            // Promotions / scheduling
            'special_price', 'special_from_date', 'special_to_date',
            'coming_soon', 'delay_delivery_days',
        ], true);
    }

    /**
     * Best-effort extraction of attribute definitions from Lazada category template response.
     * The response shapes vary; we keep this tolerant.
     */
    /**
     * $questionsOnly is the listing questionnaire's view (sale props and
     * __wire__ keys dropped, #175); false is the template inspector's view,
     * which shows the category's whole truth. $withRaw hangs the original
     * row on each entry for the inspector's detail panel.
     */
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
            // Some responses return an array directly
            if (array_is_list($data)) {
                return $this->normalizeAttributes($data, $questionsOnly, $withRaw);
            }
        }
        return [];
    }

    public function normalizeAttributes(array $raw, bool $questionsOnly = true, bool $withRaw = false): array
    {
        $out = [];
        foreach ($raw as $a) {
            if (!is_array($a)) {
                continue;
            }
            /* Sale props are the variation axes (Colour Family, Size): they
             * are consumed by getLazadaSalePropKeys() and sent at SKU level,
             * so showing them on the attribute sheet asked the operator the
             * same question twice. Keys wrapped in underscores (__images__)
             * are wire plumbing, not questions. TikTok's normaliser has
             * dropped both since it was written; Lazada's kept everything
             * (#175). */
            if ($questionsOnly && !empty($a['is_sale_prop'])) {
                continue;
            }
            $name = (string)($a['name'] ?? $a['attribute_name'] ?? '');
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

    /**
     * Fetch Lazada sale-prop (variant) attribute keys for a given category.
     * We need these keys to set SKU-level variation fields so Lazada can distinguish SKUs.
     *
     * Returns up to 2 attribute keys (strings). Empty array if not available.
     */
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
                // Lazada typically provides both 'name' and 'attribute_name'. We want the actual attribute key.
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

    /**
     * Read sale-prop keys for a category from the cached template (lazada_category_templates).
     * Used as a fallback when the live API is rate-limited or unreachable. Returns the keys
     * in the order Lazada returned them — that order is meaningful (first sale-prop is the
     * primary variant axis, second is the secondary, etc.).
     */
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

    /**
     * Map of Lazada sale-prop key → human label (e.g. color_family → "Color Family").
     * Used only for operator-facing error messages.
     */
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

    /**
     * Best-effort fallback for variant keys when category sale-prop keys can't be fetched.
     */
    public function guessLazadaVariantKeysFromOptionName(string $optionName): array
    {
        $n = strtolower(trim($optionName));
        if ($n === '') {
            return ['color_family'];
        }
        if (str_contains($n, 'color')) {
            return ['color_family'];
        }
        if (str_contains($n, 'size')) {
            return ['size'];
        }
        if (str_contains($n, 'length') || str_contains($n, 'cable')) {
            // Some PH categories use 'length' or 'cable_length'; we can't know which without category lookup.
            return ['length'];
        }
        // Generic safe-ish default.
        return ['color_family'];
    }

    /**
     * Resolve __map: attribute values to actual product field values for display/preview.
     * Returns suggested values only for attributes that have __map: stored values.
     */
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

        // Exact normalized match first
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

        // Loose contains match (manufacturer contains brand or vice versa)
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

        // Common fallback: "No Brand"
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
