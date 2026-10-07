@php
    $canManage = $canManage ?? true;
@endphp

@if(!empty($attributes))
    @if($template && $template->fetched_at)
        <p class="fm-section__note">Last read from Lazada {{ $template->fetched_at->format('Y-m-d H:i') }}.</p>
    @endif

    <div class="gf-attrs">
        @foreach($attributes as $attribute)
            @php
                $key = (string) ($attribute['key'] ?? '');
            @endphp
            @continue(strtolower(trim($key)) === 'brand')
            @continue(\Extensions\lazada\Services\Lazada\LazadaAttributes::isAutoFilledAttributeKey($key)
                && (trim((string) ($saved[$key] ?? '')) === '' || str_starts_with((string) ($saved[$key] ?? ''), '__map:')))
            @php
                $value = old('attributes.' . $key, $saved[$key] ?? '');
                $options = $attribute['options'] ?? [];
                $isSelect = is_array($options) && count($options) > 0;
                $controlId = 'attr-' . \Illuminate\Support\Str::slug($key, '-');

                $isMapped = str_starts_with((string) $value, '__map:');
                $mappedField = $isMapped ? substr((string) $value, 6) : '';
                $typedValue = $isMapped ? '' : $value;
            @endphp
            <div class="gf-attr">
                <div class="gf-attr__label">
                    <label class="gf-attr__name" for="{{ $controlId }}">
                        {{ $attribute['name'] }}
                        @if(!empty($attribute['required']))<span class="gf-attr__req" aria-hidden="true">*</span><span class="x-sr">(required)</span>@endif
                    </label>
                    <span class="gf-attr__key">{{ $key }}</span>
                </div>

                <div class="gf-attr__control">
                    @if($isSelect)
                        <div class="x-select-wrap">
                            <select id="{{ $controlId }}" name="attributes[{{ $key }}]" class="x-input" @disabled(!$canManage)>
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
                                <select class="x-input" data-attr-mode aria-label="How {{ $attribute['name'] }} is filled in" @disabled(!$canManage)>
                                    <option value="manual" @selected(!$isMapped)>Type a value</option>
                                    <option value="product_field" @selected($isMapped)>Read it off the product</option>
                                </select>
                                <x-ui.icon name="chevron-down" size="14" class="x-select-wrap__chevron" />
                            </div>

                            <div class="gf-attr__value">
                                <input id="{{ $controlId }}" class="x-input" data-attr-manual data-attr-manual-box
                                       value="{{ $typedValue }}" placeholder="Not set"
                                       aria-label="{{ $attribute['name'] }}"
                                       @disabled(!$canManage) @if($isMapped) hidden @endif>

                                <div class="x-select-wrap" data-attr-field-box @unless($isMapped) hidden @endunless>
                                    <select class="x-input" data-attr-field aria-label="Which product field {{ $attribute['name'] }} is read from" @disabled(!$canManage)>
                                        <option value="">Pick a product field</option>
                                        @foreach($erpSourceFields as $fieldKey => $fieldLabel)
                                            <option value="{{ $fieldKey }}" @selected($isMapped && $mappedField === $fieldKey)>{{ $fieldLabel }}</option>
                                        @endforeach
                                    </select>
                                    <x-ui.icon name="chevron-down" size="14" class="x-select-wrap__chevron" />
                                </div>
                            </div>

                            <input type="hidden" name="attributes[{{ $key }}]" data-attr-value value="{{ $value }}">
                        </div>
                    @endif

                    @if($errors->has('attributes.' . $key))
                        <div class="gf-attr__error">{{ $errors->first('attributes.' . $key) }}</div>
                    @endif
                </div>
            </div>
        @endforeach
    </div>
@endif
