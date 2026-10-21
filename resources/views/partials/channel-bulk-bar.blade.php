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
        @if(! empty($b['template'])){!! $bulkForm($b['template'], $b['template']['label'] ?? 'Apply template', 'secondary', ' data-bulk-plain') !!}@endif
        @if(! empty($b['push'])){!! $bulkForm($b['push'], $b['push']['label'] ?? 'Push') !!}@endif
        @if(! $isGroup)
            {!! $switches !!}
        @endif
        @if(! empty($b['link'])){!! $bulkForm($b['link'], $b['link']['label'] ?? 'Link IDs') !!}@endif
        @if($isGroup){!! $switches !!}@endif
        @if(! empty($b['delete'])){!! $bulkForm($b['delete'], $b['delete']['label'] ?? 'Delete', 'danger') !!}@endif
        @if(! empty($b['remove'])){!! $bulkForm($b['remove'], $b['remove']['label'] ?? 'Remove', 'danger') !!}@endif
    </div>
</div>
