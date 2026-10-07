<div class="gf-attrs">
    @foreach($attributes as $attribute)
        @php
            $key = (string) ($attribute['key'] ?? '');
        @endphp
        @continue(strtolower(trim($key)) === 'brand')
        @php
            $suggestion = $suggested[$key] ?? null;
            $rawSaved = $saved[$key] ?? '';
        @endphp
        @continue(\Extensions\lazada\Services\Lazada\LazadaAttributes::isAutoFilledAttributeKey($key)
            && (trim((string) $rawSaved) === '' || str_starts_with((string) $rawSaved, '__map:')))
        @php
            $fromGroup = !array_key_exists($key, ($productOwnAttrs ?? []))
                && array_key_exists($key, ($groupAttrs ?? []));

            $value = old('attributes.' . $key, $saved[$key] ?? ($suggestion ?? ''));
            $options = $attribute['options'] ?? [];
            $isSelect = is_array($options) && count($options) > 0;
            $controlId = 'lz-attr-' . \Illuminate\Support\Str::slug($key, '-');

            if ($isSelect && $suggestion !== null) {
                $suggestionIsAChoice = false;
                foreach ($options as $option) {
                    $candidate = is_array($option)
                        ? (string) ($option['name'] ?? $option['label'] ?? $option['value'] ?? '')
                        : (string) $option;
                    if ((string) $suggestion === $candidate) { $suggestionIsAChoice = true; break; }
                }
                if (!$suggestionIsAChoice && !isset($saved[$key]) && !old('attributes.' . $key)) {
                    $value = '';
                }
            }

            $isMapped = str_starts_with((string) $rawSaved, '__map:');
            $mappedField = $isMapped ? substr((string) $rawSaved, 6) : '';
            $typedValue = $isMapped ? '' : $value;
            $resolvedRaw = ($isMapped && isset($suggested[$key])) ? $suggested[$key] : null;
            $resolvedValue = $resolvedRaw !== null
                ? mb_substr(strip_tags(html_entity_decode((string) $resolvedRaw, ENT_QUOTES | ENT_HTML5, 'UTF-8')), 0, 200)
                : '';
        @endphp
        <div class="gf-attr">
            <div class="gf-attr__label">
                <label class="gf-attr__name" for="{{ $controlId }}">
                    @php
                        $attrName = (string) $attribute['name'];
                        $attrWords = preg_match('/^[a-z0-9_]+$/i', $attrName) && str_contains($attrName, '_')
                            ? ucfirst(str_replace('_', ' ', trim($attrName, '_')))
                            : $attrName;
                    @endphp
                    {{ $attrWords }}
                    @if(!empty($attribute['required']))<span class="gf-attr__req" aria-hidden="true">*</span><span class="x-sr">(required)</span>@endif
                </label>
                <span class="gf-attr__key">{{ $key }}</span>
            </div>

            <div class="gf-attr__control">
                @if($isSelect)
                    <div class="x-select-wrap">
                        <select id="{{ $controlId }}" name="attributes[{{ $key }}]" class="x-input">
                            <option value="">Not set</option>
                            @foreach($options as $option)
                                @php
                                    $optionValue = is_array($option)
                                        ? (string) ($option['name'] ?? $option['label'] ?? $option['value'] ?? '')
                                        : (string) $option;
                                @endphp
                                <option value="{{ $optionValue }}" @selected((string) $value === $optionValue)>{{ $optionValue }}</option>
                            @endforeach
                        </select>
                        <x-ui.icon name="chevron-down" size="14" class="x-select-wrap__chevron" />
                    </div>
                @else
                    <div class="gf-attr__mode" data-attr-row>
                        <div class="x-select-wrap">
                            <select class="x-input" data-attr-mode aria-label="How {{ $attribute['name'] }} is filled in">
                                <option value="manual" @selected(!$isMapped)>Type a value</option>
                                <option value="product_field" @selected($isMapped)>Read it off the product</option>
                            </select>
                            <x-ui.icon name="chevron-down" size="14" class="x-select-wrap__chevron" />
                        </div>

                        <div class="gf-attr__value">
                            <input id="{{ $controlId }}" class="x-input" data-attr-manual data-attr-manual-box
                                   value="{{ $typedValue }}" placeholder="Not set"
                                   aria-label="{{ $attribute['name'] }}"
                                   @if($isMapped) hidden @endif>

                            <div class="x-select-wrap" data-attr-field-box @unless($isMapped) hidden @endunless>
                                <select class="x-input" data-attr-field aria-label="Which product field {{ $attribute['name'] }} is read from">
                                    <option value="">Pick a product field</option>
                                    @foreach($erpSourceFields as $fieldKey => $fieldLabel)
                                        <option value="{{ $fieldKey }}" @selected($isMapped && $mappedField === $fieldKey)>{{ $fieldLabel }}</option>
                                    @endforeach
                                </select>
                                <x-ui.icon name="chevron-down" size="14" class="x-select-wrap__chevron" />
                            </div>
                        </div>

                        <input type="hidden" name="attributes[{{ $key }}]" data-attr-value value="{{ $isMapped ? $rawSaved : $value }}">

                        <div class="gf-attr__resolved" data-attr-resolved @unless($isMapped) hidden @endunless>
                            Currently reads <strong data-attr-resolved-text>{{ $resolvedValue }}</strong>
                        </div>
                    </div>
                @endif

                @if($fromGroup)
                    <p class="gf-attr__note">Inherited from the product group. Saving here overrides it for this listing.</p>
                @endif

                @if(!$isSelect && !$isMapped && empty($saved[$key] ?? null) && empty(old('attributes.' . $key)) && !empty($suggestion))
                    <p class="gf-attr__note">The catalog suggests {{ $suggestion }}.</p>
                @endif

                @if(isset($errors) && $errors->has('attributes.' . $key))
                    <div class="gf-attr__error">{{ $errors->first('attributes.' . $key) }}</div>
                @elseif(!empty($attribute['required']) && trim((string) $value) === '' && trim((string) $rawSaved) === '')
                    <div class="gf-attr__need">Required before a push.</div>
                @endif
            </div>
        </div>
    @endforeach
</div>
