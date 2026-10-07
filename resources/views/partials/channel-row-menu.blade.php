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
