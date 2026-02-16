@extends('layouts.app')

@section('title', 'Lazada Listings')

@section('content')
    <div class="page-head">
        <div>
            <h1>Lazada Listings</h1>
            <div class="muted">Phase 2: map ERP products to Lazada category + required attributes.</div>
        </div>
        <div class="page-actions" style="display:flex; gap:8px; align-items:center;">
            <a class="btn" href="{{ route('lazada.index') }}">Back to Lazada</a>
            <a class="btn" href="{{ route('lazada.listings.create') }}">Add Listing</a>
        </div>
    </div>

    @if(session('status'))
        <div class="alert success">{{ session('status') }}</div>
    @endif

    <div class="card">
        <div class="card-body">
            @php
                // Defensive: if routes are still cached from an older release during deployment,
                // Route::has(...) prevents a 500 on this page.
                $bulkUploadUrl = \Illuminate\Support\Facades\Route::has('lazada.listings.bulk_upload')
                    ? route('lazada.listings.bulk_upload')
                    : url('/lazada/listings/bulk/upload');
                $bulkSyncQtyUrl = \Illuminate\Support\Facades\Route::has('lazada.listings.bulk_sync_quantity')
                    ? route('lazada.listings.bulk_sync_quantity')
                    : url('/lazada/listings/bulk/sync/quantity');
                $bulkSyncPriceUrl = \Illuminate\Support\Facades\Route::has('lazada.listings.bulk_sync_price')
                    ? route('lazada.listings.bulk_sync_price')
                    : url('/lazada/listings/bulk/sync/price');
            @endphp

            <div style="display:flex; justify-content:flex-end; gap:8px; margin-bottom:10px; flex-wrap:wrap;">
                <form method="POST" action="{{ $bulkUploadUrl }}" class="bulk-action-form" style="margin:0;">
                    @csrf
                    <button type="submit" class="btn small">Upload</button>
                </form>
                <form method="POST" action="{{ $bulkSyncQtyUrl }}" class="bulk-action-form" style="margin:0;">
                    @csrf
                    <button type="submit" class="btn small">Sync Qty</button>
                </form>
                <form method="POST" action="{{ $bulkSyncPriceUrl }}" class="bulk-action-form" style="margin:0;">
                    @csrf
                    <button type="submit" class="btn small">Sync Price</button>
                </form>
            </div>

<table class="table" id="lazadaListingsTable">
    <thead>
    <tr>
        <th style="width:60px;">
            <label style="display:flex; align-items:center; gap:8px;">
                <input type="checkbox" id="checkAll" />
                <span>ID</span>
            </label>
        </th>
        <th style="width:70px;">Photo</th>
        <th>Product</th>
        <th style="width:170px;">Lazada Item ID</th>
        <th style="width:110px;">Quantity</th>
        <th style="width:120px;">Product Status</th>
        <th style="width:130px;">Lazada Status</th>
        <th style="width:190px;">Actions</th>
        <th style="width:70px;">Error</th>
    </tr>
    </thead>
    <tbody>
    @forelse($listings as $l)
        @php
            $p = $productsById->get($l->product_id);
            $thumb = $productThumbsById->get($l->product_id);
            $pName = $p ? html_entity_decode((string)($p->name ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8') : '';
            $pSku = $p ? (string)($p->sku ?? '') : '';
            $pModel = $p ? (string)($p->model ?? '') : '';
            $pQty = $p ? (int)($p->quantity ?? 0) : null;
            $pStatus = $p ? (int)($p->status ?? 0) : null;
            $erpStatusLabel = $p === null ? '-' : ($pStatus ? 'Enabled' : 'Disabled');

            $isDeleted = !is_null($l->lazada_deleted_at);
            $hasItemId = !is_null($l->lazada_item_id) && (string)$l->lazada_item_id !== '';
            if ($hasItemId) {
                $lazadaStatusLabel = 'Uploaded';
            } elseif ($isDeleted) {
                $lazadaStatusLabel = 'Deleted';
            } else {
                $lazadaStatusLabel = 'Not Uploaded';
            }

            $hasSyncInfo = !is_null($l->last_synced_at) || !is_null($l->last_sync_ok) || !is_null($l->last_sync_error_code);
            $hasError = $hasSyncInfo && !empty($l->last_sync_error_code);
            $errCode = (string)($l->last_sync_error_code ?? '');
            $errMsg = (string)($l->last_sync_error_message ?? '');
        @endphp
        <tr>
            <td>
                <label style="display:flex; align-items:center; gap:8px;">
                    <input type="checkbox" class="row-check" value="{{ $l->id }}" />
                    <span>{{ $l->id }}</span>
                </label>
            </td>
            <td>
                @if($thumb)
                    <img src="{{ $thumb }}" alt="" style="width:44px; height:44px; object-fit:contain; border:1px solid #e5e7eb; border-radius:6px; background:#fff;" />
                @else
                    <span class="muted">-</span>
                @endif
            </td>
            <td>
                <div><strong>{{ $pName ?: '-' }}</strong></div>
                <div class="muted" style="font-size: 12px;">@if($pSku)SKU: {{ $pSku }}@endif</div>
            </td>
            <td>
                <div>{{ $l->lazada_item_id ?? '-' }}</div>
                <div class="muted" style="font-size:12px;">{{ $l->product_id }}</div>
            </td>
            <td>{{ is_null($pQty) ? '-' : $pQty }}</td>
            <td>{{ $erpStatusLabel }}</td>
            <td>{{ $lazadaStatusLabel }}</td>
            <td>
                <div style="display:flex; gap:6px; align-items:center; flex-wrap:nowrap; white-space:nowrap;">
                    <a class="btn small" href="{{ route('lazada.listings.edit', $l->id) }}">Edit</a>

                    <form method="POST" action="{{ route('lazada.listings.upload', $l->id) }}" style="margin:0;" onsubmit="return confirm('Upload this product to Lazada?');">
                        @csrf
                        <button type="submit" class="btn small">Upload</button>
                    </form>

                    <form method="POST" action="{{ route('lazada.listings.delete_lazada', $l->id) }}" style="margin:0;" onsubmit="return confirm('Delete this product from Lazada? This cannot be undone.');">
                        @csrf
                        <button type="submit" class="btn small">Delete</button>
                    </form>

                    <form method="POST" action="{{ route('lazada.listings.sync_quantity', $l->id) }}" style="margin:0;">
                        @csrf
                        <button type="submit" class="btn small">Sync Qty</button>
                    </form>

                    <form method="POST" action="{{ route('lazada.listings.sync_price', $l->id) }}" style="margin:0;">
                        @csrf
                        <button type="submit" class="btn small">Sync Price</button>
                    </form>
                </div>
            </td>
            <td style="text-align:center;">
                @if(!$hasSyncInfo)
                    <span class="muted">-</span>
                @elseif($hasError)
                    <span title="{{ $errCode }} - {{ $errMsg }}" style="cursor:help; font-size:16px;">⚠️</span>
                @else
                    <span title="OK" style="cursor:help; font-size:16px;">✅</span>
                @endif
            </td>
        </tr>
    @empty
        <tr>
            <td colspan="9" class="muted">No listings yet.</td>
        </tr>
    @endforelse
    </tbody>
</table>

<script>
    (function(){
        const table = document.getElementById('lazadaListingsTable');
        if (!table) return;

        const checkAll = document.getElementById('checkAll');
        const rowChecks = () => Array.from(table.querySelectorAll('.row-check'));

        function updateCheckAllState(){
            const boxes = rowChecks();
            const checked = boxes.filter(b => b.checked).length;
            if (!checkAll) return;
            if (checked === 0) {
                checkAll.indeterminate = false;
                checkAll.checked = false;
            } else if (checked === boxes.length) {
                checkAll.indeterminate = false;
                checkAll.checked = true;
            } else {
                checkAll.indeterminate = true;
                checkAll.checked = false;
            }
        }

        if (checkAll) {
            checkAll.addEventListener('change', function(){
                const boxes = rowChecks();
                boxes.forEach(b => { b.checked = checkAll.checked; });
                updateCheckAllState();
            });
        }

        rowChecks().forEach(b => b.addEventListener('change', updateCheckAllState));

        function getSelectedIds(){
            return rowChecks().filter(b => b.checked).map(b => b.value);
        }

        document.querySelectorAll('form.bulk-action-form').forEach(form => {
            form.addEventListener('submit', function(e){
                form.querySelectorAll('input[name="listing_ids[]"]').forEach(n => n.remove());

                const ids = getSelectedIds();
                if (!ids.length) {
                    e.preventDefault();
                    alert('Please select at least 1 listing.');
                    return;
                }

                ids.forEach(id => {
                    const inp = document.createElement('input');
                    inp.type = 'hidden';
                    inp.name = 'listing_ids[]';
                    inp.value = id;
                    form.appendChild(inp);
                });
            });
        });
    })();
</script>
        </div>
    </div>
@endsection
