@extends('layouts.app')
@section('breadcrumb', 'Marketplace / Shopee / Products / Add')

@section('title', 'Add Product to Shopee')

@section('content')
    <div class="page-header">
        <div>
            <h2>Add Product to Shopee</h2>
            <div class="text-muted text-sm">Select an ERP product, configure category and pricing, then push to Shopee.</div>
        </div>
        <div class="page-header-actions">
            <a class="btn" href="{{ route('ext.shopee.products.index') }}">Back to Products</a>
        </div>
    </div>

    <div class="card">
        {{-- Product Search --}}
        <div>
            <label>ERP Product <span style="color:#d11;">*</span></label>
            <input type="hidden" id="selectedProductId" value="">
            <div style="position:relative;">
                <input type="text" class="input" id="productSearch" placeholder="Search by name, SKU or product ID..." autocomplete="off">
                <div id="productResults" style="display:none; position:absolute; z-index:60; top:100%; left:0; right:0; background:var(--surface); border:1px solid var(--border); border-radius:var(--radius-md); box-shadow:var(--shadow-lg); max-height:280px; overflow-y:auto; margin-top:4px;"></div>
            </div>
            <div id="selectedProductInfo" style="display:none; margin-top:8px; padding:10px 14px; background:var(--bg-subtle, #f0f4f8); border-radius:var(--radius-md); border:1px solid var(--border-light);">
            </div>
            <div class="hint" style="margin-top:4px;">Type at least 2 characters to search products.</div>
        </div>

        {{-- Shopee Category --}}
        <div style="margin-top:16px;">
            <label>Shopee Category</label>
            <select id="categorySelect" class="input">
                <option value="">-- Select Shopee Category --</option>
                @foreach($shopeeCategories as $c)
                    <option value="{{ (int)$c->category_id }}">{{ $c->name }} ({{ $c->category_id }})</option>
                @endforeach
            </select>
            <div class="hint">Optional. Pre-selects the category on the push form.</div>
        </div>

        {{-- Brand --}}
        <div style="margin-top:16px;">
            <label>Brand</label>
            <input type="text" class="input" value="No Brand (brand_id: 0)" disabled>
            <div class="hint">Brand is set to "No Brand" by default on Shopee.</div>
        </div>

        {{-- Price Markup --}}
        <h3 class="section-title" style="margin-top:20px;">Price Markup</h3>
        <div class="hint" style="margin-bottom:8px;">Formula: <strong>(Product Price &times; %) + Product Price + Fixed Amount</strong></div>
        <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 12px;">
            <div>
                <label>Fixed Amount</label>
                <input type="number" id="markupFixed" class="input" step="0.01" min="0" value="" placeholder="e.g. 100">
                <div class="hint">Flat amount added after percentage.</div>
            </div>
            <div>
                <label>Percentage (%)</label>
                <input type="number" id="markupPercent" class="input" step="0.01" min="0" value="" placeholder="e.g. 5">
                <div class="hint">% of product price applied first.</div>
            </div>
        </div>

        <div class="d-flex justify-end gap-8 mt-16">
            <a class="btn" href="{{ route('ext.shopee.products.index') }}">Cancel</a>
            <button type="button" id="continueBtn" class="btn" disabled>Continue to Push Form</button>
        </div>
    </div>

@push('scripts')
<script>
(function(){
    var searchInput = document.getElementById('productSearch');
    var resultsDiv = document.getElementById('productResults');
    var hiddenId = document.getElementById('selectedProductId');
    var infoDiv = document.getElementById('selectedProductInfo');
    var btn = document.getElementById('continueBtn');
    var categorySelect = document.getElementById('categorySelect');
    var markupFixed = document.getElementById('markupFixed');
    var markupPercent = document.getElementById('markupPercent');
    var searchUrl = @json(route('ext.shopee.products.search_catalog'));
    var pushBaseUrl = @json(url('/shopee/products'));
    var debounceTimer = null;

    if (!searchInput || !resultsDiv || !hiddenId || !btn) return;

    searchInput.addEventListener('input', function() {
        var q = searchInput.value.trim();
        if (q.length < 2) {
            resultsDiv.style.display = 'none';
            resultsDiv.innerHTML = '';
            return;
        }

        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(function() {
            fetch(searchUrl + '?q=' + encodeURIComponent(q))
                .then(function(r) { return r.json(); })
                .then(function(items) {
                    if (!items.length) {
                        resultsDiv.innerHTML = '<div style="padding:10px 12px; font-size:13px; color:var(--text-muted);">No products found</div>';
                        resultsDiv.style.display = 'block';
                        return;
                    }
                    resultsDiv.innerHTML = items.map(function(item) {
                        var imgHtml = item.image ? '<img src="' + item.image + '" alt="">' : '<div style="width:32px;height:32px;background:var(--border-light);border-radius:3px;flex-shrink:0;"></div>';
                        var optsHtml = '';
                        if (item.options && item.options.length) {
                            optsHtml = item.options.map(function(o) {
                                return '<span style="font-size:10px; color:var(--text-muted);">' + (o.name ? o.name + ' - ' : '') + o.sku + '</span>';
                            }).join(', ');
                            optsHtml = '<div style="margin-top:1px;">' + optsHtml + '</div>';
                        }
                        return '<div class="product-result-item" data-pid="' + item.product_id + '" data-name="' + item.name.replace(/"/g, '&quot;') + '" data-sku="' + (item.sku || '') + '" data-model="' + (item.model || '') + '" data-image="' + (item.image || '') + '">'
                            + imgHtml
                            + '<div class="pr-info">'
                            + '<div class="pr-name">' + item.name + '</div>'
                            + '<div class="pr-meta">'
                            + (item.model ? 'Model: ' + item.model : '') + (item.sku && item.sku !== item.model ? (item.model ? ' / ' : '') + 'SKU: ' + item.sku : '') + ' | ID: ' + item.product_id
                            + '</div>'
                            + optsHtml
                            + '</div></div>';
                    }).join('');
                    resultsDiv.style.display = 'block';
                })
                .catch(function() {
                    resultsDiv.style.display = 'none';
                });
        }, 300);
    });

    resultsDiv.addEventListener('click', function(e) {
        var item = e.target.closest('.product-result-item');
        if (!item) return;
        var pid = item.dataset.pid;
        var name = item.dataset.name;
        var sku = item.dataset.sku;
        var model = item.dataset.model;
        var image = item.dataset.image;

        hiddenId.value = pid;
        searchInput.value = name + ' (#' + pid + ')';
        resultsDiv.style.display = 'none';
        btn.disabled = false;

        // Show selected product info
        var infoHtml = '<div class="d-flex items-center gap-8">';
        if (image) infoHtml += '<img src="' + image + '" alt="" style="width:48px; height:48px; object-fit:cover; border-radius:4px; flex-shrink:0;">';
        infoHtml += '<div>';
        infoHtml += '<div class="font-bold">' + name + '</div>';
        infoHtml += '<div class="text-muted text-xs">';
        if (sku) infoHtml += 'SKU: ' + sku + ' ';
        if (model && model !== sku) infoHtml += 'Model: ' + model + ' ';
        infoHtml += 'ID: ' + pid;
        infoHtml += '</div></div></div>';
        infoDiv.innerHTML = infoHtml;
        infoDiv.style.display = 'block';
    });

    // Close results on outside click
    document.addEventListener('click', function(e) {
        if (!searchInput.contains(e.target) && !resultsDiv.contains(e.target)) {
            resultsDiv.style.display = 'none';
        }
    });

    // Clear selection when user edits search text
    searchInput.addEventListener('focus', function() {
        if (hiddenId.value) {
            searchInput.value = '';
            hiddenId.value = '';
            btn.disabled = true;
            infoDiv.style.display = 'none';
        }
    });

    btn.addEventListener('click', function() {
        var pid = hiddenId.value;
        if (!pid) return;
        var params = [];
        var catId = categorySelect ? categorySelect.value : '';
        var mf = markupFixed ? markupFixed.value : '';
        var mp = markupPercent ? markupPercent.value : '';
        if (catId) params.push('category_id=' + encodeURIComponent(catId));
        if (mf) params.push('markup_fixed=' + encodeURIComponent(mf));
        if (mp) params.push('markup_percent=' + encodeURIComponent(mp));
        var url = pushBaseUrl + '/' + pid + '/push';
        if (params.length) url += '?' + params.join('&');
        window.location.href = url;
    });
})();
</script>
@endpush
@endsection
