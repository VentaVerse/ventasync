@extends('layouts.channel')
@section('breadcrumb', 'Settings')

@section('title', 'Venta Settings')

{{-- Leaves the .x-legacy content boundary, rebuilt on the token set.

     Venta is multi-store and this page is store-scoped:
     VentaController::showSettings() findOrFail()s the {store} segment and
     hands the view a one-element $stores collection with $singleStore = true.
     Every form on this page therefore carries that one store's id, either as
     the hidden `store_id` the save reads or as a route parameter.

     Venta has NO OAuth surface. It authenticates with a stored API token, so
     nothing here is a registered redirect and nothing is exempt from the
     /channels prefix.

     The API token field below is always sent blank, both tiers:
     VentaController::save() now keeps the stored token when the field posts
     empty, the same blank-keeps-stored branch Shopee's and TikTok's save()
     already carry, so the view no longer needs to echo the secret to mask
     it in the DOM. --}}
@section('content')
@php
    // Read tier on this route is `view_venta|manage_venta`; every write below
    // (save, delete, test, fetch statuses, save mapping, logging toggle, log
    // delete) is `manage_venta`. Replaces a hardcoded `true`, which offered a
    // read-only operator every one of those controls.
    $canManageVenta = auth()->user()?->hasPermission('manage_venta/settings') ?? false;

    // showSettings() always hands over exactly one store and $singleStore =
    // true, so the pre-redesign store-selector rail and the "Add New Store"
    // panel were both unreachable markup on this route. A new store is created
    // from the modal on the workspace overview page, which posts to
    // ext.venta.stores.store. Both are gone rather than redrawn.
    $store = $stores->first();

    $storeName = $store->store_name ?: 'Unnamed store';
    $storeHost = parse_url((string) $store->base_url, PHP_URL_HOST) ?: $store->base_url;

    $statusMap = $orderStatusMaps->get($store->id, collect());
    $storeApiLogs = ($apiLogs ?? collect())->where('venta_setting_id', $store->id);
    $storeSyncLogs = $recentLogs->where('venta_setting_id', $store->id);

    $apiLogCount = $storeApiLogs->count();
    $apiLogging = (bool) $store->api_logging;

    // The option list the fetch-statuses handler rebuilds its selects from.
    // Data, not behaviour: JSON_HEX_TAG encodes < and > so a status literally
    // named "</script>" cannot close the element.
    $jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
    $erpStatusOptions = $erpOrderStatuses->map(fn ($s) => ['id' => (int) $s->order_status_id, 'name' => (string) $s->name])->values();
@endphp

<div class="cs-page"
     data-channel-settings
     data-store-settings
     data-store-id="{{ $store->id }}"
     data-tab-prefix="vt-tab-"
     data-tab-storage-key="vt-active-tab"
     data-test-url="{{ $canManageVenta ? route('ext.venta.test') : '' }}"
     data-fetch-statuses-url="{{ $canManageVenta ? route('ext.venta.fetch_statuses') : '' }}"
     data-save-map-url="{{ $canManageVenta ? route('ext.venta.save_status_map') : '' }}"
     {{-- fetchVentaStatuses() answers with `id` for the Venta status id and
          `order_status_id` for what it is already mapped to. OpenCart's fetch
          uses a different pair of keys for the same two things, which is why
          both are stated rather than assumed. --}}
     data-map-id-field="venta_status_id"
     data-map-name-field="venta_status_name"
     data-map-server-id="id"
     data-map-server-key="order_status_id"
     data-channel-label="Venta">

<div class="x-list-head">
    <div>
        <h1 class="x-page-title">Settings</h1>
        <p class="x-page-sub">{{ $storeName }} at <span class="cs-code">{{ $storeHost }}</span></p>
    </div>
    <div class="cs-state">
        @if($store->enabled)
            <x-ui.badge tone="success">Syncing</x-ui.badge>
        @else
            <x-ui.badge tone="neutral">Paused</x-ui.badge>
        @endif
    </div>
</div>

@unless($canManageVenta)
<div class="cs-readonly">
    <div>
        <strong>You can look, not change.</strong>
        The connection, status mapping and both logs are readable with the Venta view permission. Editing the connection, mapping statuses and clearing logs need the manage tier. The API token is not shown.
    </div>
</div>
@endunless

{{-- Five views of one subject, so this is the same segmented control the
     orders pages use for their status filters rather than a second tab
     grammar. --}}
<div class="x-segment-bar">
    <div class="x-segment" role="tablist" aria-label="Settings sections">
        <button type="button" class="x-segment__item is-active" role="tab" aria-selected="true" id="vt-tab-connection-tab" aria-controls="vt-tab-connection" data-cs-tab="vt-tab-connection">Connection</button>
        <button type="button" class="x-segment__item" role="tab" aria-selected="false" id="vt-tab-status-tab" aria-controls="vt-tab-status" data-cs-tab="vt-tab-status">Status mapping</button>
        <button type="button" class="x-segment__item" role="tab" aria-selected="false" id="vt-tab-automations-tab" aria-controls="vt-tab-automations" data-cs-tab="vt-tab-automations">Automations</button>
        <button type="button" class="x-segment__item" role="tab" aria-selected="false" id="vt-tab-logs-tab" aria-controls="vt-tab-logs" data-cs-tab="vt-tab-logs">
            <span>API log</span>
            @if($apiLogCount > 0)<span class="x-segment__count">{{ number_format($apiLogCount) }}</span>@endif
        </button>
        <button type="button" class="x-segment__item" role="tab" aria-selected="false" id="vt-tab-sync-tab" aria-controls="vt-tab-sync" data-cs-tab="vt-tab-sync">Sync log</button>
    </div>
</div>

{{-- ================================================================
     CONNECTION
     ================================================================ --}}
<div id="vt-tab-connection" class="cs-panel" role="tabpanel" aria-labelledby="vt-tab-connection-tab">

    @if($store->enabled)
    <section class="fm-section">
        <div class="fm-section__head">
            <h2 class="fm-section__title">Last run</h2>
        </div>
        <div class="cs-stats">
            <div class="cs-stat">
                <span class="cs-stat__k">Orders pulled</span>
                <span class="cs-stat__v">{{ $store->last_order_sync_at ? $store->last_order_sync_at->diffForHumans() : 'Never' }}</span>
            </div>
            <div class="cs-stat">
                <span class="cs-stat__k">Products pushed</span>
                <span class="cs-stat__v">{{ $store->last_product_sync_at ? $store->last_product_sync_at->diffForHumans() : 'Never' }}</span>
            </div>
            <div class="cs-stat">
                <span class="cs-stat__k">Stock pushed</span>
                <span class="cs-stat__v">{{ $store->last_stock_push_at ? $store->last_stock_push_at->diffForHumans() : 'Never' }}</span>
            </div>
        </div>
    </section>
    @endif

    <div class="cs-grid">
        <section class="fm-section">
            <div class="fm-section__head">
                <h2 class="fm-section__title">This store</h2>
            </div>

            <form method="POST" action="{{ route('ext.venta.save') }}">
                @csrf
                {{-- The store this page is scoped to. VentaController::save()
                     branches on its presence: with it the row is updated, and
                     without it a new store is created. It is always present
                     here, which is what makes store 1's save unable to touch
                     store 2's row. --}}
                <input type="hidden" name="store_id" value="{{ $store->id }}">

                <div class="fm-fields">
                    <x-ui.field label="Store name" for="vt-store-name" name="store_name" wide
                                hint="What this store is called in the ERP. It is never sent to the store itself.">
                        <x-ui.input id="vt-store-name" name="store_name" value="{{ $store->store_name }}"
                                    placeholder="e.g. Main store" :disabled="!$canManageVenta" />
                    </x-ui.field>

                    <x-ui.field label="Base URL" for="vt-base-url" name="base_url" :required="true" wide
                                hint="The store's address, with no trailing slash.">
                        <x-ui.input id="vt-base-url" name="base_url" value="{{ $store->base_url }}"
                                    placeholder="https://store.example.com" :disabled="!$canManageVenta" />
                    </x-ui.field>

                    <x-ui.credential
                        name="api_token"
                        id="vt-api-token"
                        label="API token"
                        channel="venta"
                        :store="$store->id"
                        hint="Stored encrypted. Read-only until you choose to edit it."
                        :length="\App\Support\Credentials::length($store->api_token ?? null)"
                        :can-reveal="$canManageVenta"
                        :disabled="!$canManageVenta" />

                    <x-ui.field label="Colour" for="vt-brand-color" name="brand_color"
                                hint="Used wherever this store appears in a chart or a report.">
                        <div class="cs-colour" data-colour-pair>
                            {{-- Only the colour input carries the name: the hex
                                 box beside it is a second face on the same
                                 value, exactly as before, and posting both
                                 would give the controller two `brand_color`
                                 fields. --}}
                            <input type="color" id="vt-brand-color" name="brand_color" class="cs-colour__swatch"
                                   value="{{ $store->brand_color ?: '#059669' }}" data-colour-input
                                   @disabled(!$canManageVenta) aria-label="Store colour">
                            <x-ui.input class="cs-colour__hex fm-input--num" maxlength="7" data-colour-hex
                                        value="{{ $store->brand_color ?: '#059669' }}"
                                        aria-label="Store colour as a hex value" :disabled="!$canManageVenta" />
                        </div>
                    </x-ui.field>

                    <x-ui.field label="Warehouse" for="vt-warehouse" name="warehouse_id"
                                hint="Optional. Stock operations for this store read and write that warehouse only.">
                        <x-ui.input id="vt-warehouse" name="warehouse_id" type="number" min="1"
                                    class="fm-input--num" value="{{ $store->warehouse_id }}"
                                    placeholder="Any" :disabled="!$canManageVenta" />
                    </x-ui.field>

                    <x-ui.field label="Pull orders from the last" for="vt-sync-days" name="sync_last_days"
                                hint="A rolling window. It wins over the fixed date below whenever it is set.">
                        <div class="cs-inline">
                            <x-ui.input id="vt-sync-days" name="sync_last_days" type="number" min="1" max="365"
                                        class="fm-input--num cs-days" value="{{ $store->sync_last_days }}"
                                        placeholder="30" :disabled="!$canManageVenta" />
                            <span class="cs-inline__unit">days</span>
                        </div>
                    </x-ui.field>

                    <x-ui.field label="Or from a fixed date" for="vt-sync-from" name="sync_orders_from"
                                hint="Only used when the rolling window above is empty.">
                        <x-ui.input id="vt-sync-from" name="sync_orders_from" type="date"
                                    value="{{ $store->sync_orders_from ? $store->sync_orders_from->format('Y-m-d') : '' }}"
                                    :disabled="!$canManageVenta" />
                    </x-ui.field>

                    <div class="fm-field fm-field--wide">
                        {{-- No hidden companion field, deliberately: save()
                             reads $request->has('enabled'), so a hidden 0 in
                             front of this box would make the store permanently
                             enabled. An unchecked checkbox submits nothing,
                             which is exactly what has() is testing for. --}}
                        <label class="fm-switch">
                            <input type="checkbox" class="fm-switch__input" name="enabled" value="1"
                                   @checked($store->enabled) @disabled(!$canManageVenta)>
                            <span class="fm-switch__track"></span>
                            <span class="fm-switch__text">
                                <span class="fm-switch__label">Syncing</span>
                                <span class="fm-switch__note">Off leaves the store configured but stops every scheduled pull and push.</span>
                            </span>
                        </label>
                    </div>
                </div>

                @if($canManageVenta)
                <div class="cs-actions">
                    <x-ui.button type="submit" variant="primary">Save connection</x-ui.button>
                    <x-ui.button type="button" data-test-connection>Test connection</x-ui.button>
                    <span class="cs-actions__note" data-test-result role="status" aria-live="polite"></span>
                </div>
                @endif
            </form>
        </section>

        <section class="fm-section">
            <div class="fm-section__head">
                <h2 class="fm-section__title">What connects here</h2>
            </div>
            <p class="fm-section__note">The token is issued by the Venta store itself, under its own API settings. It has to allow reading orders and writing products, stock and prices, or the pushes below will come back refused.</p>

            <dl class="cs-facts">
                <div class="cs-fact"><dt>Store</dt><dd>{{ $storeName }}</dd></div>
                <div class="cs-fact"><dt>Store ID</dt><dd class="cs-fact__mono">{{ $store->id }}</dd></div>
                <div class="cs-fact"><dt>Address</dt><dd class="cs-fact__mono">{{ $store->base_url }}</dd></div>
                <div class="cs-fact"><dt>Warehouse</dt><dd class="cs-fact__mono">{{ $store->warehouse_id ?: 'Any' }}</dd></div>
            </dl>

            @if($canManageVenta)
            {{-- Deleting a store is the one irreversible thing on this page, so
                 it sits at the bottom of its own section behind the shared
                 confirm dialog, in the transparent danger treatment. It is
                 never a standing solid red button beside Save. --}}
            <div class="cs-danger">
                <div>
                    <span class="cs-danger__title">Remove this store</span>
                    <p class="cs-danger__note">Deletes the connection and every product link belonging to it. Orders already synced into the ERP stay. Nothing on the Venta store is touched.</p>
                </div>
                <form method="POST" action="{{ route('ext.venta.destroy', $store->id) }}"
                      data-confirm="Delete {{ $storeName }} and all its product links? This cannot be undone.">
                    @csrf
                    @method('DELETE')
                    <x-ui.button type="submit" variant="danger" size="sm">Delete store</x-ui.button>
                </form>
            </div>
            @endif
        </section>
    </div>
</div>

{{-- ================================================================
     STATUS MAPPING
     ================================================================ --}}
<div id="vt-tab-status" class="cs-panel" role="tabpanel" aria-labelledby="vt-tab-status-tab" hidden>
    <section class="fm-section">
        <div class="fm-section__head">
            <h2 class="fm-section__title">Order status mapping</h2>
            @if($canManageVenta && $store->enabled)
            <div class="fm-section__aside">
                <x-ui.button type="button" size="sm" data-fetch-statuses>Fetch statuses from Venta</x-ui.button>
            </div>
            @endif
        </div>
        <p class="fm-section__note">When an order is pulled from {{ $storeName }}, its Venta status becomes the sales order status chosen here. Fetching asks the store what statuses it has; anything already mapped keeps its mapping.</p>

        @unless($store->enabled)
            <x-ui.empty title="Syncing is off for this store"
                        description="Turn syncing on under Connection before mapping statuses. Nothing is pulled from a paused store." />
        @else
            <div data-status-map>
                @if($statusMap->isEmpty())
                    <x-ui.empty title="Nothing mapped yet"
                                :description="$canManageVenta
                                    ? 'Fetch the statuses from the store, then choose what each one becomes here.'
                                    : 'Someone with the manage tier needs to fetch the statuses from the store first.'" />
                @else
                    <x-ui.table>
                        <x-slot:head>
                            <tr>
                                <th scope="col" class="cs-col-src">Venta status</th>
                                <th scope="col">Sales order status</th>
                            </tr>
                        </x-slot:head>
                        @foreach($statusMap as $map)
                            <tr>
                                <td class="cs-col-src" data-label="Venta status">
                                    <span class="cs-code">{{ $map->venta_status_id }}</span>
                                    <span class="cs-map-label">{{ $map->venta_status_name }}</span>
                                </td>
                                <td data-label="Sales order status">
                                    <div class="x-select-wrap cs-map-select">
                                        <select class="x-input" data-map-select
                                                data-map-id="{{ $map->venta_status_id }}"
                                                data-map-name="{{ $map->venta_status_name }}"
                                                aria-label="Sales order status for {{ $map->venta_status_name }}"
                                                @disabled(!$canManageVenta)>
                                            <option value="0">Leave the raw Venta status</option>
                                            @foreach($erpOrderStatuses as $erp)
                                                <option value="{{ $erp->order_status_id }}" @selected((int) $map->order_status_id === (int) $erp->order_status_id)>{{ $erp->name }}</option>
                                            @endforeach
                                        </select>
                                        <x-ui.icon name="chevron-down" size="14" class="x-select-wrap__chevron" />
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </x-ui.table>
                @endif
            </div>

            @if($canManageVenta)
            <div class="cs-actions" data-save-map-bar @if($statusMap->isEmpty()) hidden @endif>
                <x-ui.button type="button" variant="primary" data-save-map>Save mapping</x-ui.button>
            </div>
            @endif

            <script type="application/json" data-erp-statuses>{!! json_encode($erpStatusOptions, $jsonFlags) !!}</script>
        @endunless
    </section>
</div>

{{-- ================================================================
     SCHEDULING
     ================================================================ --}}
<div id="vt-tab-automations" class="cs-panel" role="tabpanel" aria-labelledby="vt-tab-automations-tab" hidden>
    <section class="fm-section">
        <div class="fm-section__head">
            <h2 class="fm-section__title">Scheduled jobs</h2>
            <x-ui.hint label="About the scheduled jobs">How often each Venta sync runs for {{ $storeName }}. Changes take effect the next time the scheduler wakes up.</x-ui.hint>
        </div>

        {{-- The same control Shopee, Lazada and TikTok use.

             This tab used to print three artisan commands to copy into the
             hosting panel by hand, which meant Venta's jobs ran only if the
             operator had pasted each one, and the ERP knew nothing about
             whether they existed, when they last ran, or whether they had
             failed. Every other channel keeps its jobs as scheduled_jobs rows
             that App\Console\Kernel registers with Laravel's scheduler, so one
             OS cron entry drives all of them and this tab edits cadence and
             on/off directly.

             storeId is passed because Venta is multi-store: each store has its
             own three jobs, carrying --store on the command line, so the page
             shows the jobs for the store being looked at and no other. --}}
        @include('partials._automations_tab', ['integration' => 'venta', 'storeId' => $store->id])
    </section>
</div>

{{-- ================================================================
     API LOG
     ================================================================ --}}
<div id="vt-tab-logs" class="cs-panel" role="tabpanel" aria-labelledby="vt-tab-logs-tab" hidden>

    <section class="fm-section">
        <div class="fm-section__head">
            <h2 class="fm-section__title">Recording</h2>
            <div class="fm-section__aside">
                @if($canManageVenta)
                    {{-- A plain POST, not a fetch: VentaController::
                         toggleApiLogging() flips the column and redirects with
                         a flash, and it reads nothing from the body. The
                         control names what pressing it will do rather than
                         showing an on/off knob whose current position has to
                         be decoded. --}}
                    <form method="POST" action="{{ route('ext.venta.toggle_api_logging', $store->id) }}">
                        @csrf
                        <x-ui.button type="submit" size="sm">{{ $apiLogging ? 'Stop recording' : 'Start recording' }}</x-ui.button>
                    </form>
                @endif
                <x-ui.badge :tone="$apiLogging ? 'success' : 'neutral'">{{ $apiLogging ? 'Recording' : 'Not recording' }}</x-ui.badge>
            </div>
        </div>
        <p class="fm-section__note">Keeps a copy of every request sent to {{ $storeName }} and the response that came back. Useful while setting a store up, and worth switching off once it is running.</p>
    </section>

    <section class="fm-section">
        <div class="fm-section__head">
            <h2 class="fm-section__title">Recent calls</h2>
            @if($apiLogCount > 0 && $canManageVenta)
            <div class="fm-section__aside">
                <form method="POST" action="{{ route('ext.venta.clear_api_logs', $store->id) }}"
                      data-confirm="Delete every recorded API call for {{ $storeName }}? This cannot be undone.">
                    @csrf
                    @method('DELETE')
                    <x-ui.button type="submit" variant="danger" size="sm">Delete all</x-ui.button>
                </form>
            </div>
            @endif
        </div>

        @if($apiLogCount === 0)
            <x-ui.empty title="Nothing recorded yet"
                        description="Calls appear here as soon as a sync runs, provided recording is on." />
        @else
            <x-ui.table>
                <x-slot:head>
                    <tr>
                        <th scope="col" class="cs-col-when">When</th>
                        <th scope="col" class="cs-col-method">Method</th>
                        <th scope="col">Endpoint</th>
                        <th scope="col" class="cs-col-status x-td-num">Status</th>
                        <th scope="col" class="cs-col-ms x-td-num">Time</th>
                    </tr>
                </x-slot:head>

                @foreach($storeApiLogs->take(30) as $alog)
                    <tr>
                        <td class="cs-col-when" data-label="When">{{ $alog->created_at->format('M d, H:i:s') }}</td>
                        <td class="cs-col-method" data-label="Method">
                            <x-ui.badge :tone="$alog->ok ? 'success' : 'danger'">{{ $alog->method }}</x-ui.badge>
                        </td>
                        <td data-label="Endpoint">
                            <div class="cs-code">{{ $alog->endpoint }}</div>
                            @if(!$alog->ok && $alog->response_body)
                                <span class="cc-err">{{ \Illuminate\Support\Str::limit(json_encode($alog->response_body), 120) }}</span>
                            @endif

                            {{-- Server-rendered disclosure, so a payload never
                                 needs JavaScript to be read and nothing is
                                 written into the page by script. --}}
                            <details class="cs-disclosure">
                                <summary>Request and response</summary>
                                <div class="cs-disclosure__body">
                                    <span class="cs-disclosure__label">Request body</span>
                                    <pre class="cs-pre">{{ $alog->request_body ? json_encode($alog->request_body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : 'Nothing sent (a GET request)' }}</pre>
                                    <span class="cs-disclosure__label">Response</span>
                                    <pre class="cs-pre">{{ $alog->response_body ? json_encode($alog->response_body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : 'Empty response' }}</pre>
                                </div>
                            </details>
                        </td>
                        <td class="cs-col-status x-td-num" data-label="Status">{{ $alog->status_code }}</td>
                        <td class="cs-col-ms x-td-num" data-label="Time">{{ $alog->response_time_ms }} ms</td>
                    </tr>
                @endforeach
            </x-ui.table>
        @endif
    </section>
</div>

{{-- ================================================================
     SYNC LOG
     ================================================================ --}}
<div id="vt-tab-sync" class="cs-panel" role="tabpanel" aria-labelledby="vt-tab-sync-tab" hidden>
    <section class="fm-section">
        <div class="fm-section__head">
            <h2 class="fm-section__title">Recent runs</h2>
        </div>
        <p class="fm-section__note">One line per scheduled or manual run against {{ $storeName }}. The counts are what the run itself reported.</p>

        @if($storeSyncLogs->count() === 0)
            <x-ui.empty title="Nothing has run yet"
                        description="Runs appear here once the scheduled commands on the Scheduling tab have fired at least once." />
        @else
            <x-ui.table>
                <x-slot:head>
                    <tr>
                        <th scope="col" class="cs-col-when">When</th>
                        <th scope="col" class="cs-col-entity">What</th>
                        <th scope="col" class="cs-col-dir">Direction</th>
                        <th scope="col" class="cs-col-status">Result</th>
                        <th scope="col" class="cs-col-count x-td-num">Seen</th>
                        <th scope="col" class="cs-col-count x-td-num">Added</th>
                        <th scope="col" class="cs-col-count x-td-num">Changed</th>
                        <th scope="col" class="cs-col-count x-td-num">Failed</th>
                        <th scope="col">Error</th>
                    </tr>
                </x-slot:head>

                @foreach($storeSyncLogs->take(15) as $log)
                    <tr>
                        <td class="cs-col-when" data-label="When">{{ ($log->started_at ?? $log->created_at)->format('M d, H:i') }}</td>
                        <td class="cs-col-entity" data-label="What">{{ ucfirst((string) $log->entity_type) }}</td>
                        <td class="cs-col-dir" data-label="Direction">{{ $log->direction === 'pull' ? 'Pulled in' : 'Pushed out' }}</td>
                        <td class="cs-col-status" data-label="Result">
                            @if($log->status === 'completed')
                                <x-ui.badge tone="success">Completed</x-ui.badge>
                            @elseif($log->status === 'failed')
                                <x-ui.badge tone="danger">Failed</x-ui.badge>
                            @else
                                <span class="x-cell-muted">{{ ucfirst((string) $log->status) }}</span>
                            @endif
                        </td>
                        <td class="cs-col-count x-td-num" data-label="Seen"><span class="x-num">{{ number_format((int) $log->records_processed) }}</span></td>
                        <td class="cs-col-count x-td-num" data-label="Added"><span class="x-num">{{ number_format((int) $log->records_created) }}</span></td>
                        <td class="cs-col-count x-td-num" data-label="Changed"><span class="x-num">{{ number_format((int) $log->records_updated) }}</span></td>
                        <td class="cs-col-count x-td-num" data-label="Failed">
                            @if((int) $log->records_failed > 0)
                                <span class="x-num cs-fail">{{ number_format((int) $log->records_failed) }}</span>
                            @else
                                <span class="x-num">0</span>
                            @endif
                        </td>
                        <td data-label="Error">
                            @if($log->error_message)
                                <span class="cc-err">{{ \Illuminate\Support\Str::limit($log->error_message, 120) }}</span>
                            @else
                                <span class="x-cell-muted">None</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </x-ui.table>
        @endif
    </section>
</div>

</div>{{-- /.cs-page --}}
@endsection
