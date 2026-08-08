{{-- Shopee category attributes: one row per attribute Shopee defines for the
     chosen category.

     This partial is rendered twice over: inline by the group form, and as the
     body of ShopeeProductGroupController::fetchAttributesAjax()'s JSON
     response when the operator changes the category. Its markup is therefore
     part of that endpoint's contract, not only of the form's, and the two
     paths have to land looking identical. That is why the row grammar lives
     in resources/css/pages/group-form.css (.gf-attrs / .gf-attr) rather than
     on the page, and why nothing here needs a script to restyle it after an
     injection.

     The wire is unchanged: one `attributes[<key>]` control per attribute,
     values from old() then from what was saved. The controller reads them raw
     off the request and hands them to saveGroupAttributes().

     `$canManage` is optional and defaults to true because the AJAX path is
     manage-tier only (perm:manage_shopee on the fetch-attributes route), so
     anything that reaches this partial that way is already the manage tier.
     The form passes it explicitly for the read tier. --}}
@php
    $canManage = $canManage ?? true;
@endphp

@if(!empty($attributes))
    @if($template && $template->fetched_at)
        <p class="fm-section__note">Last read from Shopee {{ $template->fetched_at->format('Y-m-d H:i') }}.</p>
    @endif

    <div class="gf-attrs">
        @foreach($attributes as $attribute)
            @php
                $key = (string) ($attribute['key'] ?? '');
                $value = old('attributes.' . $key, $saved[$key] ?? '');
                $options = $attribute['options'] ?? [];
                $isSelect = is_array($options) && count($options) > 0;
                $controlId = 'attr-' . \Illuminate\Support\Str::slug($key, '-');
            @endphp
            <div class="gf-attr">
                <div class="gf-attr__label">
                    <label class="gf-attr__name" for="{{ $controlId }}">
                        {{ $attribute['name'] }}
                        {{-- Said in words at label weight, not as a red badge:
                             a column of twenty red badges teaches the operator
                             that red is decoration. --}}
                        @if(!empty($attribute['required']))<span class="gf-attr__req">Required</span>@endif
                    </label>
                    {{-- Shopee's attribute key is its numeric attribute_id, so
                         a bare "1" under the name says nothing. It is worth
                         showing because it is what comes back in an API
                         rejection, but only if it says what it is. Lazada's
                         keys are real names and need no such prefix. --}}
                    <span class="gf-attr__key">{{ ctype_digit($key) ? 'Shopee attribute ' . $key : $key }}</span>
                </div>

                <div class="gf-attr__control">
                    @if($isSelect)
                        <div class="x-select-wrap">
                            <select id="{{ $controlId }}" name="attributes[{{ $key }}]" class="x-input" @disabled(!$canManage)>
                                <option value="">Not set</option>
                                @foreach($options as $option)
                                    @php
                                        $optionValue = is_array($option)
                                            ? (string) ($option['original_value_name'] ?? $option['display_value_name'] ?? $option['value_name'] ?? $option['name'] ?? $option['value'] ?? '')
                                            : (string) $option;
                                    @endphp
                                    <option value="{{ $optionValue }}" @selected((string) $value === $optionValue)>{{ $optionValue }}</option>
                                @endforeach
                            </select>
                            <x-ui.icon name="chevron-down" size="14" class="x-select-wrap__chevron" />
                        </div>
                    @else
                        <input id="{{ $controlId }}" name="attributes[{{ $key }}]" class="x-input"
                               value="{{ $value }}" placeholder="Not set" @disabled(!$canManage)>
                    @endif

                    @if($errors->has('attributes.' . $key))
                        <div class="gf-attr__error">{{ $errors->first('attributes.' . $key) }}</div>
                    @endif
                </div>
            </div>
        @endforeach
    </div>
@endif
