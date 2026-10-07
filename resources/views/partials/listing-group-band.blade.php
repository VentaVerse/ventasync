@php
    $band = $groupBand ?? ['current' => null, 'groups' => [], 'values' => []];
    $current = $band['current'] ?? null;
    $currentUrl = collect($band['groups'])->firstWhere('id', $current)['url'] ?? null;
    $bandJson = json_encode([
        'fields' => $fields ?? [],
        'values' => (object) ($band['values'] ?? []),
        'urls' => (object) collect($band['groups'])->mapWithKeys(fn ($g) => [$g['id'] => $g['url']])->all(),
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
@endphp
<section class="fm-section lg-band" data-group-band aria-labelledby="lg-band-title">
    <div class="fm-section__head">
        <h2 class="fm-section__title" id="lg-band-title"><label for="lg-group">Product group</label></h2>
        <a class="lg-band__open" href="{{ $currentUrl ?? '#' }}" data-group-open @if(! $currentUrl) hidden @endif>Open the group</a>
    </div>
    <div class="lg-band__field">
        <x-ui.select id="lg-group" name="product_group_id" data-group-select :disabled="! ($canManage ?? false)">
            <option value="">No group</option>
            @foreach($band['groups'] as $g)
                <option value="{{ $g['id'] }}" @selected((int) $current === (int) $g['id'])>{{ $g['name'] }}</option>
            @endforeach
        </x-ui.select>
        @if($canManage ?? false)
            <input type="hidden" name="product_group_listed" value="1">
        @endif
    </div>
    <div class="lg-band__diff" data-group-diff hidden>
        <p class="fm-section__note"><span class="lg-band__count" data-group-diff-count></span> differ from the group: <span data-group-diff-names></span></p>
        @if($canManage ?? false)
            <x-ui.button type="button" size="sm" data-group-reset>Use the group's values</x-ui.button>
        @endif
    </div>
    <script type="application/json" data-group-values>{!! $bandJson !!}</script>
</section>
