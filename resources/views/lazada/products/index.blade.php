@extends('layouts.app')

@section('title', 'Lazada Products')

@section('content')
    <div class="page-head">
        <div>
            <h1>Lazada Products</h1>
            <div class="muted">Phase 2: map ERP products to Lazada category + required attributes.</div>
        </div>
        <div class="page-actions" style="display:flex; gap:8px; align-items:center;">
            <a class="btn" href="{{ route('lazada.index') }}">Back to Lazada</a>
            <a class="btn" href="{{ route('lazada.products.create') }}">Add Product</a>
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
                $bulkUploadUrl = \Illuminate\Support\Facades\Route::has('lazada.products.bulk_upload')
                    ? route('lazada.products.bulk_upload')
                    : url('/lazada/listings/bulk/upload');
                $bulkSyncQtyUrl = \Illuminate\Support\Facades\Route::has('lazada.products.bulk_sync_quantity')
                    ? route('lazada.products.bulk_sync_quantity')
                    : url('/lazada/listings/bulk/sync/quantity');
                $bulkSyncPriceUrl = \Illuminate\Support\Facades\Route::has('lazada.products.bulk_sync_price')
                    ? route('lazada.products.bulk_sync_price')
                    : url('/lazada/listings/bulk/sync/price');
                $bulkDeleteUrl = \Illuminate\Support\Facades\Route::has('lazada.products.bulk_delete')
                    ? route('lazada.products.bulk_delete')
                    : url('/lazada/products/bulk/delete');
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
                <form method="POST" action="{{ $bulkDeleteUrl }}" class="bulk-action-form bulk-delete-form" style="margin:0;">
                    @csrf
                    <button type="submit" class="btn small" style="background:#dc2626; color:#fff;">Delete</button>
                </form>
            </div>

<table class="table" id="lazadaListingsTable">
    <thead>
    <tr>
        @php
            $sort = $sort ?? 'id';
            $dir = $dir ?? 'desc';
            $toggleDir = function(string $col) use ($sort, $dir) {
                if ($sort !== $col) return 'asc';
                return $dir === 'asc' ? 'desc' : 'asc';
            };
            $sortIcon = function(string $col) use ($sort, $dir) {
                if ($sort !== $col) return '';
                return $dir === 'asc' ? ' ▲' : ' ▼';
            };
            $sortUrl = function(string $col) use ($toggleDir) {
                $q = request()->query();
                $q['sort'] = $col;
                $q['dir'] = $toggleDir($col);
                return url()->current() . '?' . http_build_query($q);
            };
        @endphp

        <th style="width:60px;">
            <label style="display:flex; align-items:center; gap:8px;">
                <input type="checkbox" id="checkAll" />
                <a href="{{ $sortUrl('id') }}" style="text-decoration:none; color:inherit;">ID{!! $sortIcon('id') !!}</a>
            </label>
        </th>
        <th style="width:70px;">Photo</th>
        <th><a href="{{ $sortUrl('product') }}" style="text-decoration:none; color:inherit;">Product{!! $sortIcon('product') !!}</a></th>
        <th style="width:170px;"><a href="{{ $sortUrl('lazada_item_id') }}" style="text-decoration:none; color:inherit;">Lazada Item ID{!! $sortIcon('lazada_item_id') !!}</a></th>
        <th style="width:110px;"><a href="{{ $sortUrl('quantity') }}" style="text-decoration:none; color:inherit;">Quantity{!! $sortIcon('quantity') !!}</a></th>
        <th style="width:120px;"><a href="{{ $sortUrl('product_status') }}" style="text-decoration:none; color:inherit;">Product Status{!! $sortIcon('product_status') !!}</a></th>
        <th style="width:130px;"><a href="{{ $sortUrl('lazada_status') }}" style="text-decoration:none; color:inherit;">Lazada Status{!! $sortIcon('lazada_status') !!}</a></th>
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
                    <span>{{ $l->product_id ?? '-' }}</span>
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
                {{--
                    Keep product name/SKU on a single line with ellipsis.
                    This prevents option rows from shifting vertically when the browser is narrower,
                    keeping option quantities aligned with option text across columns.
                --}}
                <div style="font-weight:700; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:100%;">{{ $pName ?: '-' }}</div>
                <div class="muted" style="font-size: 12px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:100%;">@if($pSku)SKU: {{ $pSku }}@endif</div>
                @php
                    $optRows = isset($optionRowsByProductId) ? $optionRowsByProductId->get($l->product_id) : null;
                @endphp
                @if($optRows && count($optRows) > 0)
                    <div style="margin-top:6px; font-size:14px;">
                        <ul style="margin:0; padding-left:16px;">
                            @foreach($optRows as $or)
                                @php
                                    $ovn = (string)($or->option_value_name ?? '');
                                    $osku = (string)($or->option_sku ?? '');
                                @endphp
                                <li style="margin:0 0 3px 0; font-weight:600;">
                                    {{ $ovn ?: '-' }}@if($osku) <span class="muted" style="font-weight:600;">— {{ $osku }}</span>@endif
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </td>
            <td>
                <div>{{ $l->lazada_item_id ?? '-' }}</div>
            </td>
            <td>
                <div>{{ is_null($pQty) ? '-' : $pQty }}</div>
                @if(isset($optRows) && $optRows && count($optRows) > 0)
                    <div style="margin-top:6px; font-size:14px; line-height:1.4;">
                        <ul style="margin:0; padding-left:16px;">
                            @foreach($optRows as $or)
                                @php
                                    // Quantity-only display under the Quantity column.
                                    // Do NOT show option value/SKU text here (it is already shown under Product).
                                    $oqtyRaw = $or->option_quantity ?? null;
                                    $oqty = is_null($oqtyRaw) ? '-' : (is_numeric($oqtyRaw) ? (string)((int)$oqtyRaw) : (string)$oqtyRaw);
                                @endphp
                                <li style="margin:0 0 3px 0; font-weight:600;">
                                    {{ $oqty }}
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </td>
            <td>
                @if($erpStatusLabel === 'Enabled')
                    <span style="display:inline-block; padding:2px 8px; border-radius:999px; font-size:12px; font-weight:600; background:#dcfce7; color:#166534;">Enabled</span>
                @elseif($erpStatusLabel === 'Disabled')
                    <span style="display:inline-block; padding:2px 8px; border-radius:999px; font-size:12px; font-weight:600; background:#fee2e2; color:#991b1b;">Disabled</span>
                @else
                    <span class="muted">-</span>
                @endif
            </td>
            <td>
                @if($lazadaStatusLabel === 'Uploaded')
                    <span style="display:inline-block; padding:2px 8px; border-radius:999px; font-size:12px; font-weight:600; background:#dcfce7; color:#166534;">Uploaded</span>
                @elseif($lazadaStatusLabel === 'Not Uploaded')
                    <span style="display:inline-block; padding:2px 8px; border-radius:999px; font-size:12px; font-weight:600; background:#fee2e2; color:#991b1b;">Not Uploaded</span>
                @elseif($lazadaStatusLabel === 'Deleted')
                    <span style="display:inline-block; padding:2px 8px; border-radius:999px; font-size:12px; font-weight:600; background:#e5e7eb; color:#374151;">Deleted</span>
                @else
                    <span class="muted">-</span>
                @endif
            </td>
            <td>
                <div style="display:flex; gap:6px; align-items:center; flex-wrap:nowrap; white-space:nowrap;">
                    <a class="btn small" href="{{ route('lazada.products.edit', $l->id) }}">Edit</a>

                    <form method="POST" action="{{ route('lazada.products.upload', $l->id) }}" style="margin:0;" onsubmit="return confirm('Upload this product to Lazada?');">
                        @csrf
                        <button type="submit" class="btn small">Upload</button>
                    </form>

                    @if(empty($l->lazada_item_id))
                    <form method="POST" action="{{ route('lazada.products.sync_lazada_id', $l->id) }}" style="margin:0;">
                        @csrf
                        <button type="submit" class="btn small">Sync Lazada ID</button>
                    </form>
                    @endif

                    <form method="POST" action="{{ route('lazada.products.delete_lazada', $l->id) }}" style="margin:0;" onsubmit="return confirm('Delete this product from Lazada? This cannot be undone.');">
                        @csrf
                        <button type="submit" class="btn small">Delete</button>
                    </form>

                    <form method="POST" action="{{ route('lazada.products.sync_quantity', $l->id) }}" style="margin:0;">
                        @csrf
                        <button type="submit" class="btn small">Sync Qty</button>
                    </form>

                    <form method="POST" action="{{ route('lazada.products.sync_price', $l->id) }}" style="margin:0;">
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
            <td colspan="9" class="muted">No products yet.</td>
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

                if (form.classList.contains('bulk-delete-form')) {
                    if (!confirm('Delete ' + ids.length + ' selected product(s) from Lazada? This cannot be undone.')) {
                        e.preventDefault();
                        return;
                    }
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
