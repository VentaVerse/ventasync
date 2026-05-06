@if(!empty($attributes))
<h3 class="section-title mt-0">Category Attributes</h3>
<div class="hint" style="margin-bottom:8px;">Map shared attribute values for this Shopee category.
    @if($template && $template->fetched_at)
        Last synced: {{ $template->fetched_at->format('Y-m-d H:i') }}
    @endif
</div>
<div>
    @foreach($attributes as $a)
        @php
            $key = (string)($a['key'] ?? '');
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
                                $optLabel = is_array($opt) ? (string)($opt['original_value_name'] ?? $opt['display_value_name'] ?? $opt['value_name'] ?? $opt['name'] ?? $opt['value'] ?? '') : (string)$opt;
                                $optValue = is_array($opt) ? (string)($opt['original_value_name'] ?? $opt['display_value_name'] ?? $opt['value_name'] ?? $opt['name'] ?? $opt['value'] ?? '') : (string)$opt;
                            @endphp
                            <option value="{{ $optValue }}" {{ (string)$val === (string)$optValue ? 'selected' : '' }}>{{ $optLabel }}</option>
                        @endforeach
                    </select>
                @else
                    <input class="input" name="attributes[{{ $key }}]" value="{{ $val }}" placeholder="Enter value">
                @endif
            </div>
        </div>
    @endforeach
</div>
@endif
