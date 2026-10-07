@extends('layouts.blotter')
@section('title', 'UI Kit')
@section('breadcrumb', 'UI Kit')

@section('content')
<h1 class="x-page-title">UI Kit</h1>
<p class="x-page-sub">Every primitive. Toggle the theme in the top bar to check both.</p>

<section class="x-hub__group">
    <h2 class="x-hub__group-title">Buttons</h2>
    <div class="x-kit-row">
        @foreach(['primary', 'secondary', 'ghost', 'danger'] as $variant)
            @foreach(['md', 'sm'] as $size)
                <x-ui.button :variant="$variant" :size="$size">
                    {{ ucfirst($variant) }}{{ $size === 'sm' ? ' (sm)' : '' }}
                </x-ui.button>
            @endforeach
        @endforeach
        <x-ui.button variant="secondary" disabled>Disabled</x-ui.button>
        <x-ui.button variant="primary" href="{{ route('ui_kit_v2') }}">Link button</x-ui.button>
    </div>
</section>

<section class="x-hub__group">
    <h2 class="x-hub__group-title">Badges</h2>
    <p class="x-page-sub">Status tones carry a dot, since status is what a long list gets scanned for. Channel tones drop the dot and let the brand-tinted text carry identity, so the two never compete for the same "coloured dot" reading in a table row (see Orders).</p>
    <div class="x-kit-row">
        @foreach(['neutral','success','warning','danger','info'] as $t)
            <x-ui.badge :tone="$t">{{ ucfirst($t) }}</x-ui.badge>
        @endforeach
    </div>
    <div class="x-kit-row">
        @foreach(['lazada','shopee','tiktok','ventacart','opencart','shopify','pedallion'] as $t)
            <x-ui.badge :tone="$t" :dot="false">{{ ucfirst($t) }}</x-ui.badge>
        @endforeach
    </div>
</section>

<section class="x-hub__group">
    <h2 class="x-hub__group-title">Input</h2>
    <div class="x-kit-row">
        <x-ui.input placeholder="Default state" aria-label="Default input" />
        <x-ui.input placeholder="Focused on load" aria-label="Focused input" autofocus />
    </div>
</section>

<section class="x-hub__group">
    <h2 class="x-hub__group-title">Table</h2>
    <x-ui.table>
        <x-slot:head><tr><th scope="col">Order</th><th scope="col">Customer</th><th scope="col" class="x-td-num">Total</th><th scope="col" class="x-td-actions"></th></tr></x-slot:head>
        @foreach([[44294,'Salfini','308071.4726'],[8827,'Sample Buyer','6602.0800'],[44293,'R. Sarmiento','718.0000']] as [$id,$name,$total])
        <tr>
            <td><span class="x-row-link x-num">{{ $id }}</span></td>
            <td class="x-cell-strong">{{ $name }}</td>
            <td class="x-td-num"><x-money :php="$total" /></td>
            <td class="x-td-actions">
                <x-ui.menu>
                    <a class="x-menu__item" href="#">View</a>
                    <div class="x-menu__sep"></div>
                    <button type="button" class="x-menu__item x-menu__item--danger">Delete</button>
                </x-ui.menu>
            </td>
        </tr>
        @endforeach
    </x-ui.table>
</section>

<section class="x-hub__group">
    <h2 class="x-hub__group-title">Empty state</h2>
    <x-ui.empty title="No results" description="Try a different search." />
</section>

<section class="x-hub__group">
    <h2 class="x-hub__group-title">Hub</h2>
    <x-ui.hub :groups="[
        'Workspace' => [
            ['label' => 'Dashboard', 'description' => 'Overview and KPIs', 'icon' => 'monitor', 'route' => 'dashboard'],
            ['label' => 'Orders', 'description' => 'Sales across all channels', 'icon' => 'receipt', 'route' => 'orders.index'],
            ['label' => 'Products', 'description' => 'Catalog and stock', 'icon' => 'package', 'route' => 'products.index'],
        ],
        'Configuration' => [
            ['label' => 'Settings', 'description' => 'Every configuration area', 'icon' => 'shield', 'route' => 'settings.hub'],
        ],
    ]" placeholder="Search the kit" />
</section>
@endsection
