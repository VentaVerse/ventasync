{{-- ONE bulk bar for every channel's Listings page and group page (the owner,
     2026-09-07). The order never varies; a page draws only the verbs it
     fills. Listings page:

       1  Push to the store             (where the channel offers it)
       2  the store's own switch pair   (data-bulk-verb forms, shown per the ticked rows' states)
       3  Push stock
       4  Push prices
       5  Link IDs
       6  Delete from {Store}
       7  Remove from this channel

     Group page, the same with the group's own verbs in front and its exit
     at the end: Push, Update, Push stock, Push prices, Link IDs, the switch
     pair, Remove from product group. The `group` flag picks that shape.

     Every form carries data-bulk-form: channel-listings.js fills the ticked
     ids into it (the page's own field name) and names what the selection
     skips.

     PUSH IS BACK ON THE LISTINGS PAGE (the owner, 2026-09-09), first in the
     bar wherever it appears. It left on 2026-09-06 for two reasons that
     have both expired: a batch needed a combined form for couriers,
     category and markup, which the group page owned - and every listing
     now takes its own price rule, else its group's, so there is nothing
     shared left to ask for; and a bar reporting one outcome could not
     describe ten creates refused for ten different reasons - and the push
     answers per product, by name, in the marketplace's own words.

     Spec keys:
       switch   list of ['action', 'verb', 'codes', 'label' (with :n), 'button', 'confirm', 'fields' => [...]]
       push     ['action', 'label', 'confirm']   (group only)
       update   ['action', 'label', 'confirm']   (group only)
       stock    ['action']       prices  ['action']
       link     ['action', 'label']
       delete   ['action', 'label', 'confirm']
       remove   ['action', 'label', 'confirm']
       group    bool --}}
@php
    $b = $bulk;
    $isGroup = ! empty($b['group']);
    // The button component's own classes (x-ui.button), written by hand so a
    // form can be built as one string in the fixed order.
    $bulkForm = function (array $a, string $label, string $variant = 'secondary', string $extra = '') {
        $attrs = '';
        if (! empty($a['confirm'])) {
            $attrs .= ' data-bulk-confirm="' . e($a['confirm']) . '"';
        }
        if (! empty($a['field'])) {
            $attrs .= ' data-bulk-field="' . e($a['field']) . '"'; // this form's own id field, when it differs from the page's
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
        {{-- Push leads wherever it is offered, group page or Listings. --}}
        @if(! empty($b['push'])){!! $bulkForm($b['push'], $b['push']['label'] ?? 'Push') !!}@endif
        @if($isGroup)
            @if(! empty($b['update'])){!! $bulkForm($b['update'], $b['update']['label'] ?? 'Update') !!}@endif
        @else
            {!! $switches !!}
        @endif
        @if(! empty($b['stock'])){!! $bulkForm($b['stock'], 'Push stock') !!}@endif
        @if(! empty($b['prices'])){!! $bulkForm($b['prices'], 'Push prices') !!}@endif
        @if(! empty($b['link'])){!! $bulkForm($b['link'], $b['link']['label'] ?? 'Link IDs') !!}@endif
        @if($isGroup){!! $switches !!}@endif
        @if(! empty($b['delete'])){!! $bulkForm($b['delete'], $b['delete']['label'] ?? 'Delete', 'danger') !!}@endif
        @if(! empty($b['remove'])){!! $bulkForm($b['remove'], $b['remove']['label'] ?? 'Remove', 'danger') !!}@endif
    </div>
</div>
