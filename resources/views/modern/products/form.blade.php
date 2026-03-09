@php
    $isEdit = isset($product);
    if (!$isEdit) {
        $pimToken = $pimToken ?? (string) \Illuminate\Support\Str::uuid();
    }
@endphp
@extends('layouts.modern.app')
@section('breadcrumb', $isEdit ? 'Catalog / Products / Edit' : 'Catalog / Products / Add')

@section('content')

{{-- Page header --}}
<div class="flex justify-between items-center mb-6">
    <h1 class="text-2xl font-bold text-gray-900">
        @if($isEdit)
            Edit Product: {{ $product->name }}
        @else
            Add Product
        @endif
    </h1>
    <a href="{{ $isEdit ? ($returnUrl ?? route('products.index')) : route('products.index') }}"
       class="inline-flex items-center gap-2 bg-white border border-gray-300 text-gray-700 px-4 py-2 rounded-lg font-medium hover:bg-gray-50 transition text-sm">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
        </svg>
        Back to Products
    </a>
</div>

{{-- Validation errors --}}
@if ($errors->any())
    <div class="bg-red-50 border border-red-200 text-red-700 rounded-lg px-4 py-3 mb-6 text-sm">
        <div class="flex items-center gap-2">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <span>{{ $errors->first() }}</span>
        </div>
    </div>
@endif

{{-- Session messages --}}
@if(session('status'))
    <div class="bg-green-50 border border-green-200 text-green-700 rounded-lg px-4 py-3 mb-6 text-sm">{{ session('status') }}</div>
@endif
@if(session('error'))
    <div class="bg-red-50 border border-red-200 text-red-700 rounded-lg px-4 py-3 mb-6 text-sm">{{ session('error') }}</div>
@endif

{{-- Main form --}}
<form method="POST"
      action="{{ $isEdit ? route('products.update', $product->product_id) : route('products.store') }}"
      id="product-form">
    @csrf
    @if($isEdit)
        @method('PUT')
        <input type="hidden" name="_return" value="{{ $returnUrl ?? route('products.index') }}">
    @endif

    {{-- Tabbed interface --}}
    <div x-data="{ tab: 'general' }">
        {{-- Tab navigation --}}
        <div class="flex border-b border-gray-200 mb-6">
            <button type="button" @click="tab = 'general'"
                    :class="tab === 'general' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                    class="px-4 py-3 border-b-2 font-medium text-sm transition whitespace-nowrap">
                General
            </button>
            <button type="button" @click="tab = 'options'"
                    :class="tab === 'options' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                    class="px-4 py-3 border-b-2 font-medium text-sm transition whitespace-nowrap">
                Options
            </button>
            <button type="button" @click="tab = 'vendors'"
                    :class="tab === 'vendors' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                    class="px-4 py-3 border-b-2 font-medium text-sm transition whitespace-nowrap">
                Vendors
            </button>
        </div>

        {{-- ===== GENERAL TAB ===== --}}
        <div x-show="tab === 'general'" x-cloak>
            <div class="bg-white rounded-xl shadow-sm border p-6 mb-6">

                {{-- Manufacturer typeahead --}}
                <div class="mb-6 relative" id="manufacturer-typeahead-wrap">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Manufacturer</label>
                    <input type="text"
                           class="w-full rounded-lg border-gray-300 focus:border-blue-500 focus:ring-blue-500 text-sm"
                           id="manufacturer_search"
                           placeholder="Type to search..."
                           autocomplete="off"
                           value="{{ old('manufacturer_name', $isEdit ? ($manufacturerName ?? '') : '') }}"
                           name="manufacturer_name">
                    <input type="hidden" name="manufacturer_id" id="manufacturer_id"
                           value="{{ old('manufacturer_id', $isEdit ? $product->manufacturer_id : 0) }}">
                    <div class="typeahead-list absolute z-50 w-full bg-white border border-gray-200 rounded-lg shadow-lg mt-1 max-h-60 overflow-y-auto hidden" id="manufacturer_list"></div>
                    <p class="text-xs text-gray-400 mt-1">Start typing, then click a result. Required before uploading images.</p>
                    @error('manufacturer_name')
                        <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Image manager --}}
                @include('products.partials.image_manager', [
                    'pimToken' => $isEdit ? '' : $pimToken,
                    'pimProductId' => $isEdit ? (int) $product->product_id : 0
                ])
            </div>

            {{-- General fields --}}
            <div class="bg-white rounded-xl shadow-sm border p-6 mb-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-4">General</h3>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    {{-- Name (full width) --}}
                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            Name <span class="text-red-500">*</span>
                        </label>
                        <div class="flex">
                            <span class="hidden items-center px-3 bg-gray-100 border border-r-0 border-gray-300 rounded-l-lg text-sm text-gray-500"
                                  id="name_mfg_prefix"></span>
                            <input type="text"
                                   class="flex-1 rounded-lg border-gray-300 focus:border-blue-500 focus:ring-blue-500 text-sm"
                                   name="name" id="product_name"
                                   value="{{ old('name', $isEdit ? html_entity_decode($product->name ?? '') : '') }}"
                                   required>
                        </div>
                        @error('name')
                            <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                        @enderror
                    </div>

                    {{-- Model --}}
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            Model <span class="text-red-500">*</span>
                        </label>
                        <input type="text"
                               class="w-full rounded-lg border-gray-300 focus:border-blue-500 focus:ring-blue-500 text-sm"
                               name="model"
                               value="{{ old('model', $isEdit ? $product->model : '') }}"
                               required>
                        @error('model')
                            <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                        @enderror
                    </div>

                    {{-- SKU --}}
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            SKU <span class="text-red-500">*</span>
                        </label>
                        <input type="text"
                               class="w-full rounded-lg border-gray-300 focus:border-blue-500 focus:ring-blue-500 text-sm"
                               name="sku"
                               value="{{ old('sku', $isEdit ? $product->sku : '') }}"
                               required>
                        @error('sku')
                            <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                        @enderror
                    </div>

                    {{-- Quantity --}}
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Quantity</label>
                        <input type="text"
                               class="w-full rounded-lg border-gray-300 focus:border-blue-500 focus:ring-blue-500 text-sm"
                               id="product_quantity" name="quantity"
                               value="{{ old('quantity', $isEdit ? $product->quantity : '0') }}"
                               required>
                        @error('quantity')
                            <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                        @enderror
                        <p class="text-xs text-gray-400 mt-1 hidden" id="qty_hint">Quantity is auto-calculated from option quantities.</p>
                    </div>

                    {{-- Status --}}
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            Status <span class="text-red-500">*</span>
                        </label>
                        <select class="w-full rounded-lg border-gray-300 focus:border-blue-500 focus:ring-blue-500 text-sm"
                                name="status" required>
                            <option value="1" {{ old('status', $isEdit ? (string)$product->status : '1') == '1' ? 'selected' : '' }}>Enabled</option>
                            <option value="0" {{ old('status', $isEdit ? (string)$product->status : '1') == '0' ? 'selected' : '' }}>Disabled</option>
                        </select>
                        @error('status')
                            <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                        @enderror
                    </div>

                    {{-- Categories (full width) --}}
                    <div class="md:col-span-2 relative" id="category-typeahead-wrap">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Categories</label>
                        <div id="category_tags" class="flex flex-wrap gap-2 mb-2">
                            @php
                                $catIds = old('category_ids', $isEdit ? collect($currentCategories ?? [])->pluck('id')->all() : []);
                            @endphp
                            @foreach($catIds as $catId)
                                @php
                                    $catName = $isEdit
                                        ? (collect($currentCategories ?? [])->firstWhere('id', $catId)['name'] ?? 'Category #'.$catId)
                                        : 'Category #'.$catId;
                                @endphp
                                <span class="inline-flex items-center gap-1 px-3 py-1 rounded-full bg-blue-100 text-blue-800 text-sm tag-chip" data-id="{{ $catId }}">
                                    {{ $catName }}
                                    <input type="hidden" name="category_ids[]" value="{{ $catId }}">
                                    <button type="button" class="hover:text-blue-600" onclick="this.parentElement.remove(); window.updateCatPlaceholder();">&times;</button>
                                </span>
                            @endforeach
                        </div>
                        <input type="text"
                               class="w-full rounded-lg border-gray-300 focus:border-blue-500 focus:ring-blue-500 text-sm"
                               id="category_search"
                               placeholder="Type to search categories..."
                               autocomplete="off">
                        <div class="typeahead-list absolute z-50 w-full bg-white border border-gray-200 rounded-lg shadow-lg mt-1 max-h-60 overflow-y-auto hidden" id="category_list"></div>
                        <p class="text-xs text-gray-400 mt-1">Search and select multiple categories.</p>
                    </div>

                    {{-- Description (full width) --}}
                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                        <textarea class="w-full rounded-lg border-gray-300 focus:border-blue-500 focus:ring-blue-500 text-sm wysiwyg"
                                  name="description">{!! old('description', $isEdit ? $product->description : '') !!}</textarea>
                        @error('description')
                            <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                        @enderror
                    </div>
                </div>
            </div>

            {{-- Pricing & Cost --}}
            <div class="bg-white rounded-xl shadow-sm border p-6 mb-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-4">Pricing & Cost</h3>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Selling Price</label>
                        <input type="text"
                               class="w-full rounded-lg border-gray-300 focus:border-blue-500 focus:ring-blue-500 text-sm"
                               name="price" id="product_price"
                               value="{{ old('price', $isEdit ? $product->price : '0') }}"
                               required>
                        @error('price')
                            <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                {{-- Cost breakdown --}}
                <div class="border border-gray-200 rounded-lg p-4">
                    <div class="flex items-center gap-3 mb-4">
                        <span class="text-sm font-semibold text-gray-700">Cost Breakdown</span>
                        @if($isEdit)
                            <span id="cost-lock-badge"
                                  class="text-xs px-2 py-0.5 rounded bg-gray-100 text-gray-500">
                                Locked -- updated on PO receive
                            </span>
                            <button type="button" id="cost-override-btn"
                                    class="text-xs px-2 py-0.5 rounded border border-gray-300 text-gray-600 hover:bg-gray-50 transition">
                                Override
                            </button>
                        @endif
                    </div>

                    <div class="grid grid-cols-2 md:grid-cols-5 gap-4 items-end">
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Cost</label>
                            <input type="text"
                                   class="w-full rounded-lg border-gray-300 focus:border-blue-500 focus:ring-blue-500 text-sm {{ $isEdit ? 'bg-gray-50' : '' }}"
                                   id="cost_amount" name="cost_amount"
                                   value="{{ old('cost_amount', $isEdit ? ($product->cost_amount ?? 0) : '0') }}"
                                   @if($isEdit) readonly tabindex="-1" @endif>
                            <p class="text-xs text-gray-400 mt-1">Fixed amount</p>
                        </div>

                        <div class="hidden md:flex items-center justify-center text-gray-400 text-sm pb-6">and/or</div>

                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Cost [%]</label>
                            <input type="text"
                                   class="w-full rounded-lg border-gray-300 focus:border-blue-500 focus:ring-blue-500 text-sm {{ $isEdit ? 'bg-gray-50' : '' }}"
                                   id="cost_percentage" name="cost_percentage"
                                   value="{{ old('cost_percentage', $isEdit ? ($product->cost_percentage ?? 0) : '0') }}"
                                   @if($isEdit) readonly tabindex="-1" @endif>
                            <p class="text-xs text-gray-400 mt-1">% of selling price</p>
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Additional Cost</label>
                            <input type="text"
                                   class="w-full rounded-lg border-gray-300 focus:border-blue-500 focus:ring-blue-500 text-sm {{ $isEdit ? 'bg-gray-50' : '' }}"
                                   id="cost_additional" name="cost_additional"
                                   value="{{ old('cost_additional', $isEdit ? ($product->cost_additional ?? 0) : '0') }}"
                                   @if($isEdit) readonly tabindex="-1" @endif>
                            <p class="text-xs text-gray-400 mt-1">e.g. supplier shipping</p>
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Product Cost</label>
                            <input type="text"
                                   class="w-full rounded-lg border-gray-300 bg-gray-50 focus:border-blue-500 focus:ring-blue-500 text-sm font-semibold"
                                   id="product_cost" name="cost"
                                   value="{{ old('cost', $isEdit ? ($product->cost ?? 0) : '0') }}"
                                   readonly tabindex="-1">
                            <p class="text-xs text-gray-400 mt-1">Computed total</p>
                        </div>
                    </div>

                    {{-- Profit metrics --}}
                    <div class="grid grid-cols-3 gap-4 mt-4 pt-4 border-t border-gray-100">
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Profit</label>
                            <input type="text"
                                   class="w-full rounded-lg border-gray-300 bg-gray-50 focus:border-blue-500 focus:ring-blue-500 text-sm font-semibold"
                                   id="calc_profit" readonly tabindex="-1">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Margin [%]</label>
                            <input type="text"
                                   class="w-full rounded-lg border-gray-300 bg-gray-50 focus:border-blue-500 focus:ring-blue-500 text-sm font-semibold"
                                   id="calc_margin" readonly tabindex="-1">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Markup [%]</label>
                            <input type="text"
                                   class="w-full rounded-lg border-gray-300 bg-gray-50 focus:border-blue-500 focus:ring-blue-500 text-sm font-semibold"
                                   id="calc_markup" readonly tabindex="-1">
                        </div>
                    </div>
                </div>
            </div>

            {{-- Shipping & Dimensions --}}
            <div class="bg-white rounded-xl shadow-sm border p-6 mb-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-4">Shipping & Dimensions</h3>

                <div class="grid grid-cols-2 md:grid-cols-4 gap-6">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Weight</label>
                        <input type="text"
                               class="w-full rounded-lg border-gray-300 focus:border-blue-500 focus:ring-blue-500 text-sm"
                               name="weight"
                               value="{{ old('weight', $isEdit ? ($product->weight ?? '0') : '0') }}"
                               placeholder="e.g. 0.5">
                        @error('weight')
                            <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Length</label>
                        <input type="text"
                               class="w-full rounded-lg border-gray-300 focus:border-blue-500 focus:ring-blue-500 text-sm"
                               name="length"
                               value="{{ old('length', $isEdit ? ($product->length ?? '0') : '0') }}"
                               placeholder="e.g. 10">
                        @error('length')
                            <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Width</label>
                        <input type="text"
                               class="w-full rounded-lg border-gray-300 focus:border-blue-500 focus:ring-blue-500 text-sm"
                               name="width"
                               value="{{ old('width', $isEdit ? ($product->width ?? '0') : '0') }}"
                               placeholder="e.g. 5">
                        @error('width')
                            <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Height</label>
                        <input type="text"
                               class="w-full rounded-lg border-gray-300 focus:border-blue-500 focus:ring-blue-500 text-sm"
                               name="height"
                               value="{{ old('height', $isEdit ? ($product->height ?? '0') : '0') }}"
                               placeholder="e.g. 3">
                        @error('height')
                            <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                        @enderror
                    </div>
                </div>
            </div>
        </div>

        {{-- ===== OPTIONS TAB ===== --}}
        <div x-show="tab === 'options'" x-cloak>
            <div class="bg-white rounded-xl shadow-sm border p-6">

                <div id="options-root">
                    {{-- Option 1 --}}
                    <div id="option1-group" class="hidden">
                        <div class="border border-gray-200 rounded-lg p-4 mb-4">
                            <div class="flex items-center gap-4 flex-wrap">
                                <div class="flex-1 min-w-[200px]">
                                    <label class="block text-sm font-medium text-gray-700 mb-1">
                                        Option 1 Name <span class="text-red-500">*</span>
                                    </label>
                                    <input type="text"
                                           class="w-full rounded-lg border-gray-300 focus:border-blue-500 focus:ring-blue-500 text-sm"
                                           id="opt1-name-input"
                                           placeholder="e.g. Cable Length, Color"
                                           autocomplete="off">
                                </div>
                                <div class="pt-5">
                                    <button type="button"
                                            class="bg-red-50 text-red-600 hover:bg-red-100 px-3 py-2 rounded-lg text-sm font-medium transition"
                                            id="remove-opt1-btn">
                                        Remove Option
                                    </button>
                                </div>
                            </div>

                            {{-- Option 1 values (visible in 2-option mode only) --}}
                            <div class="mt-4 hidden" id="opt1-values-section">
                                <label class="block text-sm font-medium text-gray-700 mb-2">Option 1 Values</label>
                                <div id="opt1-value-list" class="space-y-2"></div>
                                <div class="flex gap-2 mt-3">
                                    <input type="text"
                                           class="flex-1 rounded-lg border-gray-300 focus:border-blue-500 focus:ring-blue-500 text-sm"
                                           id="opt1-new-value"
                                           placeholder="Type value name, press Enter"
                                           autocomplete="off">
                                    <button type="button"
                                            class="bg-white border border-gray-300 text-gray-700 px-3 py-2 rounded-lg text-sm font-medium hover:bg-gray-50 transition"
                                            id="opt1-add-value-btn">
                                        Add
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- Option 2 (2-option mode only) --}}
                    <div id="option2-group" class="hidden">
                        <div class="border border-gray-200 rounded-lg p-4 mb-4">
                            <div class="flex items-center gap-4 flex-wrap">
                                <div class="flex-1 min-w-[200px]">
                                    <label class="block text-sm font-medium text-gray-700 mb-1">
                                        Option 2 Name <span class="text-red-500">*</span>
                                    </label>
                                    <input type="text"
                                           class="w-full rounded-lg border-gray-300 focus:border-blue-500 focus:ring-blue-500 text-sm"
                                           id="opt2-name-input"
                                           placeholder="e.g. Size, Plug Type"
                                           autocomplete="off">
                                </div>
                                <div class="pt-5">
                                    <button type="button"
                                            class="bg-red-50 text-red-600 hover:bg-red-100 px-3 py-2 rounded-lg text-sm font-medium transition"
                                            id="remove-opt2-btn">
                                        Remove Option 2
                                    </button>
                                </div>
                            </div>

                            <div class="mt-4">
                                <label class="block text-sm font-medium text-gray-700 mb-2">Option 2 Values</label>
                                <div id="opt2-value-list" class="space-y-2"></div>
                                <div class="flex gap-2 mt-3">
                                    <input type="text"
                                           class="flex-1 rounded-lg border-gray-300 focus:border-blue-500 focus:ring-blue-500 text-sm"
                                           id="opt2-new-value"
                                           placeholder="Type value name, press Enter"
                                           autocomplete="off">
                                    <button type="button"
                                            class="bg-white border border-gray-300 text-gray-700 px-3 py-2 rounded-lg text-sm font-medium hover:bg-gray-50 transition"
                                            id="opt2-add-value-btn">
                                        Add
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- Add Option 2 button --}}
                    <div id="add-opt2-wrapper" class="hidden mb-4">
                        <button type="button"
                                class="bg-white border border-gray-300 text-gray-700 px-4 py-2 rounded-lg text-sm font-medium hover:bg-gray-50 transition"
                                id="add-opt2-btn">
                            Add Option 2
                        </button>
                    </div>

                    {{-- Values / Combination table --}}
                    <div id="data-table-wrap" class="hidden mt-4">
                        <div class="overflow-x-auto rounded-lg border border-gray-200">
                            <table class="min-w-full divide-y divide-gray-200" id="data-table">
                                <thead class="bg-gray-50" id="data-thead"></thead>
                                <tbody class="bg-white divide-y divide-gray-200" id="data-tbody"></tbody>
                            </table>
                        </div>
                        <div class="mt-3">
                            <button type="button"
                                    class="bg-white border border-gray-300 text-gray-700 px-3 py-1.5 rounded-lg text-sm font-medium hover:bg-gray-50 transition"
                                    id="add-row-btn">
                                + Add Value
                            </button>
                        </div>
                    </div>
                </div>

                <div id="add-option-wrapper" class="mt-4">
                    <button type="button"
                            class="bg-white border border-gray-300 text-gray-700 px-4 py-2 rounded-lg text-sm font-medium hover:bg-gray-50 transition inline-flex items-center gap-2"
                            id="add-option-btn">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                        </svg>
                        Add Option
                    </button>
                </div>

            </div>
        </div>

        {{-- ===== VENDORS TAB ===== --}}
        <div x-show="tab === 'vendors'" x-cloak>
            <div class="bg-white rounded-xl shadow-sm border p-6">

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Reorder Level</label>
                        <input type="number"
                               class="w-full rounded-lg border-gray-300 focus:border-blue-500 focus:ring-blue-500 text-sm"
                               name="reorder_level"
                               value="{{ old('reorder_level', $isEdit ? ($product->reorder_level ?? 0) : 0) }}"
                               min="0">
                        <p class="text-xs text-gray-400 mt-1">Alert when stock falls to this level (0 = disabled)</p>
                    </div>
                </div>

                @if($isEdit)
                    {{-- Buying Prices section --}}
                    <h3 class="text-lg font-semibold text-gray-900 mb-4 mt-6 pt-6 border-t border-gray-100">Buying Prices</h3>
                    <div id="buying-prices-section">

                        {{-- Existing vendor pricing --}}
                        <div class="overflow-x-auto rounded-lg border border-gray-200">
                            <table class="min-w-full divide-y divide-gray-200 text-sm" id="buying-prices-table">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Vendor</th>
                                        <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Option</th>
                                        <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase" style="width:120px;">Vendor SKU</th>
                                        <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase" style="width:70px;">Min Qty</th>
                                        <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase" style="width:85px;">Currency</th>
                                        <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase" style="width:110px;">Unit Price</th>
                                        <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase" style="width:130px;"></th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-100">
                                    @foreach($productVendors as $pv)
                                        <tr data-pv-id="{{ $pv->id }}" data-vendor-id="{{ $pv->vendor_id }}" data-pov-id="{{ $pv->product_option_value_id ?? '' }}">
                                            <td class="px-3 py-2 text-sm text-gray-700">{{ $pv->vendor->name ?? "\u{2014}" }}</td>
                                            <td class="px-3 py-2 text-sm text-gray-500 pv-option-label" data-pov-id="{{ $pv->product_option_value_id }}"></td>
                                            <td class="px-3 py-2">
                                                <input type="text"
                                                       class="w-full rounded border-gray-300 focus:border-blue-500 focus:ring-blue-500 text-xs pv-edit-sku"
                                                       value="{{ $pv->vendor_sku ?? '' }}">
                                            </td>
                                            <td class="px-3 py-2">
                                                <input type="number"
                                                       class="w-16 rounded border-gray-300 focus:border-blue-500 focus:ring-blue-500 text-xs pv-edit-minqty"
                                                       min="1" value="{{ $pv->min_qty }}">
                                            </td>
                                            <td class="px-3 py-2">
                                                <select class="w-20 rounded border-gray-300 focus:border-blue-500 focus:ring-blue-500 text-xs pv-edit-currency">
                                                    @foreach($currencies as $c)
                                                        <option value="{{ $c->code }}" {{ $c->code === $pv->currency_code ? 'selected' : '' }}>{{ $c->code }}</option>
                                                    @endforeach
                                                </select>
                                            </td>
                                            <td class="px-3 py-2">
                                                <input type="number"
                                                       class="w-24 rounded border-gray-300 focus:border-blue-500 focus:ring-blue-500 text-xs text-right pv-edit-price"
                                                       step="0.0001" min="0" value="{{ $pv->unit_price }}">
                                            </td>
                                            <td class="px-3 py-2 text-right whitespace-nowrap">
                                                <button type="button"
                                                        class="hidden text-xs px-2 py-1 rounded bg-blue-50 text-blue-700 hover:bg-blue-100 transition pv-save-row-btn"
                                                        data-id="{{ $pv->id }}">Save</button>
                                                <button type="button"
                                                        class="text-xs px-2 py-1 rounded bg-red-50 text-red-600 hover:bg-red-100 transition pv-remove-btn"
                                                        data-id="{{ $pv->id }}">Remove</button>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        <div id="pv-empty-msg" class="text-sm text-gray-400 mt-4 hidden">No vendor pricing yet.</div>

                        {{-- Add new vendor pricing --}}
                        <div id="pv-add-section" class="mt-6 pt-6 border-t border-gray-100">
                            <h4 class="text-sm font-semibold text-gray-700 mb-3">Add New</h4>

                            {{-- Simple mode: product without options --}}
                            <div id="pv-simple-fields">
                                <div class="flex gap-3 flex-wrap items-end">
                                    <div class="relative">
                                        <label class="block text-xs font-medium text-gray-600 mb-1">Vendor</label>
                                        <input type="text"
                                               class="w-48 rounded-lg border-gray-300 focus:border-blue-500 focus:ring-blue-500 text-sm"
                                               id="pv-vendor-search"
                                               placeholder="Type to search..."
                                               autocomplete="off">
                                        <input type="hidden" id="pv-vendor-id">
                                        <div class="typeahead-list absolute z-50 w-56 bg-white border border-gray-200 rounded-lg shadow-lg mt-1 max-h-48 overflow-y-auto hidden" id="pv-vendor-list"></div>
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-gray-600 mb-1">Vendor SKU</label>
                                        <input type="text"
                                               class="w-32 rounded-lg border-gray-300 focus:border-blue-500 focus:ring-blue-500 text-sm"
                                               id="pv-vendor-sku">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-gray-600 mb-1">Min Qty</label>
                                        <input type="number"
                                               class="w-20 rounded-lg border-gray-300 focus:border-blue-500 focus:ring-blue-500 text-sm"
                                               id="pv-min-qty" min="1" value="1">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-gray-600 mb-1">Currency</label>
                                        <select class="w-24 rounded-lg border-gray-300 focus:border-blue-500 focus:ring-blue-500 text-sm" id="pv-currency">
                                            @foreach($currencies as $c)
                                                <option value="{{ $c->code }}">{{ $c->code }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-gray-600 mb-1">Unit Price</label>
                                        <input type="number"
                                               class="w-28 rounded-lg border-gray-300 focus:border-blue-500 focus:ring-blue-500 text-sm"
                                               id="pv-unit-price" step="0.0001" min="0">
                                    </div>
                                    <button type="button"
                                            class="bg-white border border-gray-300 text-gray-700 px-4 py-2 rounded-lg text-sm font-medium hover:bg-gray-50 transition"
                                            id="pv-add-btn">
                                        Add
                                    </button>
                                </div>
                            </div>

                            {{-- Options mode: per-option-value vendor pricing --}}
                            <div id="pv-option-fields" class="hidden">
                                <div class="flex items-center justify-between mb-3">
                                    <span class="text-xs text-gray-500" id="pv-add-hint">Fill in vendor details for each option below.</span>
                                    <button type="button"
                                            class="text-xs px-3 py-1 rounded border border-gray-300 text-gray-600 hover:bg-gray-50 transition"
                                            id="pv-apply-all-btn"
                                            title="Copy vendor, currency, and min qty from the first row to all other rows">
                                        Copy first row &darr;
                                    </button>
                                </div>
                                <div class="overflow-x-auto rounded-lg border border-gray-200">
                                    <table class="min-w-full divide-y divide-gray-200 text-sm" id="pv-option-table">
                                        <thead class="bg-gray-50">
                                            <tr>
                                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Option</th>
                                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase" style="width:160px;">Vendor</th>
                                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase" style="width:80px;">Currency</th>
                                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase" style="width:70px;">Min Qty</th>
                                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase" style="width:130px;">Vendor SKU</th>
                                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase" style="width:110px;">Unit Price</th>
                                            </tr>
                                        </thead>
                                        <tbody class="bg-white divide-y divide-gray-100" id="pv-option-tbody"></tbody>
                                    </table>
                                </div>
                                <div class="mt-3 flex gap-3 items-center justify-end">
                                    <span id="pv-bulk-status" class="text-xs hidden"></span>
                                    <button type="button"
                                            class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition"
                                            id="pv-add-bulk-btn">
                                        Add Vendors
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                @else
                    <p class="text-sm text-gray-500">Vendor links can be added after saving the product.</p>
                @endif
            </div>
        </div>
    </div>

    {{-- Form actions --}}
    <div class="flex gap-3 justify-end mt-6">
        <a href="{{ $isEdit ? ($returnUrl ?? route('products.index')) : route('products.index') }}"
           class="bg-white border border-gray-300 text-gray-700 px-6 py-2.5 rounded-lg font-medium hover:bg-gray-50 transition text-sm">
            Cancel
        </a>
        <button type="submit"
                class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2.5 rounded-lg font-medium transition text-sm">
            {{ $isEdit ? 'Update' : 'Save' }}
        </button>
        @if($isEdit)
            <button type="button"
                    class="bg-red-50 text-red-600 hover:bg-red-100 border border-red-200 px-6 py-2.5 rounded-lg font-medium transition text-sm"
                    onclick="if(confirm('Delete this product?')) document.getElementById('delete-form').submit();">
                Delete
            </button>
        @endif
    </div>
</form>

@if($isEdit)
    <form id="delete-form" method="POST" action="{{ route('products.destroy', $product->product_id) }}" class="hidden">
        @csrf
        @method('DELETE')
    </form>
@endif

@endsection

@push('scripts')
{{-- Cost calculator --}}
<script>
(function() {
    var price = document.getElementById('product_price');
    var amt   = document.getElementById('cost_amount');
    var pct   = document.getElementById('cost_percentage');
    var add   = document.getElementById('cost_additional');
    var out   = document.getElementById('product_cost');
    var mirror = document.getElementById('product_cost_mirror');

    var profitEl = document.getElementById('calc_profit');
    var marginEl = document.getElementById('calc_margin');
    var markupEl = document.getElementById('calc_markup');

    function calc() {
        var p = parseFloat(price.value) || 0;
        var a = parseFloat(amt.value) || 0;
        var c = parseFloat(pct.value) || 0;
        var d = parseFloat(add.value) || 0;
        var cost = a + (c / 100 * p) + d;
        var result = cost.toFixed(4);
        out.value = result;
        if (mirror) mirror.value = result;

        var profit = p - cost;
        if (profitEl) profitEl.value = profit.toFixed(2);
        if (marginEl) marginEl.value = p > 0 ? (profit / p * 100).toFixed(2) + '%' : '0%';
        if (markupEl) markupEl.value = cost > 0 ? (profit / cost * 100).toFixed(2) + '%' : '0%';

        if (profitEl) profitEl.style.color = profit >= 0 ? '#16a34a' : '#dc2626';
        if (marginEl) marginEl.style.color = profit >= 0 ? '#16a34a' : '#dc2626';
        if (markupEl) markupEl.style.color = profit >= 0 ? '#16a34a' : '#dc2626';
    }

    [price, amt, pct, add].forEach(function(el) { el.addEventListener('input', calc); });
    calc();

    // Cost override toggle (edit mode)
    var overrideBtn = document.getElementById('cost-override-btn');
    var lockBadge = document.getElementById('cost-lock-badge');
    var costFields = [amt, pct, add];
    var unlocked = false;

    if (overrideBtn) {
        overrideBtn.addEventListener('click', function() {
            if (!unlocked) {
                if (!confirm('Cost is normally updated when receiving a PO. Are you sure you want to edit manually?')) return;
                costFields.forEach(function(el) {
                    el.removeAttribute('readonly');
                    el.removeAttribute('tabindex');
                    el.classList.remove('bg-gray-50');
                });
                lockBadge.textContent = 'Manual override';
                lockBadge.className = 'text-xs px-2 py-0.5 rounded bg-amber-100 text-amber-700';
                overrideBtn.textContent = 'Lock';
                unlocked = true;
            } else {
                costFields.forEach(function(el) {
                    el.setAttribute('readonly', true);
                    el.setAttribute('tabindex', '-1');
                    el.classList.add('bg-gray-50');
                });
                lockBadge.textContent = 'Locked \u2014 updated on PO receive';
                lockBadge.className = 'text-xs px-2 py-0.5 rounded bg-gray-100 text-gray-500';
                overrideBtn.textContent = 'Override';
                unlocked = false;
            }
        });
    }
})();
</script>

{{-- Options builder --}}
<script>
'use strict';

const EXISTING_OPTION  = @json($existingOptions->first() ?? null);
const EXISTING_OPTION2 = @json($existingOptions->count() > 1 ? $existingOptions->get(1) : null);
const EXISTING_VALUES  = @json($existingOptions->first() ? ($existingOptions->first()->values ?? []) : []);
const EXISTING_VALUES2 = @json($existingOptions->count() > 1 ? ($existingOptions->get(1)->values ?? []) : []);
const EXISTING_COMBOS  = @json($existingCombinations ?? []);

// State
let opt1Values = [];
let opt2Values = [];
let comboData  = {};
let mode       = 'none';

// ---- DOM refs ----
const $opt1Group      = document.getElementById('option1-group');
const $opt2Group      = document.getElementById('option2-group');
const $opt2Wrapper    = document.getElementById('add-opt2-wrapper');
const $addOptWrapper  = document.getElementById('add-option-wrapper');
const $tableWrap      = document.getElementById('data-table-wrap');
const $addRowBtn      = document.getElementById('add-row-btn');
const $thead          = document.getElementById('data-thead');
const $tbody          = document.getElementById('data-tbody');
const $opt1Name       = document.getElementById('opt1-name-input');
const $opt2Name       = document.getElementById('opt2-name-input');
const $opt1ValList    = document.getElementById('opt1-value-list');
const $opt1ValSection = document.getElementById('opt1-values-section');
const $opt2ValList    = document.getElementById('opt2-value-list');
const $optionsRoot    = document.getElementById('options-root');

// ---- Helpers ----

function createEl(tag, attrs, textContent) {
    const el = document.createElement(tag);
    if (attrs) {
        Object.keys(attrs).forEach(key => {
            if (key === 'className') {
                el.className = attrs[key];
            } else if (key.startsWith('data-')) {
                el.dataset[key.slice(5)] = attrs[key];
            } else {
                el.setAttribute(key, attrs[key]);
            }
        });
    }
    if (textContent !== undefined) el.textContent = textContent;
    return el;
}

function createInput(className, type, value, attrs) {
    const el = createEl('input', Object.assign({
        className: 'rounded border-gray-300 focus:border-blue-500 focus:ring-blue-500 text-sm ' + className,
        type: type || 'text'
    }, attrs || {}));
    el.value = value !== undefined && value !== null ? value : '';
    return el;
}

function clearChildren(el) {
    while (el.firstChild) el.removeChild(el.firstChild);
}

function comboKey(v1, v2) {
    return v1 + '|' + (v2 || '');
}

// ---- Mode management ----

function setMode(newMode) {
    mode = newMode;
    $opt1Group.classList.toggle('hidden', mode === 'none');
    $opt2Group.classList.toggle('hidden', mode !== 'two');
    $opt2Wrapper.classList.toggle('hidden', mode !== 'one');
    $addOptWrapper.classList.toggle('hidden', mode !== 'none');
    $tableWrap.classList.toggle('hidden', mode === 'none' || opt1Values.length === 0);
    $addRowBtn.classList.toggle('hidden', mode !== 'one');
    $opt1ValSection.classList.toggle('hidden', mode !== 'two');
    if (mode === 'two') renderOpt1List();
    renderTable();
    recalcProductQty();
}

// ---- Value list rendering (2-option mode) ----

function renderValueList(container, values, onRemove, onRename) {
    clearChildren(container);
    values.forEach((v, i) => {
        const row = createEl('div', { className: 'flex gap-2 items-center' });
        const input = createInput('js-opt-val-name flex-1', 'text', v.name, { placeholder: 'Value name' });
        input.addEventListener('change', () => {
            const newName = input.value.trim();
            if (newName && newName !== v.name) {
                onRename(i, v.name, newName);
            }
        });
        row.appendChild(input);
        const removeBtn = createEl('button', {
            className: 'bg-red-50 text-red-500 hover:bg-red-100 rounded px-2 py-1 text-sm transition',
            type: 'button',
            title: 'Remove'
        }, '\u00d7');
        removeBtn.addEventListener('click', () => onRemove(i));
        row.appendChild(removeBtn);
        container.appendChild(row);
    });
}

function remapComboKeys(optNum, oldName, newName) {
    const updated = {};
    Object.keys(comboData).forEach(key => {
        const parts = key.split('|');
        if (optNum === 1 && parts[0] === oldName) {
            updated[newName + '|' + (parts[1] || '')] = comboData[key];
        } else if (optNum === 2 && parts[1] === oldName) {
            updated[parts[0] + '|' + newName] = comboData[key];
        } else {
            updated[key] = comboData[key];
        }
    });
    comboData = updated;
}

function renderOpt1List() {
    renderValueList($opt1ValList, opt1Values,
        (i) => {
            saveTableToState();
            opt1Values.splice(i, 1);
            renderOpt1List();
            renderTable();
            recalcProductQty();
        },
        (i, oldName, newName) => {
            saveTableToState();
            remapComboKeys(1, oldName, newName);
            opt1Values[i].name = newName;
            renderTable();
        }
    );
}

function renderOpt2List() {
    renderValueList($opt2ValList, opt2Values,
        (i) => {
            saveTableToState();
            opt2Values.splice(i, 1);
            renderOpt2List();
            renderTable();
            recalcProductQty();
        },
        (i, oldName, newName) => {
            saveTableToState();
            remapComboKeys(2, oldName, newName);
            opt2Values[i].name = newName;
            renderTable();
        }
    );
}

function addOpt1Value(name) {
    name = (name || '').trim();
    if (!name || opt1Values.some(v => v.name.toLowerCase() === name.toLowerCase())) return;
    opt1Values.push({ name, sku: '', quantity: 0, absolute_price: 0, absolute_cost: 0 });
    renderOpt1List();
    renderTable();
    recalcProductQty();
}

function addOpt2Value(name) {
    name = (name || '').trim();
    if (!name || opt2Values.some(v => v.name.toLowerCase() === name.toLowerCase())) return;
    opt2Values.push({ name });
    renderOpt2List();
    renderTable();
    recalcProductQty();
}

// ---- Table state ----

function saveTableToState() {
    if (mode === 'one') {
        $tbody.querySelectorAll('tr').forEach((row, i) => {
            if (i >= opt1Values.length) return;
            const nameInput = row.querySelector('.js-value-name');
            if (nameInput) opt1Values[i].name = nameInput.value.trim();
            const skuInput = row.querySelector('.js-sku');
            if (skuInput) opt1Values[i].sku = skuInput.value;
            const qtyInput = row.querySelector('.js-qty');
            if (qtyInput) opt1Values[i].quantity = parseInt(qtyInput.value || '0', 10);
            const priceInput = row.querySelector('.js-price');
            if (priceInput) opt1Values[i].absolute_price = parseFloat(priceInput.value || '0');
            const costInput = row.querySelector('.js-cost');
            if (costInput) opt1Values[i].absolute_cost = parseFloat(costInput.value || '0');
        });
    } else if (mode === 'two') {
        $tbody.querySelectorAll('tr').forEach(row => {
            const key = row.dataset.comboKey;
            if (!key) return;
            comboData[key] = {
                sku: row.querySelector('.js-sku').value || '',
                quantity: parseInt(row.querySelector('.js-qty').value || '0', 10),
                absolute_price: parseFloat(row.querySelector('.js-price').value || '0'),
                absolute_cost: parseFloat(row.querySelector('.js-cost').value || '0'),
            };
        });
    }
}

function calcRowMetrics(row) {
    const price  = parseFloat(row.querySelector('.js-price').value) || 0;
    const cost   = parseFloat(row.querySelector('.js-cost').value) || 0;
    const profit = price - cost;
    const margin = price > 0 ? (profit / price * 100) : 0;
    const color  = profit >= 0 ? 'text-green-600' : 'text-red-600';

    const profitEl = row.querySelector('.js-profit');
    if (profitEl) {
        profitEl.textContent = profit.toFixed(2);
        profitEl.className = 'text-sm font-medium js-profit ' + color;
    }
    const marginEl = row.querySelector('.js-margin');
    if (marginEl) {
        marginEl.textContent = margin.toFixed(1) + '%';
        marginEl.className = 'text-sm font-medium js-margin ' + color;
    }
}

function bindRowEvents(tr) {
    const priceEl = tr.querySelector('.js-price');
    const costEl  = tr.querySelector('.js-cost');
    const qtyEl   = tr.querySelector('.js-qty');
    if (priceEl) priceEl.addEventListener('input', () => calcRowMetrics(tr));
    if (costEl)  costEl.addEventListener('input', () => calcRowMetrics(tr));
    if (qtyEl)   qtyEl.addEventListener('input', () => recalcProductQty());
}

// ---- Table rendering ----

function buildHeaderRow(is2) {
    const tr = document.createElement('tr');
    const thClass = 'px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider';
    if (is2) {
        const th1 = createEl('th', { className: thClass }, $opt1Name.value || 'Option 1');
        const th2 = createEl('th', { className: thClass }, $opt2Name.value || 'Option 2');
        tr.appendChild(th1);
        tr.appendChild(th2);
    } else {
        tr.appendChild(createEl('th', { className: thClass }, 'Value'));
    }
    tr.appendChild(createEl('th', { className: thClass }, 'SKU'));
    tr.appendChild(createEl('th', { className: thClass }, 'Qty'));
    tr.appendChild(createEl('th', { className: thClass }, 'Price'));
    tr.appendChild(createEl('th', { className: thClass }, 'Cost'));
    tr.appendChild(createEl('th', { className: thClass }, 'Profit'));
    tr.appendChild(createEl('th', { className: thClass }, 'Margin'));
    tr.appendChild(createEl('th', { className: thClass + ' w-10' }));
    return tr;
}

function buildComboRow(v1Name, v2Name, data) {
    const tr = document.createElement('tr');
    tr.className = 'hover:bg-gray-50';
    tr.dataset.comboKey = comboKey(v1Name, v2Name);
    const tdClass = 'px-3 py-2';

    const td1 = createEl('td', { className: tdClass + ' text-sm text-gray-700 font-medium' }, v1Name);
    const td2 = createEl('td', { className: tdClass + ' text-sm text-gray-700 font-medium' }, v2Name);
    tr.appendChild(td1);
    tr.appendChild(td2);

    const tdSku = createEl('td', { className: tdClass });
    tdSku.appendChild(createInput('js-sku w-full', 'text', data.sku));
    tr.appendChild(tdSku);

    const tdQty = createEl('td', { className: tdClass });
    tdQty.appendChild(createInput('js-qty w-20', 'number', data.quantity));
    tr.appendChild(tdQty);

    const tdPrice = createEl('td', { className: tdClass });
    tdPrice.appendChild(createInput('js-price w-24', 'number', data.absolute_price, { step: '0.01' }));
    tr.appendChild(tdPrice);

    const tdCost = createEl('td', { className: tdClass });
    tdCost.appendChild(createInput('js-cost w-24', 'number', data.absolute_cost, { step: '0.01' }));
    tr.appendChild(tdCost);

    const tdProfit = createEl('td', { className: tdClass });
    tdProfit.appendChild(createEl('span', { className: 'text-sm font-medium js-profit' }, '\u2014'));
    tr.appendChild(tdProfit);

    const tdMargin = createEl('td', { className: tdClass });
    tdMargin.appendChild(createEl('span', { className: 'text-sm font-medium js-margin' }, '\u2014'));
    tr.appendChild(tdMargin);

    tr.appendChild(createEl('td', { className: tdClass }));

    bindRowEvents(tr);
    calcRowMetrics(tr);
    return tr;
}

function buildOneOptRow(v, idx) {
    const tr = document.createElement('tr');
    tr.className = 'hover:bg-gray-50';
    tr.dataset.rowIdx = idx;
    const tdClass = 'px-3 py-2';

    const tdName = createEl('td', { className: tdClass });
    const nameInput = createInput('js-value-name w-full', 'text', v.name, { placeholder: 'Value name' });
    tdName.appendChild(nameInput);
    tr.appendChild(tdName);

    const tdSku = createEl('td', { className: tdClass });
    tdSku.appendChild(createInput('js-sku w-full', 'text', v.sku || ''));
    tr.appendChild(tdSku);

    const tdQty = createEl('td', { className: tdClass });
    tdQty.appendChild(createInput('js-qty w-20', 'number', v.quantity || 0));
    tr.appendChild(tdQty);

    const tdPrice = createEl('td', { className: tdClass });
    tdPrice.appendChild(createInput('js-price w-24', 'number', v.absolute_price || 0, { step: '0.01' }));
    tr.appendChild(tdPrice);

    const tdCost = createEl('td', { className: tdClass });
    tdCost.appendChild(createInput('js-cost w-24', 'number', v.absolute_cost || 0, { step: '0.01' }));
    tr.appendChild(tdCost);

    const tdProfit = createEl('td', { className: tdClass });
    tdProfit.appendChild(createEl('span', { className: 'text-sm font-medium js-profit' }, '\u2014'));
    tr.appendChild(tdProfit);

    const tdMargin = createEl('td', { className: tdClass });
    tdMargin.appendChild(createEl('span', { className: 'text-sm font-medium js-margin' }, '\u2014'));
    tr.appendChild(tdMargin);

    const tdActions = createEl('td', { className: tdClass });
    const removeBtn = createEl('button', {
        className: 'bg-red-50 text-red-500 hover:bg-red-100 rounded px-2 py-1 text-sm transition',
        type: 'button',
        title: 'Remove'
    }, '\u00d7');
    removeBtn.addEventListener('click', () => {
        saveTableToState();
        opt1Values.splice(idx, 1);
        renderTable();
        recalcProductQty();
    });
    tdActions.appendChild(removeBtn);
    tr.appendChild(tdActions);

    bindRowEvents(tr);
    calcRowMetrics(tr);
    return tr;
}

function renderTable() {
    saveTableToState();
    clearChildren($thead);
    clearChildren($tbody);

    if (opt1Values.length === 0) {
        $tableWrap.classList.add('hidden');
        return;
    }

    $tableWrap.classList.remove('hidden');

    const is2 = mode === 'two' && opt2Values.length > 0;

    $thead.appendChild(buildHeaderRow(is2));

    if (is2) {
        opt1Values.forEach(v1 => {
            opt2Values.forEach(v2 => {
                const key = comboKey(v1.name, v2.name);
                const d = comboData[key] || { sku: '', quantity: 0, absolute_price: 0, absolute_cost: 0 };
                $tbody.appendChild(buildComboRow(v1.name, v2.name, d));
            });
        });
    } else {
        opt1Values.forEach((v, idx) => {
            $tbody.appendChild(buildOneOptRow(v, idx));
        });
    }
}

// ---- Product quantity sync ----

function recalcProductQty() {
    const qtyInput = document.getElementById('product_quantity') || document.querySelector('input[name="quantity"]');
    if (!qtyInput) return;
    const hint = document.getElementById('qty_hint');

    if (mode !== 'none' && opt1Values.length > 0) {
        let total = 0;
        $tbody.querySelectorAll('.js-qty').forEach(el => {
            const v = parseInt(el.value || '0', 10);
            if (!isNaN(v)) total += v;
        });
        qtyInput.readOnly = true;
        qtyInput.value = total;
        qtyInput.classList.add('bg-gray-50');
        qtyInput.title = 'Auto-calculated from option quantities';
        if (hint) hint.classList.remove('hidden');
    } else {
        qtyInput.readOnly = false;
        qtyInput.classList.remove('bg-gray-50');
        qtyInput.title = '';
        if (hint) hint.classList.add('hidden');
    }
}

// ---- beforeSubmit ----

function beforeSubmit() {
    $optionsRoot.querySelectorAll('input[data-gen]').forEach(el => el.remove());

    if (mode === 'none' || opt1Values.length === 0) return;

    saveTableToState();

    const addHidden = (name, value) => {
        const el = createEl('input', { type: 'hidden', name: name, 'data-gen': '1' });
        el.value = value;
        $optionsRoot.appendChild(el);
    };

    const opt1Name = $opt1Name.value.trim();
    if (!opt1Name) return;

    if (mode === 'two' && opt2Values.length > 0) {
        const opt2Name = $opt2Name.value.trim();
        if (!opt2Name) return;

        addHidden('option1_name', opt1Name);
        addHidden('option2_name', opt2Name);

        opt1Values.forEach((v, i) => addHidden('option1_values[' + i + ']', v.name));
        opt2Values.forEach((v, i) => addHidden('option2_values[' + i + ']', v.name));

        let ci = 0;
        opt1Values.forEach(v1 => {
            opt2Values.forEach(v2 => {
                const key = comboKey(v1.name, v2.name);
                const d = comboData[key] || {};
                addHidden('combinations[' + ci + '][opt1]', v1.name);
                addHidden('combinations[' + ci + '][opt2]', v2.name);
                addHidden('combinations[' + ci + '][sku]', d.sku || '');
                addHidden('combinations[' + ci + '][quantity]', d.quantity || 0);
                addHidden('combinations[' + ci + '][absolute_price]', d.absolute_price || 0);
                addHidden('combinations[' + ci + '][absolute_cost]', d.absolute_cost || 0);
                ci++;
            });
        });
    } else {
        addHidden('option_name', opt1Name);

        opt1Values.forEach((v, idx) => {
            addHidden('values[' + idx + '][name]', v.name);
            addHidden('values[' + idx + '][sku]', v.sku || '');
            addHidden('values[' + idx + '][quantity]', v.quantity || 0);
            addHidden('values[' + idx + '][absolute_price]', v.absolute_price || 0);
            addHidden('values[' + idx + '][absolute_cost]', v.absolute_cost || 0);
            if (v.option_value_id) {
                addHidden('values[' + idx + '][option_value_id]', v.option_value_id);
            }
        });
    }
}

// ---- Event listeners ----

document.getElementById('add-option-btn').addEventListener('click', () => {
    setMode('one');
    $opt1Name.focus();
});

document.getElementById('remove-opt1-btn').addEventListener('click', () => {
    opt1Values = [];
    opt2Values = [];
    comboData = {};
    renderOpt1List();
    renderOpt2List();
    $opt1Name.value = '';
    $opt2Name.value = '';
    setMode('none');
});

document.getElementById('add-opt2-btn').addEventListener('click', () => {
    setMode('two');
    $opt2Name.focus();
});

document.getElementById('remove-opt2-btn').addEventListener('click', () => {
    opt2Values = [];
    renderOpt2List();
    $opt2Name.value = '';
    const newData = {};
    opt1Values.forEach(v1 => {
        const key = comboKey(v1.name, null);
        const found = Object.keys(comboData).find(k => k.startsWith(v1.name + '|'));
        if (found) newData[key] = comboData[found];
    });
    comboData = newData;
    setMode('one');
});

$addRowBtn.addEventListener('click', () => {
    saveTableToState();
    opt1Values.push({ name: '', sku: '', quantity: 0, absolute_price: 0, absolute_cost: 0 });
    renderTable();
    const rows = $tbody.querySelectorAll('tr');
    const lastRow = rows[rows.length - 1];
    if (lastRow) {
        const nameInput = lastRow.querySelector('.js-value-name');
        if (nameInput) nameInput.focus();
    }
    recalcProductQty();
});

// Opt1 add value (2-option mode)
function handleAddOpt1() {
    const input = document.getElementById('opt1-new-value');
    addOpt1Value(input.value);
    input.value = '';
    input.focus();
}
document.getElementById('opt1-add-value-btn').addEventListener('click', handleAddOpt1);
document.getElementById('opt1-new-value').addEventListener('keydown', e => {
    if (e.key === 'Enter') { e.preventDefault(); handleAddOpt1(); }
});

// Opt2 add value
function handleAddOpt2() {
    const input = document.getElementById('opt2-new-value');
    addOpt2Value(input.value);
    input.value = '';
    input.focus();
}
document.getElementById('opt2-add-value-btn').addEventListener('click', handleAddOpt2);
document.getElementById('opt2-new-value').addEventListener('keydown', e => {
    if (e.key === 'Enter') { e.preventDefault(); handleAddOpt2(); }
});

// Form submit hook
const form = $optionsRoot.closest('form');
if (form) {
    form.addEventListener('submit', () => {
        recalcProductQty();
        beforeSubmit();
    });
}

// ---- Load existing data ----

if (EXISTING_OPTION) {
    EXISTING_VALUES.forEach(v => {
        opt1Values.push({
            name: v.option_value_name || v.name,
            option_value_id: v.option_value_id,
            sku: v.sku || '',
            quantity: v.quantity || 0,
            absolute_price: parseFloat(v.absolute_price) || 0,
            absolute_cost: parseFloat(v.absolute_cost) || 0,
        });
    });

    $opt1Name.value = EXISTING_OPTION.option_name || EXISTING_OPTION.name || '';

    if (EXISTING_OPTION2) {
        EXISTING_VALUES2.forEach(v => {
            opt2Values.push({ name: v.option_value_name || v.name, option_value_id: v.option_value_id });
        });
        $opt2Name.value = EXISTING_OPTION2.option_name || EXISTING_OPTION2.name || '';
        renderOpt1List();
        renderOpt2List();

        if (EXISTING_COMBOS.length > 0) {
            EXISTING_COMBOS.forEach(c => {
                const key = comboKey(c.opt1_name, c.opt2_name);
                comboData[key] = {
                    sku: c.sku || '',
                    quantity: c.quantity || 0,
                    absolute_price: parseFloat(c.absolute_price) || 0,
                    absolute_cost: parseFloat(c.absolute_cost) || 0,
                };
            });
        }
        setMode('two');
    } else {
        setMode('one');
    }
} else {
    recalcProductQty();
}
</script>

{{-- Vendor pricing (edit mode only) --}}
@if($isEdit)
<script>
document.addEventListener('DOMContentLoaded', function() {
    var productId = {{ (int) $product->product_id }};
    var baseUrl = '/products/' + productId + '/vendors';
    var csrfToken = document.querySelector('meta[name="csrf-token"]').content;

    var vendorList = @json($vendors ?? []);
    var currencyList = @json($currencies->pluck('code'));

    // Build option value map
    var optionValues = [];
    @if($existingOptions->isNotEmpty())
        @foreach($existingOptions as $opt)
            @if(!empty($opt->values))
                @foreach($opt->values as $val)
                    optionValues.push({
                        product_option_value_id: {{ (int)$val['product_option_value_id'] }},
                        label: {!! json_encode(html_entity_decode($opt->name, ENT_QUOTES|ENT_HTML5, 'UTF-8') . ': ' . ($val['name'] ?? '')) !!},
                        sku: {!! json_encode($val['sku'] ?? '') !!}
                    });
                @endforeach
            @endif
        @endforeach
    @endif

    var hasOptions = optionValues.length > 0;

    // Show the right form mode
    if (hasOptions) {
        document.getElementById('pv-simple-fields').classList.add('hidden');
        document.getElementById('pv-option-fields').classList.remove('hidden');
        buildOptionRows();
    }

    // Fill option labels in existing table
    document.querySelectorAll('.pv-option-label').forEach(function(td) {
        var povId = parseInt(td.dataset.povId);
        if (!povId) { td.textContent = '\u2014'; return; }
        var ov = optionValues.find(function(v) { return v.product_option_value_id === povId; });
        td.textContent = ov ? ov.label : '#' + povId;
    });

    // Show empty message if no rows
    function checkEmpty() {
        var rows = document.querySelectorAll('#buying-prices-table tbody tr[data-pv-id]');
        var emptyMsg = document.getElementById('pv-empty-msg');
        if (emptyMsg) {
            emptyMsg.style.display = rows.length === 0 ? '' : 'none';
            emptyMsg.classList.toggle('hidden', rows.length > 0);
        }
    }
    checkEmpty();

    function buildCurrencySelect(selectedCode) {
        var sel = document.createElement('select');
        currencyList.forEach(function(c) {
            var opt = document.createElement('option');
            opt.value = c;
            opt.textContent = c;
            if (c === selectedCode) opt.selected = true;
            sel.appendChild(opt);
        });
        return sel.innerHTML;
    }

    // Inline edit - show Save button on input change
    document.getElementById('buying-prices-table').addEventListener('input', function(e) {
        var tr = e.target.closest('tr[data-pv-id]');
        if (!tr) return;
        var saveBtn = tr.querySelector('.pv-save-row-btn');
        if (saveBtn) {
            saveBtn.style.display = '';
            saveBtn.classList.remove('hidden');
        }
    });

    // Save inline edit & remove
    document.getElementById('buying-prices-table').addEventListener('click', function(e) {
        var saveBtn = e.target.closest('.pv-save-row-btn');
        if (saveBtn) {
            var tr = saveBtn.closest('tr');
            var pvId = saveBtn.dataset.id;
            saveBtn.disabled = true;
            saveBtn.textContent = '\u2026';

            fetch(baseUrl + '/' + pvId, {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
                body: JSON.stringify({
                    vendor_sku: tr.querySelector('.pv-edit-sku').value || null,
                    min_qty: parseInt(tr.querySelector('.pv-edit-minqty').value) || 1,
                    currency_code: tr.querySelector('.pv-edit-currency').value,
                    unit_price: parseFloat(tr.querySelector('.pv-edit-price').value) || 0,
                })
            })
            .then(function(r) { return r.json().then(function(d) { return { ok: r.ok, data: d }; }); })
            .then(function(res) {
                saveBtn.disabled = false;
                if (res.ok) {
                    saveBtn.style.display = 'none';
                    saveBtn.classList.add('hidden');
                    saveBtn.textContent = 'Save';
                } else {
                    saveBtn.textContent = 'Save';
                    alert(res.data.error || res.data.message || 'Error saving.');
                }
            })
            .catch(function() { saveBtn.disabled = false; saveBtn.textContent = 'Save'; });
            return;
        }

        var removeBtn = e.target.closest('.pv-remove-btn');
        if (removeBtn) {
            if (!confirm('Remove this vendor link?')) return;
            var pvId = removeBtn.dataset.id;
            fetch(baseUrl + '/' + pvId, {
                method: 'DELETE',
                headers: { 'X-CSRF-TOKEN': csrfToken }
            })
            .then(function(r) {
                if (!r.ok) return;
                removeBtn.closest('tr').remove();
                groupExistingTable();
                checkEmpty();
            });
        }
    });

    // Bulk remove by vendor
    function bulkRemove(vendorId) {
        fetch(baseUrl, {
            method: 'DELETE',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
            body: JSON.stringify({ vendor_id: parseInt(vendorId) })
        })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (!res.ok) return;
            var tbody = document.querySelector('#buying-prices-table tbody');
            tbody.querySelectorAll('tr[data-vendor-id="' + vendorId + '"]').forEach(function(tr) {
                tr.remove();
            });
            groupExistingTable();
            checkEmpty();
        });
    }

    // Vendor grouping for existing table
    function groupExistingTable() {
        var tbody = document.querySelector('#buying-prices-table tbody');
        tbody.querySelectorAll('.pv-vendor-spacer, .pv-vendor-header').forEach(function(el) { el.remove(); });

        var rows = Array.from(tbody.querySelectorAll('tr[data-pv-id]'));
        if (rows.length === 0) return;

        var groups = {};
        var order = [];
        rows.forEach(function(tr) {
            var vid = tr.dataset.vendorId || '0';
            var name = tr.querySelector('td').textContent.trim();
            if (!groups[vid]) { groups[vid] = { name: name, rows: [] }; order.push(vid); }
            groups[vid].rows.push(tr);
        });

        order.forEach(function(vid, gIdx) {
            var g = groups[vid];

            if (gIdx > 0) {
                var spacer = document.createElement('tr');
                spacer.className = 'pv-vendor-spacer';
                var spacerTd = document.createElement('td');
                spacerTd.colSpan = 7;
                spacerTd.className = 'p-0 border-0';
                var spacerDiv = document.createElement('div');
                spacerDiv.className = 'h-3 my-1 border-t-2 border-gray-200';
                spacerTd.appendChild(spacerDiv);
                spacer.appendChild(spacerTd);
                tbody.appendChild(spacer);
            }

            var header = document.createElement('tr');
            header.className = 'pv-vendor-header bg-gray-50';
            var td = document.createElement('td');
            td.colSpan = 7;
            td.className = 'p-0';
            var wrap = document.createElement('div');
            wrap.className = 'flex items-center justify-between px-3 py-2';

            var labelSpan = document.createElement('span');
            labelSpan.className = 'cursor-pointer text-sm font-semibold text-gray-700';
            var chevron = document.createElement('span');
            chevron.className = 'pv-chevron inline-block w-4 transition-transform';
            chevron.textContent = '\u25B6';
            labelSpan.appendChild(chevron);
            labelSpan.appendChild(document.createTextNode(' ' + g.name + ' '));
            var countSpan = document.createElement('span');
            countSpan.className = 'text-gray-400 font-normal';
            countSpan.textContent = '(' + g.rows.length + ')';
            labelSpan.appendChild(countSpan);
            wrap.appendChild(labelSpan);

            var removeBtn = document.createElement('button');
            removeBtn.type = 'button';
            removeBtn.className = 'text-xs px-2 py-1 rounded bg-red-50 text-red-600 hover:bg-red-100 transition';
            removeBtn.textContent = 'Remove vendor';
            (function(v, n) {
                removeBtn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    if (!confirm('Remove all entries for ' + n + '?')) return;
                    bulkRemove(v);
                });
            })(vid, g.name);
            wrap.appendChild(removeBtn);
            td.appendChild(wrap);
            header.appendChild(td);
            tbody.appendChild(header);

            g.rows.forEach(function(tr) {
                tr.style.display = 'none';
                tbody.appendChild(tr);
            });

            (function(hdr, grp) {
                hdr.querySelector('span[class*="cursor"]').addEventListener('click', function() {
                    var chev = hdr.querySelector('.pv-chevron');
                    var hidden = grp.rows[0].style.display === 'none';
                    grp.rows.forEach(function(tr) { tr.style.display = hidden ? '' : 'none'; });
                    chev.style.transform = hidden ? 'rotate(90deg)' : 'rotate(0deg)';
                });
            })(header, g);
        });
    }

    groupExistingTable();

    // Add new - option rows vendor grouping
    function updateVendorGrouping() {
        var tbody = document.getElementById('pv-option-tbody');
        tbody.querySelectorAll('.pv-vendor-spacer').forEach(function(el) { el.remove(); });
        var rows = Array.from(tbody.querySelectorAll('tr[data-pov]'));
        var prevVid = null;
        rows.forEach(function(tr) {
            var vid = tr.querySelector('.pv-var-vendor').value || null;
            if (prevVid !== null && vid && vid !== prevVid) {
                var spacer = document.createElement('tr');
                spacer.className = 'pv-vendor-spacer';
                var spacerTd = document.createElement('td');
                spacerTd.colSpan = 6;
                spacerTd.className = 'p-0 border-0';
                var spacerDiv = document.createElement('div');
                spacerDiv.className = 'h-3 my-1 border-t-2 border-gray-200';
                spacerTd.appendChild(spacerDiv);
                spacer.appendChild(spacerTd);
                tr.parentNode.insertBefore(spacer, tr);
            }
            if (vid) prevVid = vid;
        });
    }

    function setupRowVendorTypeahead(inputEl, hiddenEl, listEl, currencyEl) {
        var timer = null;
        function hideList() { listEl.style.display = 'none'; }
        function showList() { listEl.style.display = 'block'; }
        function render(items) {
            while (listEl.firstChild) listEl.removeChild(listEl.firstChild);
            if (!items.length) {
                var empty = document.createElement('div');
                empty.className = 'px-3 py-2 text-xs text-gray-400';
                empty.textContent = 'No vendors found';
                listEl.appendChild(empty);
                showList();
                return;
            }
            items.forEach(function(v) {
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.textContent = v.name;
                btn.className = 'block w-full text-left px-3 py-2 text-sm text-gray-700 hover:bg-gray-50 transition';
                btn.addEventListener('mousedown', function(e) {
                    e.preventDefault();
                    inputEl.value = v.name;
                    hiddenEl.value = v.id;
                    hideList();
                    if (v.currency_code && currencyEl) currencyEl.value = v.currency_code;
                    updateVendorGrouping();
                });
                listEl.appendChild(btn);
            });
            showList();
        }
        function filter(q) {
            q = q.toLowerCase();
            if (!q) return vendorList.slice(0, 50);
            return vendorList.filter(function(v) {
                return v.name.toLowerCase().indexOf(q) !== -1;
            }).slice(0, 50);
        }
        inputEl.addEventListener('input', function() {
            hiddenEl.value = '';
            clearTimeout(timer);
            timer = setTimeout(function() { render(filter(inputEl.value.trim())); }, 80);
        });
        inputEl.addEventListener('focus', function() { render(filter(inputEl.value.trim())); });
        document.addEventListener('click', function(e) {
            if (e.target !== inputEl && !listEl.contains(e.target)) hideList();
        });
    }

    function buildOptionRows() {
        var tbody = document.getElementById('pv-option-tbody');
        while (tbody.firstChild) tbody.removeChild(tbody.firstChild);

        optionValues.forEach(function(ov) {
            var tr = document.createElement('tr');
            tr.dataset.pov = ov.product_option_value_id;
            tr.className = 'hover:bg-gray-50';

            // Option label cell
            var tdLabel = document.createElement('td');
            tdLabel.className = 'px-3 py-2 text-sm';
            var labelText = document.createTextNode(ov.label + ' ');
            tdLabel.appendChild(labelText);
            var skuSpan = document.createElement('span');
            skuSpan.className = 'text-gray-400';
            skuSpan.textContent = '[' + ov.sku + ']';
            tdLabel.appendChild(skuSpan);
            tr.appendChild(tdLabel);

            // Vendor cell
            var tdVendor = document.createElement('td');
            tdVendor.className = 'px-3 py-2 relative';
            var vendorInput = document.createElement('input');
            vendorInput.className = 'w-36 rounded border-gray-300 focus:border-blue-500 focus:ring-blue-500 text-xs pv-var-vendor-input';
            vendorInput.autocomplete = 'off';
            vendorInput.placeholder = 'Search vendor\u2026';
            var vendorHidden = document.createElement('input');
            vendorHidden.type = 'hidden';
            vendorHidden.className = 'pv-var-vendor';
            var vendorListDiv = document.createElement('div');
            vendorListDiv.className = 'pv-var-vendor-list absolute z-50 bg-white border border-gray-200 rounded-lg shadow-lg max-h-44 overflow-y-auto w-52';
            vendorListDiv.style.display = 'none';
            tdVendor.appendChild(vendorInput);
            tdVendor.appendChild(vendorHidden);
            tdVendor.appendChild(vendorListDiv);
            tr.appendChild(tdVendor);

            // Currency cell
            var tdCurrency = document.createElement('td');
            tdCurrency.className = 'px-3 py-2';
            var currencySelect = document.createElement('select');
            currencySelect.className = 'w-20 rounded border-gray-300 focus:border-blue-500 focus:ring-blue-500 text-xs pv-var-currency';
            currencyList.forEach(function(c) {
                var opt = document.createElement('option');
                opt.value = c;
                opt.textContent = c;
                if (c === 'PHP') opt.selected = true;
                currencySelect.appendChild(opt);
            });
            tdCurrency.appendChild(currencySelect);
            tr.appendChild(tdCurrency);

            // Min Qty cell
            var tdMinQty = document.createElement('td');
            tdMinQty.className = 'px-3 py-2';
            var minQtyInput = document.createElement('input');
            minQtyInput.className = 'w-16 rounded border-gray-300 focus:border-blue-500 focus:ring-blue-500 text-xs pv-var-minqty';
            minQtyInput.type = 'number';
            minQtyInput.min = '1';
            minQtyInput.value = '1';
            tdMinQty.appendChild(minQtyInput);
            tr.appendChild(tdMinQty);

            // Vendor SKU cell
            var tdSku = document.createElement('td');
            tdSku.className = 'px-3 py-2';
            var skuInput = document.createElement('input');
            skuInput.className = 'w-28 rounded border-gray-300 focus:border-blue-500 focus:ring-blue-500 text-xs pv-var-sku';
            skuInput.value = ov.sku;
            tdSku.appendChild(skuInput);
            tr.appendChild(tdSku);

            // Unit Price cell
            var tdPrice = document.createElement('td');
            tdPrice.className = 'px-3 py-2';
            var priceInput = document.createElement('input');
            priceInput.className = 'w-24 rounded border-gray-300 focus:border-blue-500 focus:ring-blue-500 text-xs pv-var-price';
            priceInput.type = 'number';
            priceInput.step = '0.0001';
            priceInput.min = '0';
            priceInput.value = '0';
            tdPrice.appendChild(priceInput);
            tr.appendChild(tdPrice);

            tbody.appendChild(tr);

            setupRowVendorTypeahead(vendorInput, vendorHidden, vendorListDiv, currencySelect);
        });
    }

    // "Copy first row" button
    document.getElementById('pv-apply-all-btn').addEventListener('click', function() {
        var rows = document.querySelectorAll('#pv-option-tbody tr[data-pov]');
        if (rows.length < 2) return;
        var first = rows[0];
        var vendorId = first.querySelector('.pv-var-vendor').value;
        var vendorName = first.querySelector('.pv-var-vendor-input').value;
        var currencyVal = first.querySelector('.pv-var-currency').value;
        var minQtyVal = first.querySelector('.pv-var-minqty').value;
        if (!vendorId) { alert('Please select a vendor in the first row first.'); return; }
        for (var i = 1; i < rows.length; i++) {
            rows[i].querySelector('.pv-var-vendor').value = vendorId;
            rows[i].querySelector('.pv-var-vendor-input').value = vendorName;
            rows[i].querySelector('.pv-var-currency').value = currencyVal;
            rows[i].querySelector('.pv-var-minqty').value = minQtyVal;
        }
        updateVendorGrouping();
    });

    // Simple add (product-level, no options)
    if (window.setupTypeahead) {
        window.setupTypeahead({
            inputId: 'pv-vendor-search',
            hiddenId: 'pv-vendor-id',
            listId: 'pv-vendor-list',
            url: '/api/vendors?q=',
            showAllOnFocus: true,
            onSelect: function(item) {
                if (item.currency_code) document.getElementById('pv-currency').value = item.currency_code;
            }
        });
    }

    document.getElementById('pv-add-btn').addEventListener('click', function() {
        var vendorId = document.getElementById('pv-vendor-id').value;
        if (!vendorId) { alert('Please select a vendor.'); return; }
        fetch(baseUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
            body: JSON.stringify({
                vendor_id: vendorId,
                vendor_sku: document.getElementById('pv-vendor-sku').value || null,
                min_qty: parseInt(document.getElementById('pv-min-qty').value) || 1,
                currency_code: document.getElementById('pv-currency').value,
                unit_price: parseFloat(document.getElementById('pv-unit-price').value) || 0,
            })
        })
        .then(function(r) { return r.json().then(function(d) { return { ok: r.ok, data: d }; }); })
        .then(function(res) {
            if (!res.ok) { alert(res.data.error || 'Error adding vendor.'); return; }
            appendRow(res.data);
            document.getElementById('pv-vendor-id').value = '';
            document.getElementById('pv-vendor-search').value = '';
            document.getElementById('pv-vendor-sku').value = '';
            document.getElementById('pv-min-qty').value = '1';
            document.getElementById('pv-unit-price').value = '';
        });
    });

    // Bulk add (per-option)
    document.getElementById('pv-add-bulk-btn').addEventListener('click', function() {
        var btn = this;
        var options = [];
        document.querySelectorAll('#pv-option-tbody tr[data-pov]').forEach(function(tr) {
            var vendorId = tr.querySelector('.pv-var-vendor').value;
            if (!vendorId) return;
            options.push({
                product_option_value_id: parseInt(tr.dataset.pov),
                vendor_id: parseInt(vendorId),
                currency_code: tr.querySelector('.pv-var-currency').value,
                min_qty: parseInt(tr.querySelector('.pv-var-minqty').value) || 1,
                vendor_sku: tr.querySelector('.pv-var-sku').value || null,
                unit_price: parseFloat(tr.querySelector('.pv-var-price').value) || 0,
            });
        });
        if (options.length === 0) { alert('Please select a vendor for at least one option.'); return; }

        btn.disabled = true;
        btn.textContent = 'Saving\u2026';

        fetch(baseUrl + '/bulk', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
            body: JSON.stringify({ options: options })
        })
        .then(function(r) { return r.json().then(function(d) { return { ok: r.ok, data: d }; }); })
        .then(function(res) {
            btn.disabled = false;
            btn.textContent = 'Add Vendors';
            if (!res.ok) { alert(res.data.error || res.data.message || 'Error saving.'); return; }
            var count = res.data.length;
            res.data.forEach(function(pv) { appendRow(pv); });
            var status = document.getElementById('pv-bulk-status');
            if (status && count > 0) {
                status.textContent = count + ' added';
                status.style.display = 'inline';
                status.classList.remove('hidden');
                status.style.color = '#10b981';
                setTimeout(function() { status.style.display = 'none'; status.classList.add('hidden'); }, 3000);
            } else if (status && count === 0) {
                status.textContent = 'All already exist';
                status.style.display = 'inline';
                status.classList.remove('hidden');
                status.style.color = '#f59e0b';
                setTimeout(function() { status.style.display = 'none'; status.classList.add('hidden'); }, 3000);
            }
            buildOptionRows();
        })
        .catch(function(err) {
            btn.disabled = false;
            btn.textContent = 'Add Vendors';
            alert('Network error: ' + (err.message || 'Request failed'));
        });
    });

    // Append row - inline-editable format (using safe DOM methods)
    function appendRow(pv) {
        var tbody = document.querySelector('#buying-prices-table tbody');
        if (tbody.querySelector('tr[data-pv-id="' + pv.id + '"]')) return;

        var tr = document.createElement('tr');
        tr.className = 'hover:bg-gray-50';
        tr.dataset.pvId = pv.id;
        tr.dataset.vendorId = pv.vendor_id || '';
        tr.dataset.povId = pv.product_option_value_id || '';

        var optionLabel = '\u2014';
        if (pv.product_option_value_id) {
            var ov = optionValues.find(function(v) { return v.product_option_value_id === pv.product_option_value_id; });
            optionLabel = ov ? ov.label : '#' + pv.product_option_value_id;
        }

        // Vendor name
        var tdVendor = document.createElement('td');
        tdVendor.className = 'px-3 py-2 text-sm text-gray-700';
        tdVendor.textContent = pv.vendor ? pv.vendor.name : '\u2014';
        tr.appendChild(tdVendor);

        // Option label
        var tdOption = document.createElement('td');
        tdOption.className = 'px-3 py-2 text-sm text-gray-500';
        tdOption.textContent = optionLabel;
        tr.appendChild(tdOption);

        // SKU input
        var tdSku = document.createElement('td');
        tdSku.className = 'px-3 py-2';
        var skuInput = document.createElement('input');
        skuInput.type = 'text';
        skuInput.className = 'w-full rounded border-gray-300 focus:border-blue-500 focus:ring-blue-500 text-xs pv-edit-sku';
        skuInput.value = pv.vendor_sku || '';
        tdSku.appendChild(skuInput);
        tr.appendChild(tdSku);

        // Min Qty input
        var tdMinQty = document.createElement('td');
        tdMinQty.className = 'px-3 py-2';
        var minQtyInput = document.createElement('input');
        minQtyInput.type = 'number';
        minQtyInput.className = 'w-16 rounded border-gray-300 focus:border-blue-500 focus:ring-blue-500 text-xs pv-edit-minqty';
        minQtyInput.min = '1';
        minQtyInput.value = pv.min_qty;
        tdMinQty.appendChild(minQtyInput);
        tr.appendChild(tdMinQty);

        // Currency select
        var tdCurrency = document.createElement('td');
        tdCurrency.className = 'px-3 py-2';
        var currSelect = document.createElement('select');
        currSelect.className = 'w-20 rounded border-gray-300 focus:border-blue-500 focus:ring-blue-500 text-xs pv-edit-currency';
        currencyList.forEach(function(c) {
            var opt = document.createElement('option');
            opt.value = c;
            opt.textContent = c;
            if (c === pv.currency_code) opt.selected = true;
            currSelect.appendChild(opt);
        });
        tdCurrency.appendChild(currSelect);
        tr.appendChild(tdCurrency);

        // Price input
        var tdPrice = document.createElement('td');
        tdPrice.className = 'px-3 py-2';
        var priceInput = document.createElement('input');
        priceInput.type = 'number';
        priceInput.className = 'w-24 rounded border-gray-300 focus:border-blue-500 focus:ring-blue-500 text-xs text-right pv-edit-price';
        priceInput.step = '0.0001';
        priceInput.min = '0';
        priceInput.value = pv.unit_price;
        tdPrice.appendChild(priceInput);
        tr.appendChild(tdPrice);

        // Actions
        var tdActions = document.createElement('td');
        tdActions.className = 'px-3 py-2 text-right whitespace-nowrap';
        var saveBtn = document.createElement('button');
        saveBtn.type = 'button';
        saveBtn.className = 'hidden text-xs px-2 py-1 rounded bg-blue-50 text-blue-700 hover:bg-blue-100 transition pv-save-row-btn';
        saveBtn.dataset.id = pv.id;
        saveBtn.textContent = 'Save';
        tdActions.appendChild(saveBtn);
        tdActions.appendChild(document.createTextNode(' '));
        var delBtn = document.createElement('button');
        delBtn.type = 'button';
        delBtn.className = 'text-xs px-2 py-1 rounded bg-red-50 text-red-600 hover:bg-red-100 transition pv-remove-btn';
        delBtn.dataset.id = pv.id;
        delBtn.textContent = 'Remove';
        tdActions.appendChild(delBtn);
        tr.appendChild(tdActions);

        tbody.appendChild(tr);
        groupExistingTable();
        checkEmpty();
    }
});
</script>
@endif
@endpush
