{{-- ONE bulk bar for every channel's Listings page and group page (the owner,
     2026-09-07). The order never varies; a page draws only the verbs it
     fills. Listings page:

       1  Send to the store             (where the channel offers it)
       2  the store's own switch pair   (data-bulk-verb forms, shown per the ticked rows' states)
       3  Link IDs
       4  Delete from {Store}
       5  Remove from this channel

     Group page, the same with the group's own verbs in front and its exit
     at the end: Send, Update, Link IDs, the switch pair, Remove from
     product group. The `group` flag picks that shape.

     Stock and price left the bar with the row menu's copies of them: they
     were the API's shape showing through, and Send carries whatever the
     channel's listing carries.

     Every form carries data-bulk-form: channel-listings.js fills the ticked
     ids into it (the page's own field name) and names what the selection
     skips.

     SEND LEADS THE BAR wherever it appears. It was once kept off the
     Listings page for two reasons that have both expired: a batch needed a
     combined form for couriers, category and markup, which the group page
     owned - and every listing now takes its own price rule, else its
     group's, so there is nothing shared left to ask for; and a bar
     reporting one outcome could not describe ten creates refused for ten
     different reasons - and a send answers per product, by name, in the
     marketplace's own words.

     Spec keys:
       switch   list of ['action', 'verb', 'codes', 'label' (with :n), 'button', 'confirm', 'fields' => [...]]
       push     ['action', 'label', 'confirm']   (group only)
       update   ['action', 'label', 'confirm']   (group only)
       link     ['action', 'label']
       delete   ['action', 'label', 'confirm']
       remove   ['action', 'label', 'confirm']
       move     ['action', 'groups' => [['id', 'name', 'count']], 'current', 'return']   Listings page: file the ticked rows into a group, or out of every group
       ungroup  ['action', 'label', 'confirm', 'return']   Listings page inside a group: take the ticked rows out of it
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
    {{-- The group picker sits beside the bar, not inside it: the bar is a
         sticky layer, and a dialog drawn inside one is covered by the page. --}}
    <div class="modal-backdrop" :class="{ active: picking }" x-data="{ picking: false }"
         @open-move-picker.window="picking = true" @keydown.escape.window="picking = false" @click.self="picking = false">
        <div class="modal modal--sm cc-move" role="dialog" aria-modal="true" aria-labelledby="cc-move-title">
            <div class="modal-header">
                <h2 id="cc-move-title">Move <span x-text="selected.length"></span> to a group</h2>
                <button class="modal-close" type="button" @click="picking = false" aria-label="Close">&times;</button>
            </div>
            <div class="cc-move__list">
                @foreach($b['move']['groups'] as $g)
                    @continue((string) $g['id'] === (string) ($b['move']['current'] ?? ''))
                    <form method="POST" action="{{ $b['move']['action'] }}" data-bulk-form data-bulk-field="ids[]">
                        @csrf
                        <input type="hidden" name="group" value="{{ $g['id'] }}">
                        <input type="hidden" name="_return" value="{{ $b['move']['return'] ?? '' }}">
                        <button type="submit" class="cc-move__pick">
                            <span class="cc-move__name">{{ $g['name'] }}</span>
                            <span class="cc-move__n">{{ number_format((int) $g['count']) }}</span>
                        </button>
                    </form>
                @endforeach
                @if(($b['move']['current'] ?? '') !== 'none')
                    <form method="POST" action="{{ $b['move']['action'] }}" data-bulk-form data-bulk-field="ids[]">
                        @csrf
                        <input type="hidden" name="group" value="none">
                        <input type="hidden" name="_return" value="{{ $b['move']['return'] ?? '' }}">
                        <button type="submit" class="cc-move__pick cc-move__pick--out">
                            <span class="cc-move__name">{{ \App\Integrations\Listings\StatusMenu::UNGROUPED }}</span>
                        </button>
                    </form>
                @endif
            </div>
        </div>
    </div>
@endif
