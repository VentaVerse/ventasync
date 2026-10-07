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
                        @if(!empty($attribute['required']))<span class="gf-attr__req" aria-hidden="true">*</span><span class="x-sr">(required)</span>@endif
                    </label>
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
