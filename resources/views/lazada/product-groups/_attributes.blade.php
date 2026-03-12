@if(!empty($attributes))
<h3 class="section-title mt-0">Category Attributes</h3>
<div class="hint" style="margin-bottom:8px;">Map shared attribute values for this Lazada category.
    @if($template && $template->fetched_at)
        Last synced: {{ $template->fetched_at->format('Y-m-d H:i') }}
    @endif
</div>
<div>
    @foreach($attributes as $a)
        @php
            $key = (string)($a['key'] ?? '');
            if (strtolower(trim($key)) === 'brand') { continue; }
            $val = old('attributes.'.$key, $saved[$key] ?? '');
            $options = $a['options'] ?? [];
            $isSelect = is_array($options) && count($options) > 0;
        @endphp
        <div style="display:grid; grid-template-columns: 1fr 2fr; gap: 12px; padding: 10px 0; border-bottom: 1px solid var(--border-light); align-items:start;">
            <div>
                <div class="font-semibold">
                    {{ $a['name'] }}
                    @if(!empty($a['required']))
                        <span class="badge badge-red" style="margin-left:6px;">MANDATORY</span>
                    @endif
                </div>
                <div class="hint" style="margin-top:4px;">{{ $key }}</div>
            </div>
            <div>
                @if($isSelect)
                    <select class="input" name="attributes[{{ $key }}]">
                        <option value="">-- Select --</option>
                        @foreach($options as $opt)
                            @php
                                $optLabel = is_array($opt) ? (string)($opt['name'] ?? $opt['label'] ?? $opt['value'] ?? '') : (string)$opt;
                                $optValue = is_array($opt) ? (string)($opt['name'] ?? $opt['label'] ?? $opt['value'] ?? '') : (string)$opt;
                            @endphp
                            <option value="{{ $optValue }}" {{ (string)$val === (string)$optValue ? 'selected' : '' }}>{{ $optLabel }}</option>
                        @endforeach
                    </select>
                @else
                    @php
                        $isMapVal = str_starts_with((string)$val, '__map:');
                        $mapField = $isMapVal ? substr((string)$val, 6) : '';
                        $manualVal = $isMapVal ? '' : $val;
                    @endphp
                    <div class="attr-row" style="display:grid; grid-template-columns: 1fr 1fr; gap: 8px;">
                        <select class="input attr-mode-toggle">
                            <option value="manual" {{ !$isMapVal ? 'selected' : '' }}>Manual Input</option>
                            <option value="product_field" {{ $isMapVal ? 'selected' : '' }}>Product Field</option>
                        </select>
                        <input class="input attr-manual-input" value="{{ $manualVal }}" placeholder="Enter value" style="{{ $isMapVal ? 'display:none;' : '' }}">
                        <select class="input attr-field-select" style="{{ !$isMapVal ? 'display:none;' : '' }}">
                            <option value="">-- Select Product Field --</option>
                            @foreach($erpSourceFields as $erpKey => $erpLabel)
                                <option value="{{ $erpKey }}" {{ $isMapVal && $mapField === $erpKey ? 'selected' : '' }}>{{ $erpLabel }}</option>
                            @endforeach
                        </select>
                        <input type="hidden" name="attributes[{{ $key }}]" class="attr-hidden-value" value="{{ $val }}">
                    </div>
                @endif
            </div>
        </div>
    @endforeach
</div>
@endif
