@extends('layouts.channel')
@section('breadcrumb', 'Settings')

@section('title', 'OpenCart Settings')

@section('content')
@php
    $canManageOpencart = auth()->user()?->hasPermission('manage_opencart/settings') ?? false;

    $store = $stores->first();

    $storeName = $store->store_name ?: 'Unnamed store';
    $storeHost = parse_url((string) $store->base_url, PHP_URL_HOST) ?: $store->base_url;

    $statusMap = $orderStatusMaps->get($store->id, collect());
    $storeSyncLogs = $recentLogs->where('opencart_setting_id', $store->id);

    $importSteps = [
        ['entity' => 'categories',    'label' => 'Categories',    'note' => 'Products need somewhere to land.'],
        ['entity' => 'manufacturers', 'label' => 'Manufacturers', 'note' => 'Pulled alongside categories.'],
        ['entity' => 'options',       'label' => 'Variations',    'note' => 'Needs categories and manufacturers first.'],
        ['entity' => 'products',      'label' => 'Products',      'note' => 'Needs variations first.'],
        ['entity' => 'status_map',    'label' => 'Order statuses','note' => 'Map them before orders arrive, or every order lands on a raw numeric status.'],
        ['entity' => 'orders',        'label' => 'Orders',        'note' => 'Last. Stock is not adjusted for a historical import.'],
    ];

    $jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
    $erpStatusOptions = $erpOrderStatuses->map(fn ($s) => ['id' => (int) $s->order_status_id, 'name' => (string) $s->name])->values();
@endphp

@php
    $csKnown = ['connection', 'orders', 'status', 'automations', 'sync'];
    if ($canManageOpencart) { $csKnown[] = 'import'; }
    $csSection = in_array(request('tab'), $csKnown, true) ? request('tab') : ('connection');
@endphp
<div class="cs-page"
     data-channel-settings
     data-store-settings
     data-store-id="{{ $store->id }}"
     data-tab-prefix="oc-tab-"
     data-tab-storage-key="oc-active-tab"
     data-test-url="{{ $canManageOpencart ? route('ext.opencart.test') : '' }}"
     data-fetch-statuses-url="{{ $canManageOpencart ? route('ext.opencart.fetch_oc_statuses') : '' }}"
     data-save-map-url="{{ $canManageOpencart ? route('ext.opencart.save_status_map') : '' }}"
     data-map-id-field="oc_status_id"
     data-map-name-field="oc_status_name"
     data-map-server-id="order_status_id"
     data-map-server-key="erp_order_status_id"
     data-map-name-match
     data-sync-url="{{ $canManageOpencart ? route('ext.opencart.sync') : '' }}"
     data-push-url="{{ $canManageOpencart ? route('ext.opencart.push') : '' }}"
     data-push-qty-url="{{ $canManageOpencart ? route('ext.opencart.push_qty') : '' }}"
     data-verify-url="{{ $canManageOpencart ? route('ext.opencart.verify_password') : '' }}"
     data-channel-label="OpenCart">

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

@unless($canManageOpencart)
<div class="cs-readonly">
    <div>
        <strong>You can look, not change.</strong>
        The connection, sync window, status mapping and the run log are readable with the OpenCart view permission. Editing the connection, running syncs and pushing products need the manage tier. The API key is not shown.
    </div>
</div>
@endunless

<div id="oc-tab-connection" class="cs-panel" @if($csSection !== 'connection') hidden @endif>


    <div class="cs-grid">
        <section class="fm-section">
            <div class="fm-section__head">
                <h2 class="fm-section__title">This store</h2>
            </div>

            <form method="POST" action="{{ route('ext.opencart.save') }}">
                @csrf
                <input type="hidden" name="store_id" value="{{ $store->id }}">

                <div class="fm-fields">
                    <x-ui.field label="Store name" for="oc-store-name" name="store_name" wide
                                hint="What this store is called in the ERP. It is never sent to the store itself.">
                        <x-ui.input id="oc-store-name" name="store_name" value="{{ $store->store_name }}"
                                    placeholder="e.g. Main store" :disabled="!$canManageOpencart" />
                    </x-ui.field>

                    <x-ui.field label="Base URL" for="oc-base-url" name="base_url" :required="true" wide
                                hint="The storefront's address, with no trailing slash.">
                        <x-ui.input id="oc-base-url" name="base_url" value="{{ $store->base_url }}"
                                    placeholder="https://store.example.com" :disabled="!$canManageOpencart" />
                    </x-ui.field>

                    <x-ui.credential
                        name="api_key"
                        id="oc-api-key"
                        label="API key"
                        channel="opencart"
                        :store="$store->id"
                        hint="Stored encrypted. Read-only until you choose to edit it."
                        :length="\App\Support\Credentials::length($store->api_key ?? null)"
                        :can-reveal="$canManageOpencart"
                        :disabled="!$canManageOpencart" />

                    <x-ui.field label="Colour" for="oc-brand-color" name="brand_color"
                                hint="Used wherever this store appears in a chart or a report.">
                        <div class="cs-colour" data-colour-pair>
                            <input type="color" id="oc-brand-color" name="brand_color" class="cs-colour__swatch"
                                   value="{{ $store->brand_color ?: '#16a34a' }}" data-colour-input
                                   @disabled(!$canManageOpencart) aria-label="Store colour">
                            <x-ui.input class="cs-colour__hex fm-input--num" maxlength="7" data-colour-hex
                                        value="{{ $store->brand_color ?: '#16a34a' }}"
                                        aria-label="Store colour as a hex value" :disabled="!$canManageOpencart" />
                        </div>
                    </x-ui.field>

                    <div class="fm-field fm-field--wide">
                        <label class="fm-switch">
                            <input type="checkbox" class="fm-switch__input" name="enabled" value="1"
                                   @checked($store->enabled) @disabled(!$canManageOpencart)>
                            <span class="fm-switch__track"></span>
                            <span class="fm-switch__text">
                                <span class="fm-switch__label">Syncing</span>
                                <span class="fm-switch__note">Off leaves the store configured but stops every scheduled pull and push.</span>
                            </span>
                        </label>
                    </div>
                </div>

                @if($canManageOpencart)
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
            <p class="fm-section__note">The ERP Sync module has to be installed on the OpenCart server first. The key above is the shared secret set in that module, and both sides must hold the same one.</p>

            <dl class="cs-facts">
                <div class="cs-fact"><dt>Store</dt><dd>{{ $storeName }}</dd></div>
                <div class="cs-fact"><dt>Store ID</dt><dd class="cs-fact__mono">{{ $store->id }}</dd></div>
                <div class="cs-fact"><dt>Address</dt><dd class="cs-fact__mono">{{ $store->base_url }}</dd></div>
            </dl>

            @if($canManageOpencart)
            <div class="cs-danger">
                <div>
                    <span class="cs-danger__title">Remove this store <x-ui.hint label="What removal does">Everything this store holds in the ERP goes with it: product links, product groups, the status map and the sync log. Sales already imported stay under its name. Nothing on the OpenCart store is touched. There is no undo.</x-ui.hint></span>
                </div>
                <x-ui.button type="button" variant="danger" size="sm" data-delete-open>Delete this store</x-ui.button>
            </div>
            @endif
        </section>
    </div>

    @if($canManageOpencart)
    @include('partials.channel-delete-store', [
        'action' => route('ext.opencart.stores.destroy', ['store' => $store->id]),
        'storeName' => $store->store_name,
        'channelLabel' => 'OpenCart',
        'holds' => 'its product links, product groups, status map and sync log',
    ])
    @endif
</div>

<div id="oc-tab-orders" class="cs-panel" @if($csSection !== 'orders') hidden @endif>

    <section class="fm-section">
        <div class="fm-section__head">
            <h2 class="fm-section__title">How far back to look</h2>
        </div>
        <p class="fm-section__note">
            @if($store->sync_last_days)
                Right now every run fetches orders changed in the last {{ $store->sync_last_days }} {{ \Illuminate\Support\Str::plural('day', $store->sync_last_days) }}.@if($store->sync_orders_from) The fixed date is ignored while the rolling window is set.@endif
            @elseif($store->sync_orders_from)
                Right now every run fetches orders changed on or after {{ $store->sync_orders_from->format('j M Y') }}.
            @else
                Nothing is set, so every run fetches every order the store has ever had. Set one of the two below.
            @endif
        </p>

        <form method="POST" action="{{ route('ext.opencart.sync_date') }}">
            @csrf
            <input type="hidden" name="store_id" value="{{ $store->id }}">

            <div class="fm-fields fm-fields--2">
                <x-ui.field label="A rolling window of" for="oc-sync-days" name="sync_last_days"
                            hint="Wins over the fixed date whenever it is set.">
                    <div class="cs-inline">
                        <x-ui.input id="oc-sync-days" name="sync_last_days" type="number" min="1" max="365"
                                    class="fm-input--num cs-days" value="{{ $store->sync_last_days }}"
                                    placeholder="7" :disabled="!$canManageOpencart" />
                        <span class="cs-inline__unit">days</span>
                    </div>
                </x-ui.field>

                <x-ui.field label="Or a fixed date" for="oc-sync-from" name="sync_orders_from"
                            hint="Only used when the rolling window is empty.">
                    <x-ui.input id="oc-sync-from" name="sync_orders_from" type="date"
                                value="{{ $store->sync_orders_from ? $store->sync_orders_from->format('Y-m-d') : '' }}"
                                :disabled="!$canManageOpencart" />
                </x-ui.field>
            </div>

            @if($canManageOpencart)
            <div class="cs-actions">
                <x-ui.button type="submit" variant="primary">Save the window</x-ui.button>
            </div>
            @endif
        </form>
    </section>

    @if($canManageOpencart)
    <section class="fm-section">
        <div class="fm-section__head">
            <h2 class="fm-section__title">Run one now</h2>
            <x-ui.hint label="About running one now">Outside the schedule, for when you need something immediately. Leave both dates empty to use the window above.</x-ui.hint>
        </div>

        <div class="fm-fields fm-fields--2">
            <x-ui.field label="From" for="oc-pull-from">
                <x-ui.input id="oc-pull-from" type="date" data-pull-from />
            </x-ui.field>
            <x-ui.field label="To" for="oc-pull-to">
                <x-ui.input id="oc-pull-to" type="date" data-pull-to />
            </x-ui.field>
        </div>

        <div class="cs-actions">
            <x-ui.button type="button" data-sync-run data-entity="orders">Pull orders</x-ui.button>
            <x-ui.button type="button" data-push-qty>Push stock</x-ui.button>
            <span class="cs-actions__note">Both talk to the store directly and can take a while. The result lands on the Run log tab.</span>
        </div>
    </section>
    @endif

    <section class="fm-section">
        <div class="fm-section__head">
            <h2 class="fm-section__title">Reviews pushed to this store</h2>
        </div>

        <form method="POST" action="{{ route('ext.opencart.save_review_settings') }}">
            @csrf
            <input type="hidden" name="store_id" value="{{ $store->id }}">

            <div class="fm-field">
                <label class="fm-switch">
                    <input type="checkbox" class="fm-switch__input" name="review_auto_approve" value="1"
                           @checked($store->review_auto_approve) @disabled(!$canManageOpencart)>
                    <span class="fm-switch__track"></span>
                    <span class="fm-switch__text">
                        <span class="fm-switch__label">Publish them straight away</span>
                        <span class="fm-switch__note">Off leaves each pushed review waiting for approval inside OpenCart.</span>
                    </span>
                </label>
            </div>

            @if($canManageOpencart)
            <div class="cs-actions">
                <x-ui.button type="submit" variant="primary">Save review setting</x-ui.button>
            </div>
            @endif
        </form>
    </section>
</div>

<div id="oc-tab-status" class="cs-panel" @if($csSection !== 'status') hidden @endif>
    <section class="fm-section">
        <div class="fm-section__head">
            <h2 class="fm-section__title">Order status mapping</h2>
            @if($canManageOpencart)
            <div class="fm-section__aside">
                <x-ui.button type="button" size="sm" data-fetch-statuses>Fetch statuses from OpenCart</x-ui.button>
            </div>
            @endif
        </div>
        <p class="fm-section__note">When an order is pulled from {{ $storeName }}, its OpenCart status becomes the sales order status chosen here. Fetching asks the store what statuses it has; anything already mapped keeps its mapping, and a status whose name matches an ERP status exactly is proposed for you.</p>

        <div data-status-map>
            @if($statusMap->isEmpty())
                <x-ui.empty title="Nothing mapped yet"
                            :description="$canManageOpencart
                                ? 'Fetch the statuses from the store, then choose what each one becomes here.'
                                : 'Someone with the manage tier needs to fetch the statuses from the store first.'" />
            @else
                <x-ui.table>
                    <x-slot:head>
                        <tr>
                            <th scope="col" class="cs-col-src">OpenCart status</th>
                            <th scope="col">Sales order status</th>
                        </tr>
                    </x-slot:head>
                    @foreach($statusMap as $map)
                        <tr>
                            <td class="cs-col-src" data-label="OpenCart status">
                                <span class="cs-map-name">{{ $map->oc_status_name }}</span>
                            </td>
                            <td data-label="Sales order status">
                                <div class="x-select-wrap cs-map-select">
                                    <select class="x-input" data-map-select
                                            data-map-id="{{ $map->oc_status_id }}"
                                            data-map-name="{{ $map->oc_status_name }}"
                                            aria-label="Sales order status for {{ $map->oc_status_name }}"
                                            @disabled(!$canManageOpencart)>
                                        <option value="0">Leave the raw OpenCart status</option>
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

        @if($canManageOpencart)
        <div class="cs-actions" data-save-map-bar @if($statusMap->isEmpty()) hidden @endif>
            <x-ui.button type="button" variant="primary" data-save-map>Save mapping</x-ui.button>
        </div>
        @endif

        <script type="application/json" data-erp-statuses>{!! json_encode($erpStatusOptions, $jsonFlags) !!}</script>
    </section>
</div>

@if($canManageOpencart)
<div id="oc-tab-automations" class="cs-panel" @if($csSection !== 'automations') hidden @endif>
    <section class="fm-section">
        <div class="fm-section__head">
            <h2 class="fm-section__title">Scheduled jobs</h2>
            <x-ui.hint label="About the scheduled jobs">How often each OpenCart sync runs for {{ $store->store_name ?: $store->base_url }}. Changes take effect the next time the scheduler wakes up.</x-ui.hint>
        </div>

        @include('partials._automations_tab', ['integration' => 'opencart', 'storeId' => $store->id])
    </section>
</div>

<div id="oc-tab-import" class="cs-panel" @if($csSection !== 'import') hidden @endif>
    <section class="fm-section">
        <div class="fm-section__head">
            <h2 class="fm-section__title">Import everything, once</h2>
        </div>
        <p class="fm-section__note">A full import ignores the date window and pulls the store's whole history. It is for a new connection only. On an ERP that already holds this store's data it will overwrite what is here, so it stays locked until you confirm it is you.</p>

        <div class="cs-lock" data-import-lock>
            <div class="cs-lock__row">
                <x-ui.field label="Your account password" for="oc-import-pw" name="password">
                    <x-ui.input id="oc-import-pw" type="password" autocomplete="current-password" data-import-password />
                </x-ui.field>
                <x-ui.button type="button" data-import-unlock>Unlock</x-ui.button>
            </div>
            <p class="fm-error" data-import-error hidden></p>
        </div>

        <div class="cs-steps cs-steps--locked" data-import-steps hidden>
            @foreach($importSteps as $i => $step)
                <div class="cs-step" data-import-step="{{ $step['entity'] }}" data-step-index="{{ $i + 1 }}"
                     @if($i >= 2) data-step-locked @endif>
                    <span class="cs-step__n">{{ $i + 1 }}</span>
                    <div class="cs-step__body">
                        <span class="cs-step__title">{{ $step['label'] }}</span>
                        <p class="cs-step__note">{{ $step['note'] }}</p>
                        <div class="cs-step__row">
                            @if($step['entity'] === 'status_map')
                                <x-ui.button type="button" size="sm" data-goto-tab="oc-tab-status">Open status mapping</x-ui.button>
                            @else
                                <x-ui.button type="button" size="sm" data-sync-run data-entity="{{ $step['entity'] }}" data-full="1">Import {{ strtolower($step['label']) }}</x-ui.button>
                            @endif
                            <span class="cs-step__state" data-step-state></span>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="cs-actions" data-import-actions hidden>
            <x-ui.button type="button" size="sm" data-import-reset>Start the checklist again</x-ui.button>
            <span class="cs-actions__note">Only clears the ticks on this page. Nothing already imported is undone.</span>
        </div>
    </section>

    <section class="fm-section" data-import-push hidden>
        <div class="fm-section__head">
            <h2 class="fm-section__title">Send the catalog the other way</h2>
        </div>
        <p class="fm-section__note">Pushes every catalog product to {{ $storeName }}, overwriting the store's own copy of each one. This is the opposite direction to the checklist above and is not part of it.</p>

        <div class="cs-actions">
            <x-ui.button type="button" variant="danger" data-push-all
                         data-confirm-message="Push every catalog product to {{ $storeName }}? Product data on the OpenCart store is overwritten.">Push all products</x-ui.button>
        </div>
    </section>
</div>
@endif

<div id="oc-tab-sync" class="cs-panel" @if($csSection !== 'sync') hidden @endif>
    <section class="fm-section">
        <div class="fm-section__head">
            <h2 class="fm-section__title">Recent runs</h2>
        </div>
        <p class="fm-section__note">One line per scheduled or manual run against {{ $storeName }}. The counts are what the run itself reported.</p>

        @if($storeSyncLogs->count() === 0)
            <x-ui.empty title="Nothing has run yet"
                        description="Runs appear here once the scheduled commands on the Scheduling tab have fired at least once, or you run one from the Order sync tab." />
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

                @foreach($storeSyncLogs as $log)
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

</div>
@endsection
