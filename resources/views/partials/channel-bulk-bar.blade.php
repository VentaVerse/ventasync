@php
    $b = $bulk;
    $isGroup = ! empty($b['group']);
    $bulkForm = function (array $a, string $label, string $variant = 'secondary', string $extra = '') {
        $attrs = '';
        if (! empty($a['confirm'])) {
            $attrs .= ' data-bulk-confirm="' . e($a['confirm']) . '"';
        }
        if (! empty($a['field'])) {
            $attrs .= ' data-bulk-field="' . e($a['field']) . '"';
        }
        $fields = '';
        foreach ((array) ($a['fields'] ?? []) as $k => $v) {
            $fields .= '<input type="hidden" name="' . e($k) . '" value="' . e($v) . '">';
        }

        return '<form method="POST" action="' . e($a['action']) . '" data-bulk-form' . $attrs . $extra . '>' . csrf_field() . $fields
            . '<button type="submit" class="x-btn x-btn--' . e($variant) . ' x-btn--sm">' . e($label) . '</button></form>';
    };
    $switches = '';
    foreach ((array) ($b['switch'] ?? []) as $s) {
        $switches .= $bulkForm($s, $s['button'], 'secondary',
            ' data-bulk-verb="' . e($s['verb']) . '" data-verb-codes="' . e($s['codes']) . '" data-verb-label="' . e($s['label']) . '" hidden');
    }
@endphp
<div class="cc-bulkbar" x-show="selected.length > 0" x-cloak data-bulkbar role="status">
    <span class="cc-bulkbar__count" aria-live="polite"><span class="x-num" x-text="selected.length"></span> selected <span class="cc-bulkbar__note" data-bulk-skipped></span></span>
    <div class="cc-bulkbar__actions">
        @if(! empty($b['push'])){!! $bulkForm($b['push'], $b['push']['label'] ?? 'Push') !!}@endif
        @if(! $isGroup)
            {!! $switches !!}
        @endif
        @if(! empty($b['move']))
            <button type="button" class="x-btn x-btn--secondary x-btn--sm" @click="$dispatch('open-move-picker')">Move to group</button>
        @endif
        @if(! empty($b['link'])){!! $bulkForm($b['link'], $b['link']['label'] ?? 'Link IDs') !!}@endif
        @if($isGroup){!! $switches !!}@endif
        @if(! empty($b['delete'])){!! $bulkForm($b['delete'], $b['delete']['label'] ?? 'Delete', 'danger') !!}@endif
        @if(! empty($b['ungroup'])){!! $bulkForm($b['ungroup'] + ['field' => 'ids[]', 'fields' => ['_return' => $b['ungroup']['return'] ?? '']], $b['ungroup']['label'] ?? 'Remove from group', 'danger') !!}@endif
        @if(! empty($b['remove'])){!! $bulkForm($b['remove'], $b['remove']['label'] ?? 'Remove', 'danger') !!}@endif
    </div>
</div>
@if(! empty($b['move']))
    <div class="modal-backdrop" :class="{ active: picking }" x-data="{ picking: false }"
         @open-move-picker.window="picking = true" @keydown.escape.window="picking = false" @click.self="picking = false">
        <div class="modal modal--sm cc-grouppick" role="dialog" aria-modal="true" aria-labelledby="cc-grouppick-title">
            <div class="modal-header">
                <h2 id="cc-grouppick-title">Move <span x-text="selected.length"></span> to a group</h2>
                <button class="modal-close" type="button" @click="picking = false" aria-label="Close">&times;</button>
            </div>
            <div class="cc-grouppick__list">
                @foreach($b['move']['groups'] as $g)
                    @continue((string) $g['id'] === (string) ($b['move']['current'] ?? ''))
                    <form method="POST" action="{{ $b['move']['action'] }}" data-bulk-form data-bulk-field="ids[]">
                        @csrf
                        <input type="hidden" name="group" value="{{ $g['id'] }}">
                        <input type="hidden" name="_return" value="{{ $b['move']['return'] ?? '' }}">
                        <button type="submit" class="cc-grouppick__pick">
                            <span class="cc-grouppick__name">{{ $g['name'] }}</span>
                            <span class="cc-grouppick__n">{{ number_format((int) $g['count']) }}</span>
                        </button>
                    </form>
                @endforeach
                @if(($b['move']['current'] ?? '') !== 'none')
                    <form method="POST" action="{{ $b['move']['action'] }}" data-bulk-form data-bulk-field="ids[]">
                        @csrf
                        <input type="hidden" name="group" value="none">
                        <input type="hidden" name="_return" value="{{ $b['move']['return'] ?? '' }}">
                        <button type="submit" class="cc-grouppick__pick cc-grouppick__pick--out">
                            <span class="cc-grouppick__name">{{ \App\Integrations\Listings\StatusMenu::UNGROUPED }}</span>
                        </button>
                    </form>
                @endif
            </div>
        </div>
    </div>
@endif
