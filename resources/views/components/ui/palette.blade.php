@php
    $entries = [];
    foreach (\App\Support\Navigation::groups() as $entry) {
        foreach ($entry['items'] ?? [$entry] as $item) {
            if (! isset($item['route']) && ! empty($item['children'])) {
                foreach ($item['children'] as $child) {
                    $entries[] = [
                        'label' => $entry['label'].' / '.$item['label'].' / '.$child['label'],
                        'url'   => route($child['route'], $child['params'] ?? []),
                        'hay'   => strtolower($entry['label'].' '.$item['label'].' '.$child['label'].' '.implode(' ', $child['keywords'] ?? [])),
                    ];
                }
                continue;
            }
            $entries[] = [
                'label' => ($entry['label'] !== $item['label'] ? $entry['label'].' / ' : '').$item['label'],
                'url'   => route($item['route'], $item['params'] ?? []),
                'hay'   => strtolower($entry['label'].' '.$item['label'].' '.implode(' ', $item['keywords'] ?? [])),
            ];
        }
    }
@endphp

<div class="x-palette" x-data="ventasyncPalette(@js($entries))" x-show="open" x-cloak
     @keydown.escape.window="close()"
     @keydown.window.prevent.cmd.k="toggle()"
     @keydown.window.prevent.ctrl.k="toggle()"
     @ventasync-open-palette.window="openPalette()">
    <div class="x-palette__scrim" @click="close()"></div>
    <div class="x-palette__panel" role="dialog" aria-modal="true" aria-label="Command palette">
        <div class="x-palette__search">
            <x-ui.icon name="search" size="14" />
            <input type="search" class="x-palette__input" x-ref="input" x-model="q"
                   placeholder="Search pages, orders and products" aria-label="Search"
                   autocomplete="off" spellcheck="false" role="combobox" aria-expanded="true"
                   @input="search()"
                   @keydown.down.prevent="move(1)"
                   @keydown.up.prevent="move(-1)"
                   @keydown.enter.prevent="go()">
        </div>
        <div class="x-palette__results" role="listbox" aria-label="Results">
            <template x-if="routes().length">
                <div class="x-palette__group">
                    <p class="x-palette__group-title">Pages</p>
                    <template x-for="(r, i) in routes()" :key="r.url">
                        <a :href="r.url" class="x-palette__item" role="option"
                           :aria-selected="idx === i ? 'true' : 'false'"
                           :class="idx === i && 'is-active'"
                           @mouseenter="idx = i" x-text="r.label"></a>
                    </template>
                </div>
            </template>

            <template x-if="records.orders.length">
                <div class="x-palette__group">
                    <p class="x-palette__group-title">Orders</p>
                    <template x-for="(o, i) in records.orders" :key="'order-' + o.id">
                        <a :href="o.url" class="x-palette__item x-palette__item--record" role="option"
                           :aria-selected="idx === routes().length + i ? 'true' : 'false'"
                           :class="idx === routes().length + i && 'is-active'"
                           @mouseenter="idx = routes().length + i">
                            <span class="x-palette__item-title" x-text="'#' + o.id + (o.firstname || o.lastname ? ' · ' + [o.firstname, o.lastname].filter(Boolean).join(' ') : '')"></span>
                            <span class="x-palette__item-meta" x-text="o.total_display"></span>
                        </a>
                    </template>
                </div>
            </template>

            <template x-if="records.products.length">
                <div class="x-palette__group">
                    <p class="x-palette__group-title">Products</p>
                    <template x-for="(p, i) in records.products" :key="'product-' + p.id">
                        <a :href="p.url" class="x-palette__item x-palette__item--record" role="option"
                           :aria-selected="idx === routes().length + records.orders.length + i ? 'true' : 'false'"
                           :class="idx === routes().length + records.orders.length + i && 'is-active'"
                           @mouseenter="idx = routes().length + records.orders.length + i">
                            <span class="x-palette__item-title" x-text="p.name"></span>
                            <span class="x-palette__item-meta" x-text="p.sku || ('ID ' + p.id)"></span>
                        </a>
                    </template>
                </div>
            </template>

            <template x-if="q.length >= 2 && !routes().length && !records.orders.length && !records.products.length">
                <p class="x-palette__empty">Nothing matches "<span x-text="q"></span>".</p>
            </template>
        </div>
        <div class="x-palette__hints">
            <span><kbd>&uarr;</kbd><kbd>&darr;</kbd> navigate</span>
            <span><kbd>&crarr;</kbd> open</span>
            <span><kbd>esc</kbd> close</span>
        </div>
    </div>
</div>
