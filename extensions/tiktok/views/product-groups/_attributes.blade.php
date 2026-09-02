{{-- TikTok category attributes: one row per product property TikTok asks
     for in the chosen category (the sales properties are the variation
     axes, which the push names from the catalog). Rendered inline by the
     preset form and the listing page, and as the body of
     fetchAttributesAjax()'s JSON when the category changes, so the row
     grammar lives in pages/group-form.css (.gf-attrs / .gf-attr) and the
     wire is one `attributes[<id>]` control per attribute. --}}
@php
    $canManage = $canManage ?? true;
    $rows = $rows ?? [];
    $saved = $saved ?? [];
@endphp
@if(!empty($rows))
    @if(($template ?? null) && $template->fetched_at)
        <p class="fm-section__note">Last read from TikTok Shop {{ $template->fetched_at->format('Y-m-d H:i') }}.</p>
    @endif
    <div class="gf-attrs">
        @foreach($rows as $row)
            @php
                $key = (string) $row['key'];
                $value = old('attributes.' . $key, $saved[$key] ?? '');
                $controlId = 'tt-attr-' . \Illuminate\Support\Str::slug($key, '-');
                $isSelect = !empty($row['options']);
            @endphp
            <div class="gf-attr">
                <div class="gf-attr__label">
                    <label class="gf-attr__name" for="{{ $controlId }}">
                        {{ $row['name'] }}
                        @if($row['required'])<span class="gf-attr__req">Required</span>@endif
                    </label>
                    <span class="gf-attr__key">TikTok attribute {{ $key }}</span>
                </div>
                <div class="gf-attr__control">
                    @if($isSelect)
                        <div class="x-select-wrap">
                            <select id="{{ $controlId }}" name="attributes[{{ $key }}]" class="x-input" @disabled(!$canManage)>
                                <option value="">Not set</option>
                                @foreach($row['options'] as $opt)
                                    <option value="{{ $opt['id'] !== '' ? $opt['id'] : $opt['name'] }}"
                                            @selected((string) $value === (string) $opt['id'] || (string) $value === (string) $opt['name'])>{{ $opt['name'] }}</option>
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
@else
    <p class="fm-section__note">{{ ($template ?? null) ? 'TikTok Shop asks nothing extra for this category.' : 'Pick a category and the attributes TikTok Shop asks for it appear here.' }}</p>
@endif
