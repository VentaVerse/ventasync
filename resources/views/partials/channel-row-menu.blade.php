{{-- ONE row menu for every channel's Listings page and group page (the owner,
     2026-09-07: "make sure the sequence is also unified"). The order is the
     life of a listing and never varies: edit it, send it, keep it in step,
     switch it, then the links, then the exits.

       1  Edit listing            (absent on a group page, by decision)
       2  Edit in catalog
       3  Send to {Store}         one verb, whether it is on the store yet or not
       4  the store's own switch  Delist / Publish, Deactivate / Activate ...
       5  Check against {Store}
       6  Link ID                 when not linked         / Unlink ID when it is
       7  Delete from {Store}     when linked
       8  Remove from this channel, or Remove from product group

     Stock and price are not verbs here. They were the API's shape showing
     through: a channel whose update call cannot carry a figure needed a
     second button, and the seller had to know which. Send carries whatever
     the channel's own listing carries, making as many calls as that takes,
     and the scheduled syncs keep the figures fresh between sends.

     Each page hands in a spec: only the keys it fills are drawn, so a
     channel without a piece simply has no line for it, and a channel that
     gains the piece gains the line in the same place. Every form posts
     with the busy overlay (data-slow-action) and the shared confirm sheet.

     Spec keys (all optional but `label`):
       label     the menu's aria label
       edit      url                     catalog   url
       push      ['href' => url, 'title'] or ['action' => url, 'label', 'confirm', 'verb', 'tone', 'attrs' => [...]]
                 any form may carry 'fields' => [name => value] and 'method' => 'DELETE'
       update    ['action' => url, 'label', 'confirm', 'verb', 'tone']
       switch    ['action' => url, 'label', 'confirm', 'verb', 'tone', 'fields' => [...]]
                 or ['href' => url, 'label', 'title'] when a knowable gap blocks it
       check     ['action' => url, 'label', 'fields' => [...]]
       link      ['action' => url, 'label']         unlink  ['action' => url, 'confirm']
       delete    ['action' => url, 'label', 'confirm', 'verb']
       remove    ['action' => url, 'label', 'confirm', 'verb']
       store     the store's display name, for the default labels --}}
@php
    $m = $menu;
    $storeWord = (string) ($m['store'] ?? 'the store');
    $formOf = function (array $a, string $label, string $class = 'x-menu__item') {
        $attrs = '';
        foreach ((array) ($a['attrs'] ?? []) as $k => $v) {
            $attrs .= ' ' . e($k) . '="' . e($v) . '"';
        }
        if (! empty($a['confirm'])) {
            $attrs .= ' data-confirm="' . e($a['confirm']) . '"';
            if (! empty($a['verb'])) {
                $attrs .= ' data-confirm-verb="' . e($a['verb']) . '"';
            }
            if (! empty($a['tone'])) {
                $attrs .= ' data-confirm-tone="' . e($a['tone']) . '"';
            }
        }
        $fields = '';
        foreach ((array) ($a['fields'] ?? []) as $k => $v) {
            $fields .= '<input type="hidden" name="' . e($k) . '" value="' . e($v) . '">';
        }

        if (! empty($a['method']) && strtoupper((string) $a['method']) !== 'POST') {
            $fields .= method_field(strtoupper((string) $a['method']));
        }

        return '<form method="POST" action="' . e($a['action']) . '" data-slow-action' . $attrs . '>' . csrf_field() . $fields
            . '<button type="submit" class="' . e($class) . '">' . e($label) . '</button></form>';
    };
    $linkOf = function (array $a, string $label) {
        $attrs = '';
        if (! empty($a['title'])) {
            $attrs .= ' title="' . e($a['title']) . '"';
        }
        foreach ((array) ($a['attrs'] ?? []) as $k => $v) {
            $attrs .= ' ' . e($k) . '="' . e($v) . '"';
        }

        return '<a class="x-menu__item" href="' . e($a['href']) . '"' . $attrs . '>' . e($label) . '</a>';
    };
@endphp
<x-ui.menu label="{{ $m['label'] ?? 'Actions' }}">
    @if(! empty($m['edit']))
        <a class="x-menu__item" href="{{ $m['edit'] }}">Edit listing</a>
    @endif
    @if(! empty($m['catalog']))
        <a class="x-menu__item" href="{{ $m['catalog'] }}">Edit in catalog</a>
    @endif

    @if(! empty($m['push']))
        @if(! empty($m['push']['href']))
            {!! $linkOf($m['push'], $m['push']['label'] ?? 'Push to ' . $storeWord) !!}
        @else
            {!! $formOf($m['push'], $m['push']['label'] ?? 'Push to ' . $storeWord) !!}
        @endif
    @endif
    @if(! empty($m['update']))
        {!! $formOf($m['update'], $m['update']['label'] ?? 'Update on ' . $storeWord) !!}
    @endif
    @if(! empty($m['switch']))
        @if(! empty($m['switch']['href']))
            {!! $linkOf($m['switch'], $m['switch']['label']) !!}
        @else
            {!! $formOf($m['switch'], $m['switch']['label']) !!}
        @endif
    @endif

    @if(! empty($m['check']) || ! empty($m['link']) || ! empty($m['unlink']) || ! empty($m['delete']))
        <div class="x-menu__sep" role="separator"></div>
    @endif
    @if(! empty($m['check']))
        {!! $formOf($m['check'], $m['check']['label'] ?? 'Check against ' . $storeWord) !!}
    @endif
    @if(! empty($m['link']))
        {!! $formOf($m['link'], $m['link']['label'] ?? 'Link ID') !!}
    @endif
    @if(! empty($m['unlink']))
        {!! $formOf($m['unlink'] + ['verb' => $m['unlink']['verb'] ?? 'Unlink'], 'Unlink ID', 'x-menu__item x-menu__item--danger') !!}
    @endif
    @if(! empty($m['delete']))
        {!! $formOf($m['delete'] + ['verb' => $m['delete']['verb'] ?? 'Delete from ' . $storeWord], $m['delete']['label'] ?? 'Delete from ' . $storeWord, 'x-menu__item x-menu__item--danger') !!}
    @endif

    @if(! empty($m['remove']))
        <div class="x-menu__sep" role="separator"></div>
        {!! $formOf($m['remove'] + ['verb' => $m['remove']['verb'] ?? ($m['remove']['label'] ?? 'Remove')], $m['remove']['label'] ?? 'Remove from this channel', 'x-menu__item x-menu__item--danger') !!}
    @endif
</x-ui.menu>
