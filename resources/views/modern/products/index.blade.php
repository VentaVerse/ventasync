@extends('layouts.modern.app')
@section('breadcrumb', 'Catalog / Products')

@section('content')
@php
    $currentSort = $sort ?? 'product_id';
    $currentDir  = $dir ?? 'asc';

    function modern_sort_url($field, $currentSort, $currentDir, $q, $status) {
        $nextDir = 'asc';
        if ($currentSort === $field) {
            $nextDir = $currentDir === 'asc' ? 'desc' : 'asc';
        }
        $params = ['sort' => $field, 'dir' => $nextDir];
        if ($q !== '')       $params['q']      = $q;
        if ($status !== '')  $params['status']  = $status;
        return route('products.index', $params);
    }

    function modern_sort_icon($field, $currentSort, $currentDir) {
        if ($currentSort !== $field) return '';
        return $currentDir === 'asc'
            ? '<svg class="inline w-3 h-3 ml-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7"/></svg>'
            : '<svg class="inline w-3 h-3 ml-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>';
    }
@endphp

{{-- Page header --}}
<div class="flex justify-between items-center mb-6">
    <h1 class="text-2xl font-bold text-gray-900">Products</h1>
    <a href="{{ route('products.create') }}"
       class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg font-medium transition inline-flex items-center gap-2">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
        </svg>
        Add Product
    </a>
</div>

{{-- Search / filter toolbar --}}
<form method="GET" action="{{ route('products.index') }}" class="flex flex-wrap gap-3 items-center bg-white rounded-xl shadow-sm border p-4 mb-6">
    <div class="relative flex-1 min-w-[200px]">
        <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
        </svg>
        <input type="text" name="q" value="{{ $q ?? '' }}" placeholder="Search by name..."
               class="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
    </div>
    <select name="status" class="border border-gray-300 rounded-lg text-sm px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
        <option value="">All Status</option>
        <option value="1" {{ ($status ?? '') === '1' ? 'selected' : '' }}>Enabled</option>
        <option value="0" {{ ($status ?? '') === '0' ? 'selected' : '' }}>Disabled</option>
    </select>
    <button type="submit"
            class="bg-gray-800 hover:bg-gray-900 text-white px-4 py-2 rounded-lg text-sm font-medium transition">
        Search
    </button>
    @if(($q ?? '') !== '' || ($status ?? '') !== '')
        <a href="{{ route('products.index') }}" class="text-sm text-gray-500 hover:text-gray-700 transition">Clear</a>
    @endif
</form>

{{-- Bulk actions bar --}}
<div class="flex items-center gap-3 mb-4" x-data="{ bulkOpen: false }">
    <div class="relative">
        <button @click="bulkOpen = !bulkOpen" type="button"
                class="inline-flex items-center gap-2 bg-white border border-gray-300 text-gray-700 px-4 py-2 rounded-lg text-sm font-medium hover:bg-gray-50 transition">
            Bulk Actions
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
            </svg>
        </button>
        <div x-show="bulkOpen" @click.away="bulkOpen = false"
             x-transition:enter="transition ease-out duration-100"
             x-transition:enter-start="opacity-0 scale-95"
             x-transition:enter-end="opacity-100 scale-100"
             x-transition:leave="transition ease-in duration-75"
             x-transition:leave-start="opacity-100 scale-100"
             x-transition:leave-end="opacity-0 scale-95"
             class="absolute left-0 mt-2 w-48 bg-white rounded-lg shadow-lg border z-20"
             style="display: none;">
            <button type="button" onclick="submitBulk('enable')"
                    class="w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 rounded-t-lg">
                <span class="inline-block w-2 h-2 rounded-full bg-green-500 mr-2"></span>Enable Selected
            </button>
            <button type="button" onclick="submitBulk('disable')"
                    class="w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">
                <span class="inline-block w-2 h-2 rounded-full bg-gray-400 mr-2"></span>Disable Selected
            </button>
            <hr class="border-gray-100">
            <button type="button" onclick="submitBulk('delete')"
                    class="w-full text-left px-4 py-2 text-sm text-red-600 hover:bg-red-50 rounded-b-lg">
                <svg class="inline w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                </svg>
                Delete Selected
            </button>
        </div>
    </div>
    <span class="text-sm text-gray-500" id="selected-count-label"></span>
</div>

{{-- Hidden bulk form --}}
<form id="product-bulk-form" method="POST" action="{{ route('products.bulk') }}">
    @csrf
    <input type="hidden" name="action" id="bulk-action-field" value="">
</form>

{{-- Products table --}}
<div x-data="{
        selectAll: false,
        selected: [],
        toggleAll() {
            const boxes = document.querySelectorAll('.product-row-check');
            boxes.forEach(b => {
                b.checked = this.selectAll;
                const id = parseInt(b.value);
                if (this.selectAll && !this.selected.includes(id)) {
                    this.selected.push(id);
                }
            });
            if (!this.selectAll) this.selected = [];
            this.updateLabel();
        },
        toggle(id) {
            const idx = this.selected.indexOf(id);
            if (idx > -1) this.selected.splice(idx, 1);
            else this.selected.push(id);
            const boxes = document.querySelectorAll('.product-row-check');
            this.selectAll = this.selected.length === boxes.length && boxes.length > 0;
            this.updateLabel();
        },
        updateLabel() {
            const el = document.getElementById('selected-count-label');
            if (el) el.textContent = this.selected.length > 0 ? this.selected.length + ' selected' : '';
        }
     }"
     class="bg-white rounded-xl shadow-sm border overflow-hidden">
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="w-10 px-4 py-3">
                        <input type="checkbox" x-model="selectAll" @change="toggleAll()"
                               class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                    </th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-20">
                        <a href="{{ modern_sort_url('product_id', $currentSort, $currentDir, $q ?? '', $status ?? '') }}"
                           class="hover:text-gray-700 transition">ID{!! modern_sort_icon('product_id', $currentSort, $currentDir) !!}</a>
                    </th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-16">
                        Image
                    </th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                        <a href="{{ modern_sort_url('name', $currentSort, $currentDir, $q ?? '', $status ?? '') }}"
                           class="hover:text-gray-700 transition">Name{!! modern_sort_icon('name', $currentSort, $currentDir) !!}</a>
                    </th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-32">
                        SKU
                    </th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-28">
                        <a href="{{ modern_sort_url('quantity', $currentSort, $currentDir, $q ?? '', $status ?? '') }}"
                           class="hover:text-gray-700 transition">Quantity{!! modern_sort_icon('quantity', $currentSort, $currentDir) !!}</a>
                    </th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-28">
                        <a href="{{ modern_sort_url('price', $currentSort, $currentDir, $q ?? '', $status ?? '') }}"
                           class="hover:text-gray-700 transition">Price{!! modern_sort_icon('price', $currentSort, $currentDir) !!}</a>
                    </th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-24">
                        <a href="{{ modern_sort_url('status', $currentSort, $currentDir, $q ?? '', $status ?? '') }}"
                           class="hover:text-gray-700 transition">Status{!! modern_sort_icon('status', $currentSort, $currentDir) !!}</a>
                    </th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider w-44">
                        Actions
                    </th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @forelse($products as $p)
                    @php
                        $img = trim((string)($p->image ?? ''));
                        $src = $img !== '' ? asset('storage/' . ltrim($img, '/')) : '';
                        $optRows = is_array($p->option_rows ?? null) ? $p->option_rows : (is_iterable($p->option_rows ?? null) ? $p->option_rows : []);
                    @endphp
                    <tr class="hover:bg-gray-50 transition">
                        <td class="px-4 py-3">
                            <input type="checkbox"
                                   class="product-row-check rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                                   name="ids[]" value="{{ (int)$p->product_id }}" form="product-bulk-form"
                                   @change="toggle({{ (int)$p->product_id }})">
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-600 font-mono">{{ $p->product_id }}</td>
                        <td class="px-4 py-3">
                            @if($src)
                                <img src="{{ $src }}" alt="" class="w-10 h-10 rounded-lg object-cover" loading="lazy" decoding="async"
                                     onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                <div class="w-10 h-10 rounded-lg bg-gray-100 items-center justify-center text-gray-400" style="display:none;">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                    </svg>
                                </div>
                            @else
                                <div class="w-10 h-10 rounded-lg bg-gray-100 flex items-center justify-center text-gray-400">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                    </svg>
                                </div>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            <div class="text-sm font-semibold text-gray-900 truncate max-w-xs">{{ $p->name ?? '-' }}</div>
                        </td>
                        <td class="px-4 py-3 text-xs text-gray-500 font-mono">{{ $p->sku ?: "\u{2014}" }}</td>
                        <td class="px-4 py-3 text-sm text-gray-700">{{ (int)$p->quantity }}</td>
                        <td class="px-4 py-3 text-sm text-gray-700">{{ number_format((float)$p->price, 2) }}</td>
                        <td class="px-4 py-3">
                            @if((int)$p->status === 1)
                                <span class="inline-flex items-center rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-medium text-green-800">Enabled</span>
                            @else
                                <span class="inline-flex items-center rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-600">Disabled</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right">
                            <div class="flex items-center justify-end gap-1">
                                <a href="{{ route('products.sales', $p->product_id) }}?back={{ urlencode(request()->fullUrl()) }}"
                                   class="inline-flex items-center px-2 py-1 text-xs font-medium text-gray-600 bg-gray-100 rounded hover:bg-gray-200 transition"
                                   title="Sales">Sales</a>
                                <a href="{{ route('products.stock_history', $p->product_id) }}?back={{ urlencode(request()->fullUrl()) }}"
                                   class="inline-flex items-center px-2 py-1 text-xs font-medium text-gray-600 bg-gray-100 rounded hover:bg-gray-200 transition"
                                   title="Stock History">Stock</a>
                                <a href="{{ route('products.edit', $p->product_id) }}"
                                   class="inline-flex items-center px-2 py-1 text-xs font-medium text-blue-700 bg-blue-50 rounded hover:bg-blue-100 transition"
                                   title="Edit">
                                    <svg class="w-3.5 h-3.5 mr-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                                    </svg>
                                    Edit
                                </a>
                                <form method="POST" action="{{ route('products.destroy', $p->product_id) }}" class="inline"
                                      onsubmit="return confirm('Delete this product?')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit"
                                            class="inline-flex items-center px-2 py-1 text-xs font-medium text-red-600 bg-red-50 rounded hover:bg-red-100 transition"
                                            title="Delete">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                        </svg>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>

                    {{-- Option sub-rows (combinations) --}}
                    @if(!empty($optRows))
                        @foreach($optRows as $or)
                            @php
                                $ovImg = trim((string)($or->option_image ?? ''));
                                $ovSrc = $ovImg !== '' ? asset('storage/' . ltrim($ovImg, '/')) : '';
                                $final = (float)($or->absolute_price ?? 0);
                            @endphp
                            <tr class="bg-gray-50 border-l-4 border-blue-200">
                                <td class="px-4 py-2"></td>
                                <td class="px-4 py-2"></td>
                                <td class="px-4 py-2">
                                    @if($ovSrc)
                                        <img src="{{ $ovSrc }}" alt="" class="w-8 h-8 rounded object-cover" loading="lazy" decoding="async"
                                             onerror="this.style.display='none';">
                                    @else
                                        <span class="text-gray-300">&mdash;</span>
                                    @endif
                                </td>
                                <td class="pl-16 pr-4 py-2">
                                    <div class="text-sm font-medium text-gray-800">{{ $or->option_value_name ?? '' }}</div>
                                    <div class="text-xs text-gray-400 mt-0.5">{{ $or->option_name ?? '' }}</div>
                                </td>
                                <td class="px-4 py-2 text-xs text-gray-500 font-mono">{{ $or->sku ?? '' }}</td>
                                <td class="px-4 py-2 text-sm font-medium text-gray-700">{{ (int)($or->quantity ?? 0) }}</td>
                                <td class="px-4 py-2 text-sm text-gray-700">{{ number_format($final, 2) }}</td>
                                <td class="px-4 py-2"></td>
                                <td class="px-4 py-2"></td>
                            </tr>
                        @endforeach
                    @endif
                @empty
                    <tr>
                        <td colspan="9" class="px-4 py-12 text-center">
                            <div class="flex flex-col items-center text-gray-400">
                                <svg class="w-12 h-12 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                                </svg>
                                <p class="text-sm font-medium">No products found</p>
                                <p class="text-xs mt-1">Try adjusting your search or filter criteria.</p>
                            </div>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

{{-- Pagination --}}
@if($products->hasPages())
    <div class="mt-6">
        {{ $products->onEachSide(1)->links() }}
    </div>
@endif

{{-- Bulk action submission script --}}
<script>
function submitBulk(action) {
    var checks = document.querySelectorAll('.product-row-check:checked');
    var count = checks.length;

    if (count === 0) {
        alert('Please select at least one product.');
        return;
    }
    if (action === 'delete' && !confirm('Delete ' + count + ' selected product(s)? This cannot be undone.')) {
        return;
    }

    document.getElementById('bulk-action-field').value = action;
    document.getElementById('product-bulk-form').submit();
}
</script>
@endsection
