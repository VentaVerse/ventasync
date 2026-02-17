@extends('layouts.app')
@section('breadcrumb', 'Marketplace / Lazada / Products')

@section('title', 'Lazada Products')

@section('content')
    <div class="page-header">
        <div>
            <h2>Lazada Products</h2>
            <div class="text-muted text-sm">Map ERP products to Lazada category + required attributes.</div>
        </div>
        <div class="page-header-actions">
            <a class="btn" href="{{ route('lazada.profiles.index') }}">Profiles</a>
            <a class="btn" href="{{ route('lazada.index') }}">Back to Lazada</a>
            <a class="btn" href="{{ route('lazada.products.create') }}">Add Product</a>
        </div>
    </div>

    @if(session('status'))
        <div class="alert success">{{ session('status') }}</div>
    @endif

    <div class="card">
        {{-- Search & Filter toolbar --}}
        <form method="GET" action="{{ route('lazada.products.index') }}" class="d-flex gap-8 items-end flex-wrap mb-12">
            <div>
                <label class="text-xs text-muted">Search</label>
                <input type="text" name="q" class="input" value="{{ $q ?? '' }}" placeholder="Product name or SKU..." style="min-width:220px;">
            </div>
            <div>
                <label class="text-xs text-muted">Profile</label>
                <select name="profile" class="input" style="min-width:150px;">
                    <option value="all" {{ ($profileFilter ?? 'all') === 'all' ? 'selected' : '' }}>All Profiles</option>
                    <option value="none" {{ ($profileFilter ?? '') === 'none' ? 'selected' : '' }}>No Profile</option>
                    @foreach(($allProfiles ?? collect()) as $pId => $pName)
                        <option value="{{ $pId }}" {{ (string)($profileFilter ?? '') === (string)$pId ? 'selected' : '' }}>{{ $pName }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="text-xs text-muted">Lazada Status</label>
                <select name="lazada_status" class="input" style="min-width:150px;">
                    <option value="all" {{ ($lazadaStatus ?? 'all') === 'all' ? 'selected' : '' }}>All</option>
                    <option value="not_uploaded" {{ ($lazadaStatus ?? '') === 'not_uploaded' ? 'selected' : '' }}>Not Uploaded</option>
                    <option value="uploaded" {{ ($lazadaStatus ?? '') === 'uploaded' ? 'selected' : '' }}>Uploaded</option>
                    <option value="deleted" {{ ($lazadaStatus ?? '') === 'deleted' ? 'selected' : '' }}>Deleted</option>
                </select>
            </div>
            {{-- Preserve sort --}}
            @if(!empty($sort))<input type="hidden" name="sort" value="{{ $sort }}">@endif
            @if(!empty($dir))<input type="hidden" name="dir" value="{{ $dir }}">@endif
            <button type="submit" class="btn small">Filter</button>
            @if(($q ?? '') !== '' || ($lazadaStatus ?? 'all') !== 'all' || ($profileFilter ?? 'all') !== 'all')
                <a href="{{ route('lazada.products.index') }}" class="btn small">Clear</a>
            @endif
        </form>

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

        <div class="d-flex justify-end gap-8 flex-wrap mb-12">
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
                <button type="submit" class="btn small danger">Delete</button>
            </form>
        </div>

        <div class="table-wrap">
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
                $qp = request()->query();
                $qp['sort'] = $col;
                $qp['dir'] = $toggleDir($col);
                unset($qp['page']);
                return url()->current() . '?' . http_build_query($qp);
            };
        @endphp

        <th style="width:60px;">
            <label class="d-flex items-center gap-8">
                <input type="checkbox" id="checkAll" />
                <a href="{{ $sortUrl('id') }}" style="text-decoration:none; color:inherit;">ID{!! $sortIcon('id') !!}</a>
            </label>
        </th>
        <th style="width:70px;">Photo</th>
        <th><a href="{{ $sortUrl('product') }}" style="text-decoration:none; color:inherit;">Product{!! $sortIcon('product') !!}</a></th>
        <th style="width:130px;"><a href="{{ $sortUrl('profile') }}" style="text-decoration:none; color:inherit;">Profile{!! $sortIcon('profile') !!}</a></th>
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
                <label class="d-flex items-center gap-8">
                    <input type="checkbox" class="row-check" value="{{ $l->id }}" />
                    <span>{{ $l->product_id ?? '-' }}</span>
                </label>
            </td>
            <td>
                @if($thumb)
                    <img src="{{ $thumb }}" alt="" class="thumb thumb-sm" />
                @else
                    <span class="text-muted">-</span>
                @endif
            </td>
            <td>
                <div class="font-bold truncate" style="max-width:100%;">{{ $pName ?: '-' }}</div>
                <div class="text-muted text-xs truncate" style="max-width:100%;">@if($pSku)SKU: {{ $pSku }}@endif</div>
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
                                <li class="font-semibold" style="margin:0 0 3px 0;">
                                    {{ $ovn ?: '-' }}@if($osku) <span class="text-muted font-semibold">— {{ $osku }}</span>@endif
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </td>
            <td>
                @if($l->lazada_profile_id && isset($profileNames) && $profileNames->has($l->lazada_profile_id))
                    <a href="{{ route('lazada.profiles.edit', $l->lazada_profile_id) }}" class="text-xs">{{ $profileNames->get($l->lazada_profile_id) }}</a>
                @else
                    <span class="text-muted text-xs">-</span>
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
                                    $oqtyRaw = $or->option_quantity ?? null;
                                    $oqty = is_null($oqtyRaw) ? '-' : (is_numeric($oqtyRaw) ? (string)((int)$oqtyRaw) : (string)$oqtyRaw);
                                @endphp
                                <li class="font-semibold" style="margin:0 0 3px 0;">
                                    {{ $oqty }}
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </td>
            <td>
                @if($erpStatusLabel === 'Enabled')
                    <span class="badge badge-green">Enabled</span>
                @elseif($erpStatusLabel === 'Disabled')
                    <span class="badge badge-red">Disabled</span>
                @else
                    <span class="text-muted">-</span>
                @endif
            </td>
            <td>
                @if($lazadaStatusLabel === 'Uploaded')
                    <span class="badge badge-green">Uploaded</span>
                @elseif($lazadaStatusLabel === 'Not Uploaded')
                    <span class="badge badge-red">Not Uploaded</span>
                @elseif($lazadaStatusLabel === 'Deleted')
                    <span class="badge badge-gray">Deleted</span>
                @else
                    <span class="text-muted">-</span>
                @endif
            </td>
            <td>
                <div class="d-flex gap-6 items-center text-nowrap">
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
            <td class="text-center">
                @if(!$hasSyncInfo)
                    <span class="text-muted">-</span>
                @elseif($hasError)
                    <span title="{{ $errCode }} - {{ $errMsg }}" style="cursor:help; font-size:16px;">⚠️</span>
                @else
                    <span title="OK" style="cursor:help; font-size:16px;">✅</span>
                @endif
            </td>
        </tr>
    @empty
        <tr>
            <td colspan="10" class="text-muted">No products found.</td>
        </tr>
    @endforelse
    </tbody>
</table>
        </div>

        @if(isset($paginator) && $paginator->lastPage() > 1)
            <div class="d-flex justify-center mt-16">
                {{ $paginator->links() }}
            </div>
        @endif

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
@endsection
