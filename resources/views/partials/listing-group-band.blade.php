{{--
    The Product group band, first in every listing page's main column. The
    group is the listing's template: picking one moves the product into it
    and fills the page's fields with its values; "Use the group's values"
    does the same for the group already picked. Both save with Save.

    Expected keys:
      groupBand  array{current: ?int, groups: list<array{id: int, name: string, url: string}>,
                       values: array<int, array<string, mixed>>}     from the controller
      fields     array<string, array{label: string, kind: string, ...}> the page's
                 group-held fields; kinds are read by resources/js/pages/listing-group-band.js
      canManage  bool
--}}
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
<section class="lg-band" data-group-band aria-labelledby="lg-band-label">
    <div class="lg-band__row">
        <div class="lg-band__field">
            <label class="lg-band__label" id="lg-band-label" for="lg-group">Product group</label>
            <select id="lg-group" name="product_group_id" class="x-input lg-band__select" data-group-select @disabled(! ($canManage ?? false))>
                <option value="">No group</option>
                @foreach($band['groups'] as $g)
                    <option value="{{ $g['id'] }}" @selected((int) $current === (int) $g['id'])>{{ $g['name'] }}</option>
                @endforeach
            </select>
            @if($canManage ?? false)
                <input type="hidden" name="product_group_listed" value="1">
            @endif
        </div>
        <a class="lg-band__open" href="{{ $currentUrl ?? '#' }}" data-group-open @if(! $currentUrl) hidden @endif>Open the group</a>
    </div>
    <div class="lg-band__diff" data-group-diff hidden>
        <p><span class="lg-band__count" data-group-diff-count></span> differ from the group: <span data-group-diff-names></span></p>
        @if($canManage ?? false)
            <x-ui.button type="button" size="sm" data-group-reset>Use the group's values</x-ui.button>
        @endif
    </div>
    <script type="application/json" data-group-values>{!! $bandJson !!}</script>
</section>
