{{--
    The API scope matrix, shared by create and edit.

    One radio group per resource, named `scopes[{resource}]`, posting a
    level: none, read, or write. Write posts both abilities, because the
    scope middleware checks each ability on its own and a token that can
    change something it cannot read is never what anyone meant.
    ApiClientController@resolveScopes turns the level into abilities and
    drops any level the catalog does not offer for that resource.

    Expects:
      $scopes  ApiScopeRegistry::all(), keyed by resource
      $current array of "resource:action" strings already granted (edit
               page), or [] on create
--}}
@php
    $current = $current ?? [];
    $levels = ['none' => 'None', 'read' => 'Read', 'write' => 'Read & write'];
@endphp

<div class="st-scopes" data-scope-matrix>
    <div class="st-scopes__head">
        <span>Resource</span>
        <span class="st-scopes__all" role="group" aria-label="Set every resource">
            <span class="st-scopes__all-lead" aria-hidden="true">Set all</span>
            @foreach($levels as $value => $label)
                <button type="button" class="st-scopes__all-btn" data-scope-all="{{ $value }}"
                        aria-label="Set every resource to {{ $label }}">{{ $label }}</button>
            @endforeach
        </span>
    </div>

    @foreach($scopes as $key => $meta)
        @php
            $offersWrite = in_array('write', $meta['actions'], true);
            $offered = $offersWrite ? $levels : array_slice($levels, 0, 2, true);

            // An edit page shows what the token holds; a failed save shows
            // what was posted. A legacy write-only grant reads as Read & write.
            $held = in_array("$key:write", $current, true) && $offersWrite
                ? 'write'
                : (in_array("$key:read", $current, true) ? 'read' : 'none');
            $level = old("scopes.$key", $held);
            if (is_array($level)) {
                $level = ! empty($level['write']) && $offersWrite ? 'write' : (! empty($level['read']) ? 'read' : 'none');
            }
            if (! array_key_exists($level, $offered)) {
                $level = 'none';
            }
        @endphp

        <div class="st-scope">
            <div class="st-scope__info">
                <div class="st-scope__name" id="st-scope-{{ $key }}">
                    {{ $meta['label'] }}
                    <code class="st-scope__key">{{ $key }}</code>
                </div>
                <div class="st-scope__desc">{{ $meta['description'] }}</div>
            </div>

            <div class="st-level" role="radiogroup" aria-labelledby="st-scope-{{ $key }}"
                 data-segments="{{ count($offered) }}">
                <span class="st-level__thumb" aria-hidden="true"></span>
                @foreach($offered as $value => $label)
                    <label class="st-level__seg">
                        <input type="radio" class="st-level__input" name="scopes[{{ $key }}]"
                               value="{{ $value }}" @checked($level === $value)>
                        <span>{{ $label }}</span>
                    </label>
                @endforeach
            </div>
        </div>
    @endforeach
</div>

@error('scopes')<div class="fm-error">{{ $message }}</div>@enderror
