@extends('layouts.app')

@section('content')
<div class="page-header">
    <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;">
        <h1 style="margin:0;">Lazada</h1>
        <div style="display:flex; gap:8px;">
            <a class="btn" href="{{ route('lazada.listings.index') }}">Go to Listings</a>
        </div>
    </div>
</div>

<div class="card">
    <h2>Connection Settings</h2>

    <form method="POST" action="{{ route('lazada.save') }}">
        @csrf

        <div class="grid-2">
            <div class="field">
                <label>Region</label>
                <select name="region" class="input">
                    <option value="">(not set)</option>
                    <option value="ph" {{ ($setting->region ?? '') === 'ph' ? 'selected' : '' }}>Philippines (ph)</option>
                    <option value="sg" {{ ($setting->region ?? '') === 'sg' ? 'selected' : '' }}>Singapore (sg)</option>
                    <option value="my" {{ ($setting->region ?? '') === 'my' ? 'selected' : '' }}>Malaysia (my)</option>
                    <option value="id" {{ ($setting->region ?? '') === 'id' ? 'selected' : '' }}>Indonesia (id)</option>
                    <option value="th" {{ ($setting->region ?? '') === 'th' ? 'selected' : '' }}>Thailand (th)</option>
                    <option value="vn" {{ ($setting->region ?? '') === 'vn' ? 'selected' : '' }}>Vietnam (vn)</option>
                </select>
            </div>

            <div class="field">
                <label>Redirect URI</label>
                <input class="input" name="redirect_uri" value="{{ old('redirect_uri', $setting->redirect_uri ?? $defaultRedirect) }}">
                <div class="help">Use this as the app callback URL in Lazada Open Platform.</div>
            </div>

            <div class="field">
                <label>App Key</label>
                <input class="input" name="app_key" value="{{ old('app_key', $setting->app_key ?? '') }}">
            </div>

            <div class="field">
                <label>App Secret</label>
                <input class="input" name="app_secret" value="{{ old('app_secret', $setting->app_secret ?? '') }}">
                <div class="help">Stored encrypted.</div>
            </div>
        </div>

        <div class="actions">
            <button class="btn btn-primary" type="submit">Save Settings</button>
        </div>
    </form>
</div>

<div class="card">
    <h2>OAuth Proof of Concept</h2>

    <div class="actions">
        <a class="btn" href="{{ route('lazada.authorize') }}" target="_blank" rel="noopener">Authorize with Lazada</a>
    </div>

    <div class="divider"></div>

    <form method="POST" action="{{ route('lazada.token_create') }}">
        @csrf
        <div class="field">
            <label>Auth Code (from callback)</label>
            <input class="input" name="code" value="{{ old('code', $setting->auth_code ?? session('lazada_last_auth_code') ?? '') }}" placeholder="Will auto-fill after a successful callback, or paste code=... here">
        </div>

        <div class="actions">
            <button class="btn btn-primary" type="submit">Exchange Code → Token</button>
        </div>
    </form>

    <div class="divider"></div>

    <div class="actions">
        <form method="POST" action="{{ route('lazada.token_refresh') }}" style="display:inline">
            @csrf
            <button class="btn" type="submit">Refresh Access Token</button>
        </form>
    </div>

    <div class="divider"></div>

    <h3>API Explorer</h3>

    <div class="help" style="margin-top:6px;">
        Pick an endpoint, fill parameters in a friendly table, and run. Base signing fields are generated automatically.
        <br><span class="lz-code">E005: Invalid Request Format</span> usually means the endpoint expects a specific payload format (e.g., <span class="lz-code">payload</span> as a JSON/XML string).
    </div>

    <style>
        .lz-code{font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;}
        .lz-explorer{display:grid;grid-template-columns: 360px 1fr;gap:14px;margin-top:12px;}
        @media (max-width: 1100px){.lz-explorer{grid-template-columns:1fr;}}
        .lz-panel{border:1px solid #e5e7eb;border-radius:10px;padding:12px;background:#fff;}
        .lz-search{display:flex;gap:8px;align-items:center;}
        .lz-search input{width:100%;}
        .lz-list{margin-top:10px;max-height:520px;overflow:auto;padding-right:4px;}
        .lz-cat{margin:10px 0 6px;font-weight:700;color:#111827;font-size:13px;text-transform:uppercase;letter-spacing:.03em;}
        .lz-endpoint{width:100%;text-align:left;border:1px solid #e5e7eb;border-radius:10px;padding:10px 10px;margin-bottom:8px;background:#fafafa;cursor:pointer;}
        .lz-endpoint:hover{background:#f3f4f6;}
        .lz-badge{display:inline-block;font-size:11px;padding:2px 8px;border-radius:999px;border:1px solid #e5e7eb;background:#fff;margin-right:8px;}
        .lz-badge.get{border-color:#bfdbfe;background:#eff6ff;}
        .lz-badge.post{border-color:#bbf7d0;background:#f0fdf4;}
        .lz-hint{color:#6b7280;font-size:12px;margin-top:4px;}
        .lz-kv{width:100%;border-collapse:collapse;}
        .lz-kv th,.lz-kv td{border-bottom:1px solid #eef2f7;padding:8px 6px;vertical-align:top;}
        .lz-kv th{color:#374151;font-size:12px;text-transform:uppercase;letter-spacing:.03em;}
        .lz-kv input,.lz-kv textarea{width:100%;}
        .lz-row-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center;}
        .lz-mini{font-size:12px;color:#6b7280;}
        .lz-note{border:1px dashed #e5e7eb;border-radius:10px;padding:10px;background:#fcfcfd;color:#374151;}
        .lz-split{display:grid;grid-template-columns: 1fr 1fr;gap:10px;}
        @media (max-width: 900px){.lz-split{grid-template-columns:1fr;}}
    </style>

    <div class="lz-explorer">
        <div class="lz-panel">
            <div class="lz-search">
                <input class="input" id="lz-endpoint-search" placeholder="Search endpoints (e.g. product/create, orders/get)">
            </div>
            <div class="lz-hint">Click an endpoint to auto-fill method, auth, path and parameters.</div>

            <div class="lz-list" id="lz-endpoint-list">
                <div class="lz-cat">Seller</div>
                <button type="button" class="lz-endpoint" data-ep='{"name":"Get Seller","method":"GET","auth":true,"path":"/seller/get","desc":"Fetch seller/account info.","params":[]}'><span class="lz-badge get">GET</span><span class="lz-code">/seller/get</span><div class="lz-hint">Seller/account info</div></button>

                <div class="lz-cat">Catalog</div>
                <button type="button" class="lz-endpoint" data-ep='{"name":"Category Tree","method":"GET","auth":false,"path":"/category/tree/get","desc":"Fetch category tree.","params":[]}'><span class="lz-badge get">GET</span><span class="lz-code">/category/tree/get</span><div class="lz-hint">Public</div></button>
                <button type="button" class="lz-endpoint" data-ep='{"name":"Category Attributes","method":"GET","auth":false,"path":"/category/attributes/get","desc":"Get attributes for a primary category.","params":[{"k":"primary_category_id","req":true,"ph":"9257"}]}'><span class="lz-badge get">GET</span><span class="lz-code">/category/attributes/get</span><div class="lz-hint">Requires primary_category_id</div></button>
                <button type="button" class="lz-endpoint" data-ep='{"name":"Brands Query","method":"GET","auth":false,"path":"/category/brands/query","desc":"Brands list (uses startRow/pageSize internally).","params":[{"k":"page_no","req":false,"ph":"1"},{"k":"page_size","req":false,"ph":"50"},{"k":"brand_name","req":false,"ph":"Morley"}]}'><span class="lz-badge get">GET</span><span class="lz-code">/category/brands/query</span><div class="lz-hint">Paging + optional brand_name</div></button>
                <button type="button" class="lz-endpoint" data-ep='{"name":"Products (List)","method":"GET","auth":true,"path":"/products/get","desc":"List products.","params":[{"k":"filter","req":false,"ph":"all"},{"k":"limit","req":false,"ph":"10"},{"k":"offset","req":false,"ph":"0"}]}'><span class="lz-badge get">GET</span><span class="lz-code">/products/get</span><div class="lz-hint">Auth required</div></button>
                <button type="button" class="lz-endpoint" data-ep='{"name":"QC Status","method":"GET","auth":true,"path":"/product/qc/status/get","desc":"QC status by seller_sku.","params":[{"k":"seller_sku","req":true,"ph":"MY-SKU"}]}'><span class="lz-badge get">GET</span><span class="lz-code">/product/qc/status/get</span><div class="lz-hint">seller_sku</div></button>

                <div class="lz-cat">Products</div>
                <button type="button" class="lz-endpoint" data-ep='{"name":"Create Product","method":"POST","auth":true,"path":"/product/create","desc":"Create product. Lazada expects payload as a JSON/XML string (depends on API).","params":[{"k":"payload","req":true,"type":"payload"}]}'><span class="lz-badge post">POST</span><span class="lz-code">/product/create</span><div class="lz-hint">payload required</div></button>
                <button type="button" class="lz-endpoint" data-ep='{"name":"Update Product","method":"POST","auth":true,"path":"/product/update","desc":"Update product. payload required.","params":[{"k":"payload","req":true,"type":"payload"}]}'><span class="lz-badge post">POST</span><span class="lz-code">/product/update</span><div class="lz-hint">payload required</div></button>
                <button type="button" class="lz-endpoint" data-ep='{"name":"Update Price/Qty","method":"POST","auth":true,"path":"/product/price_quantity/update","desc":"Update price + quantity.","params":[{"k":"seller_sku","req":true,"ph":"MY-SKU"},{"k":"price","req":true,"ph":"999"},{"k":"quantity","req":true,"ph":"10"}]}'><span class="lz-badge post">POST</span><span class="lz-code">/product/price_quantity/update</span><div class="lz-hint">seller_sku, price, quantity</div></button>

                <div class="lz-cat">Orders</div>
                <button type="button" class="lz-endpoint" data-ep='{"name":"Orders (Range)","method":"GET","auth":true,"path":"/orders/get","desc":"Orders list.","params":[{"k":"update_after","req":false,"ph":""},{"k":"sort_direction","req":false,"ph":"DESC"},{"k":"limit","req":false,"ph":"10"},{"k":"offset","req":false,"ph":"0"}]}'><span class="lz-badge get">GET</span><span class="lz-code">/orders/get</span><div class="lz-hint">update_after, limit, offset</div></button>
                <button type="button" class="lz-endpoint" data-ep='{"name":"Order (Single)","method":"GET","auth":true,"path":"/order/get","desc":"Fetch one order.","params":[{"k":"order_id","req":true,"ph":"123"}]}'><span class="lz-badge get">GET</span><span class="lz-code">/order/get</span><div class="lz-hint">order_id</div></button>
                <button type="button" class="lz-endpoint" data-ep='{"name":"Order Items","method":"GET","auth":true,"path":"/order/items/get","desc":"Items for an order.","params":[{"k":"order_id","req":true,"ph":"123"}]}'><span class="lz-badge get">GET</span><span class="lz-code">/order/items/get</span><div class="lz-hint">order_id</div></button>
                <button type="button" class="lz-endpoint" data-ep='{"name":"Pack Order","method":"POST","auth":true,"path":"/order/pack","desc":"Pack order items.","params":[{"k":"delivery_type","req":true,"ph":"dropship"},{"k":"shipping_provider","req":true,"ph":""},{"k":"order_item_ids","req":true,"ph":"<comma-separated>"}]}'><span class="lz-badge post">POST</span><span class="lz-code">/order/pack</span><div class="lz-hint">delivery_type, shipping_provider, order_item_ids</div></button>
                <button type="button" class="lz-endpoint" data-ep='{"name":"Ready To Ship (RTS)","method":"POST","auth":true,"path":"/order/rts","desc":"Mark packed items as ready to ship.","params":[{"k":"delivery_type","req":true,"ph":"dropship"},{"k":"shipping_provider","req":true,"ph":""},{"k":"tracking_number","req":false,"ph":""},{"k":"order_item_ids","req":true,"ph":"<comma-separated>"}]}'><span class="lz-badge post">POST</span><span class="lz-code">/order/rts</span><div class="lz-hint">delivery_type, shipping_provider, order_item_ids</div></button>
                <button type="button" class="lz-endpoint" data-ep='{"name":"Get Shipping Label (AWB)","method":"GET","auth":true,"path":"/order/package/document/get","desc":"Fetch shipping label/document for an order.","params":[{"k":"order_id","req":true,"ph":"123"},{"k":"doc_type","req":false,"ph":"shippingLabel"}]}'><span class="lz-badge get">GET</span><span class="lz-code">/order/package/document/get</span><div class="lz-hint">order_id, doc_type</div></button>
                <button type="button" class="lz-endpoint" data-ep='{"name":"Cancel Order Item","method":"POST","auth":true,"path":"/order/cancel","desc":"Cancel an order item.","params":[{"k":"reason_id","req":true,"ph":""},{"k":"order_item_id","req":true,"ph":""},{"k":"reason_detail","req":false,"ph":""}]}'><span class="lz-badge post">POST</span><span class="lz-code">/order/cancel</span><div class="lz-hint">reason_id, order_item_id</div></button>
                <button type="button" class="lz-endpoint" data-ep='{"name":"Validate Cancel","method":"GET","auth":true,"path":"/order/reverse/cancel/validate","desc":"Validate whether an order can be cancelled.","params":[{"k":"order_id","req":true,"ph":"123"}]}'><span class="lz-badge get">GET</span><span class="lz-code">/order/reverse/cancel/validate</span><div class="lz-hint">order_id</div></button>

                <div class="lz-cat">Images</div>
                <button type="button" class="lz-endpoint" data-ep='{"name":"Image Upload (Base64)","method":"POST","auth":true,"path":"/image/upload","desc":"Upload base64 image (returns Lazada inlink).","params":[{"k":"image","req":true,"ph":"<base64>"}]}'><span class="lz-badge post">POST</span><span class="lz-code">/image/upload</span><div class="lz-hint">image (base64)</div></button>
                <button type="button" class="lz-endpoint" data-ep='{"name":"Images Migrate","method":"POST","auth":true,"path":"/images/migrate","desc":"Migrate public URLs to Lazada-hosted images. payload required.","params":[{"k":"payload","req":true,"type":"payload"}]}'><span class="lz-badge post">POST</span><span class="lz-code">/images/migrate</span><div class="lz-hint">payload required</div></button>
                <button type="button" class="lz-endpoint" data-ep='{"name":"Image Response Get","method":"GET","auth":true,"path":"/image/response/get","desc":"Fetch migrate results by batch_id.","params":[{"k":"batch_id","req":true,"ph":"<batch_id>"}]}'><span class="lz-badge get">GET</span><span class="lz-code">/image/response/get</span><div class="lz-hint">batch_id</div></button>

                <div class="lz-cat">Finance</div>
                <button type="button" class="lz-endpoint" data-ep='{"name":"Transaction Details","method":"GET","auth":true,"path":"/finance/transaction/details/get","desc":"Transaction/fees details.","params":[{"k":"start_time","req":true,"ph":""},{"k":"end_time","req":true,"ph":""},{"k":"limit","req":false,"ph":"10"},{"k":"offset","req":false,"ph":"0"}]}'><span class="lz-badge get">GET</span><span class="lz-code">/finance/transaction/details/get</span><div class="lz-hint">start_time, end_time</div></button>
            </div>
        </div>

        <div class="lz-panel">
            <div class="lz-split" style="margin-bottom:10px;">
                <div>
                    <div class="field" style="margin-bottom:0;">
                        <label>Selected Endpoint</label>
                        <div class="lz-mini" id="lz-ep-name">None selected</div>
                        <div class="lz-hint" id="lz-ep-desc"></div>
                    </div>
                </div>
                <div class="lz-note">
                    <div style="font-weight:700; margin-bottom:6px;">Common pitfalls</div>
                    <div class="lz-mini">
                        <div>• <span class="lz-code">payload</span> must be a single string. If you paste JSON object/array, the server will encode it, but Lazada may require a specific structure.</div>
                        <div>• If you see repeated timestamps, you’re reading an old log entry. Always check the newest row.</div>
                    </div>
                </div>
            </div>

            <form id="lazada-api-explorer-form" method="POST" action="{{ route('lazada.explorer_run') }}">
                @csrf

                <div class="grid-2">
                    <div class="field">
                        <label>Method</label>
                        <select name="method" class="input" id="lazada-explorer-method">
                            <option value="GET" {{ old('method', 'GET') === 'GET' ? 'selected' : '' }}>GET</option>
                            <option value="POST" {{ old('method') === 'POST' ? 'selected' : '' }}>POST</option>
                        </select>
                    </div>
                    <div class="field">
                        <label>Auth</label>
                        <label style="display:flex; align-items:center; gap:8px; margin-top:6px;">
                            <input type="checkbox" name="auth_required" value="1" id="lazada-explorer-auth" {{ old('auth_required') ? 'checked' : '' }}>
                            Use Access Token
                        </label>
                        <div class="lz-mini">Enable for seller/product/order/finance endpoints.</div>
                    </div>
                </div>

                <div class="field">
                    <label>API Path</label>
                    <input class="input" name="api_path" id="lazada-explorer-path" value="{{ old('api_path', '/seller/get') }}" placeholder="/seller/get">
                    <div class="lz-mini">Leading slash will be added automatically.</div>
                </div>

                <div class="field">
                    <label>Parameters</label>
                    <div class="lz-mini" style="margin-bottom:8px;">Use this table for most calls. It will generate JSON automatically.</div>
                    <table class="lz-kv" id="lz-kv-table">
                        <thead>
                            <tr>
                                <th style="width:34%;">Key</th>
                                <th>Value</th>
                                <th style="width:70px;"></th>
                            </tr>
                        </thead>
                        <tbody id="lz-kv-body"></tbody>
                    </table>
                    <div class="lz-row-actions" style="margin-top:10px;">
                        <button class="btn" type="button" id="lz-add-row">Add Param</button>
                        <button class="btn" type="button" id="lz-use-sample-payload" style="display:none;">Insert Sample Payload</button>
                        <button class="btn" type="button" id="lz-sync-to-json">Update JSON</button>
                        <span class="lz-mini">(Auto updates before submit)</span>
                    </div>
                </div>

                <div class="field">
                    <label>Params JSON</label>
                    <textarea class="input lz-code" name="params_json" id="lazada-explorer-params" rows="10" placeholder='{"limit":10,"offset":0}'>{{ old('params_json', '{\n  "payload": "<xml_or_json_payload_here>"\n}') }}</textarea>
                    <div class="lz-mini">If you prefer, edit JSON directly. The table can be rebuilt from JSON using “Update JSON”.</div>
                </div>

                <div class="actions">
                    <button class="btn btn-primary" type="submit">Run</button>
                </div>
            </form>
        </div>
    </div>

    @push('scripts')
    <script>
    (function(){
        function qs(sel){ return document.querySelector(sel); }
        function qsa(sel){ return Array.prototype.slice.call(document.querySelectorAll(sel)); }

        var methodSel = qs('#lazada-explorer-method');
        var authChk   = qs('#lazada-explorer-auth');
        var pathInp   = qs('#lazada-explorer-path');
        var paramsTa  = qs('#lazada-explorer-params');
        var kvBody    = qs('#lz-kv-body');
        var epName    = qs('#lz-ep-name');
        var epDesc    = qs('#lz-ep-desc');
        var epSearch  = qs('#lz-endpoint-search');
        var btnAddRow = qs('#lz-add-row');
        var btnSync   = qs('#lz-sync-to-json');
        var btnSample = qs('#lz-use-sample-payload');

        // Keep table (Key/Value) and Params JSON in sync without losing user edits.
        // Source-of-truth is whichever the user edited last.
        var tableDirty = false;
        var jsonDirty  = false;
        var syncTimer  = null;

        function markTableDirty(){
            tableDirty = true;
            jsonDirty = false;
            if (syncTimer) clearTimeout(syncTimer);
            syncTimer = setTimeout(function(){
                try { syncToJson(); } catch(e) {}
            }, 150);
        }

        function markJsonDirty(){
            jsonDirty = true;
            tableDirty = false;
        }


        function safeJsonParse(str){
            try { return JSON.parse(str); } catch(e){ return null; }
        }

        function isObject(val){
            return val !== null && typeof val === 'object';
        }

        function buildRow(key, value){
            var tr = document.createElement('tr');
            var tdK = document.createElement('td');
            var tdV = document.createElement('td');
            var tdA = document.createElement('td');

            var k = document.createElement('input');
            k.className = 'input';
            k.placeholder = 'key';
            k.value = key || '';

            var v;
            if ((key || '').toLowerCase() === 'payload') {
                v = document.createElement('textarea');
                v.className = 'input lz-code';
                v.rows = 6;
                v.placeholder = 'payload (JSON/XML string)';
                // Payload must be submitted as a STRING to Lazada.
                // If JSON is provided in Params JSON (as an object/array), stringify it so we never end up with "[object Object]".
                if (isObject(value)) {
                    v.value = JSON.stringify(value, null, 2);
                } else {
                    v.value = value || '';
                }
            } else {
                v = document.createElement('input');
                v.className = 'input';
                v.placeholder = 'value';
                v.value = (value === null || typeof value === 'undefined') ? '' : String(value);
            }

            // Any change in the table should update Params JSON.
            k.addEventListener('input', markTableDirty);
            k.addEventListener('change', markTableDirty);
            v.addEventListener('input', markTableDirty);
            v.addEventListener('change', markTableDirty);

            var del = document.createElement('button');
            del.type = 'button';
            del.className = 'btn';
            del.textContent = 'Remove';
            del.addEventListener('click', function(){
                tr.remove();
                syncToJson();
            });

            tdK.appendChild(k);
            tdV.appendChild(v);
            tdA.appendChild(del);
            tr.appendChild(tdK);
            tr.appendChild(tdV);
            tr.appendChild(tdA);
            kvBody.appendChild(tr);
        }

        function clearRows(){
            while(kvBody.firstChild){ kvBody.removeChild(kvBody.firstChild); }
        }

        function tableToObject(){
            var obj = {};
            qsa('#lz-kv-body tr').forEach(function(tr){
                var keyEl = tr.querySelector('td:nth-child(1) .input');
                var valEl = tr.querySelector('td:nth-child(2) .input, td:nth-child(2) textarea');
                var k = keyEl ? String(keyEl.value || '').trim() : '';
                if(!k) return;
                var vRaw = valEl ? valEl.value : '';
                // IMPORTANT:
                // Lazada expects `payload` to be a single string parameter (usually XML; sometimes JSON-encoded string).
                // Do NOT auto-parse payload into an object, otherwise it will round-trip as [object Object].
                if (String(k).toLowerCase() === 'payload') {
                    obj[k] = vRaw;
                } else {
                    var parsed = safeJsonParse(vRaw);
                    obj[k] = parsed !== null ? parsed : vRaw;
                }
            });
            return obj;
        }

        function syncToJson(){
            if(!paramsTa) return;
            paramsTa.value = JSON.stringify(tableToObject(), null, 2);
            // JSON now matches the table.
            jsonDirty = false;
            tableDirty = false;
        }

        function rebuildTableFromJson(){
            if(!paramsTa) return;
            var obj = safeJsonParse(paramsTa.value || '');
            if(!obj || typeof obj !== 'object') return;
            clearRows();
            Object.keys(obj).forEach(function(k){
                buildRow(k, obj[k]);
            });
            jsonDirty = false;
            tableDirty = false;
        }

        function selectEndpoint(ep){
            if (methodSel) methodSel.value = (ep.method || 'GET').toUpperCase();
            if (authChk) authChk.checked = !!ep.auth;
            if (pathInp) pathInp.value = ep.path || '';
            if (epName) epName.textContent = ep.name || 'Selected';
            if (epDesc) epDesc.textContent = ep.desc || '';

            clearRows();
            (ep.params || []).forEach(function(p){ buildRow(p.k, p.ph || ''); });
            var hasPayload = (ep.params || []).some(function(p){ return String(p.k).toLowerCase() === 'payload'; });
            if (btnSample) btnSample.style.display = hasPayload ? '' : 'none';
            syncToJson();
        }

        function getSamplePayloadFor(path){
            if (path === '/product/create' || path === '/product/update') {
                return JSON.stringify({
                    Request: {
                        Product: {
                            PrimaryCategory: 0,
                            Images: { Image: ["https://<your-domain>/path/to/image.jpg"] },
                            Attributes: {
                                name: "Sample Product Name",
                                description: "Sample description",
                                brand: "No Brand",
                                model: "MODEL-001",
                                package_height: "10",
                                package_length: "10",
                                package_width: "10",
                                package_weight: "1"
                            },
                            Skus: { Sku: [ { SellerSku: "SAMPLE-SKU", price: 100, quantity: 1 } ] }
                        }
                    }
                }, null, 2);
            }
            if (path === '/images/migrate') {
                // Lazada image migrate expects XML in `payload`.
                return '<' + '?xml version="1.0" encoding="UTF-8" ?>\n' +
                    '<Request>\n  <Images>\n    <Url>https://<your-domain>/path/to/image1.jpg</Url>\n  </Images>\n</Request>';
            }
            return '{\n  "Request": {}\n}';
        }

        qsa('.lz-endpoint').forEach(function(btn){
            btn.addEventListener('click', function(){
                var ep = safeJsonParse(btn.getAttribute('data-ep') || '{}') || {};
                selectEndpoint(ep);
            });
        });

        if (epSearch) {
            epSearch.addEventListener('input', function(){
                var q = String(epSearch.value || '').toLowerCase();
                qsa('.lz-endpoint').forEach(function(btn){
                    var raw = (btn.getAttribute('data-ep') || '').toLowerCase();
                    var t = (btn.textContent || '').toLowerCase() + ' ' + raw;
                    btn.style.display = t.indexOf(q) >= 0 ? '' : 'none';
                });
            });
        }

        if (btnAddRow) btnAddRow.addEventListener('click', function(){ buildRow('', ''); });
        if (btnSync) btnSync.addEventListener('click', function(){
            // If user edited JSON, rebuild table; otherwise just update JSON from table.
            if (jsonDirty) {
                rebuildTableFromJson();
            }
            syncToJson();
        });

        

        if (paramsTa) {
            paramsTa.addEventListener('input', markJsonDirty);
            paramsTa.addEventListener('change', markJsonDirty);
        }
if (btnSample) {
            btnSample.addEventListener('click', function(){
                var p = (pathInp && pathInp.value) ? String(pathInp.value) : '';
                qsa('#lz-kv-body tr').forEach(function(tr){
                    var keyEl = tr.querySelector('td:nth-child(1) .input');
                    if (!keyEl) return;
                    if (String(keyEl.value || '').trim().toLowerCase() !== 'payload') return;
                    var valEl = tr.querySelector('td:nth-child(2) textarea, td:nth-child(2) .input');
                    if (valEl) valEl.value = getSamplePayloadFor(p);
                });
                syncToJson();
            });
        }

        // initial load
        try { rebuildTableFromJson(); } catch(e) {}

        // On submit: prefer whatever is currently in the JSON textarea.
        // The previous behavior always overwrote the textarea from the table,
        // which made it easy to accidentally submit placeholder values like "<batch_id>".
        var form = qs('#lazada-api-explorer-form');
        if (form) form.addEventListener('submit', function(){
            try {
                // If the user edited Params JSON last, honor it by rebuilding the table.
                // Otherwise, keep the table as source-of-truth and just sync JSON from table.
                if (jsonDirty) {
                    rebuildTableFromJson();
                }
                syncToJson();
            } catch(e) {
                // If JSON is invalid, fall back to current table values.
                try { syncToJson(); } catch(e2) {}
            }
        });
        })();
    </script>
    @endpush

    @if(!empty($logs) && count($logs) > 0)
        <div class="divider"></div>
        <h3>Recent API Logs</h3>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th style="width:140px;">Time</th>
                        <th style="width:80px;">Method</th>
                        <th>API Path</th>
                        <th style="width:90px;">Auth</th>
                        <th style="width:90px;">Status</th>
                        <th style="width:70px;">OK</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($logs as $log)
                        <tr>
                            <td>{{ $log->created_at }}</td>
                            <td>{{ $log->method }}</td>
                            <td>
                                @if(!empty($log->pack))
                                    <div class="help" style="margin-bottom:2px;">Pack: <strong>{{ strtoupper($log->pack) }}</strong></div>
                                @endif
                                <div><code>{{ $log->api_path }}</code></div>
                                <details style="margin-top:6px;">
                                    <summary>View</summary>
                                    <div style="margin-top:8px;">
                                        <div class="help" style="margin-bottom:6px;">Request Params</div>
                                        <pre class="pre" style="max-height:220px; overflow:auto;">{{ json_encode($log->request_params, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                        <div class="help" style="margin:10px 0 6px;">Response</div>
                                        <pre class="pre" style="max-height:220px; overflow:auto;">{{ json_encode($log->response_body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                    </div>
                                </details>
                            </td>
                            <td>{{ $log->auth_required ? 'Yes' : 'No' }}</td>
                            <td>{{ $log->response_status }}</td>
                            <td>{{ $log->ok ? 'Yes' : 'No' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>

<div class="card">
    <h2>Catalog Proof of Concept</h2>

    <div class="actions">
        <form method="POST" action="{{ route('lazada.category_tree') }}" style="display:inline">
            @csrf
            <button class="btn btn-primary" type="submit">Get Category Tree</button>
        </form>
    </div>

    <div class="divider"></div>

    <form method="POST" action="{{ route('lazada.category_attributes') }}">
        @csrf
        <div class="field">
            <label>Category Attributes (Primary Category ID)</label>
            <input class="input" name="category_id" value="{{ old('category_id', '') }}" placeholder="e.g. 10000000">
            <div class="help">Calls <code>/category/attributes/get</code> (no auth required).</div>
        </div>
        <div class="actions">
            <button class="btn btn-primary" type="submit">Get Attributes</button>
        </div>
    </form>

    <div class="divider"></div>

    <form method="POST" action="{{ route('lazada.brands_query') }}">
        @csrf
        <div class="grid-2">
            <div class="field">
                <label>Brands Page No</label>
                <input class="input" name="page_no" value="{{ old('page_no', 1) }}">
            </div>
            <div class="field">
                <label>Brands Page Size</label>
                <input class="input" name="page_size" value="{{ old('page_size', 50) }}">
            </div>
        </div>
        <div class="actions">
            <button class="btn btn-primary" type="submit">Get Brands</button>
        </div>
        <div class="help">Calls <code>/category/brands/query</code> (no auth required).</div>
    </form>

    <div class="divider"></div>

    <form method="POST" action="{{ route('lazada.products_get') }}">
        @csrf
        <div class="field">
            <label>Get Products (Seller SKU)</label>
            <input class="input" name="seller_sku" value="{{ old('seller_sku', '') }}" placeholder="e.g. MY-SKU-001">
            <div class="help">Calls <code>/products/get</code> (auth required). Uses stored Access Token.</div>
        </div>

        <div class="actions">
            <button class="btn btn-primary" type="submit">Get Product</button>
        </div>
    </form>
</div>

<div class="card">
    <h2>Variants / Options Proof of Concept</h2>

    <form method="POST" action="{{ route('lazada.product_payload_preview') }}">
        @csrf
        <div class="grid-2">
            <div class="field">
                <label>ERP Product</label>
                <select name="product_id" class="input" required>
                    <option value="">(select)</option>
                    @foreach(($products ?? []) as $p)
                        <option value="{{ $p->product_id }}" {{ (string)old('product_id') === (string)$p->product_id ? 'selected' : '' }}>
                            #{{ $p->product_id }} — {{ $p->name ?: '(no name)' }} {{ ($p->sku || $p->model) ? ('(' . ($p->sku ?: $p->model) . ')') : '' }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="field">
                <label>Primary Category ID (Lazada)</label>
                <input class="input" name="primary_category_id" value="{{ old('primary_category_id', '') }}" placeholder="e.g. 10000000" required>
                <div class="help">Use the Category Tree button above to find the correct ID.</div>
            </div>
        </div>

        <div class="grid-2">
            <div class="field">
                <label>Brand ID (Lazada, optional)</label>
                <input class="input" name="brand_id" value="{{ old('brand_id', '') }}" placeholder="e.g. 12345">
                <div class="help">Optional for preview. Some categories require it.</div>
            </div>
            <div class="field">
                <label>Images (comma-separated URLs, optional)</label>
                <input class="input" name="images" value="{{ old('images', '') }}" placeholder="https://.../1.jpg, https://.../2.jpg">
            </div>
        </div>

        <div class="field">
            <label>Description Override (optional)</label>
            <textarea class="input" name="description" rows="4" placeholder="Leave blank to use ERP product description">{{ old('description', '') }}</textarea>
        </div>

        <div class="actions">
            <button class="btn btn-primary" type="submit">Preview Payload</button>
        </div>
        <div class="help">This will build SKU variants from your ERP options. Single option = 1 SKU per value; multiple options = SKU combinations (cartesian product).</div>
    </form>

    <div class="divider"></div>

    <form method="POST" action="{{ route('lazada.product_create') }}" onsubmit="return confirm('This will call Lazada /product/create using the generated payload. Continue?')">
        @csrf
        <input type="hidden" name="product_id" value="{{ old('product_id', '') }}">
        <input type="hidden" name="primary_category_id" value="{{ old('primary_category_id', '') }}">
        <input type="hidden" name="brand_id" value="{{ old('brand_id', '') }}">
        <input type="hidden" name="images" value="{{ old('images', '') }}">
        <input type="hidden" name="description" value="{{ old('description', '') }}">

        <div class="actions">
            <button class="btn" type="submit">Create Product in Lazada</button>
        </div>
        <div class="help">Start with Preview first. Lazada often requires category-specific attributes, so this call may return validation errors—that’s normal for POC.</div>
    </form>
</div>

@if($result)
    <div class="card">
        <h2>Last Result</h2>

        @php
            $ok = (bool)($result['ok'] ?? false);
        @endphp

        <div class="{{ $ok ? 'notice ok' : 'notice bad' }}">
            <strong>{{ $result['title'] ?? 'Result' }}</strong>
        </div>

        <pre class="pre">{{ json_encode($result['data'] ?? $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
    </div>
@endif

<style>
    .card { background:#fff; border-radius:16px; padding:16px; margin-bottom:16px; box-shadow:0 1px 2px rgba(0,0,0,.06); }
    .page-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:12px; }
    .grid-2 { display:grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    .field label { font-weight:600; display:block; margin-bottom:6px; }
    .help { font-size:12px; color:#64748b; margin-top:4px; }
    .actions { margin-top:12px; display:flex; gap:8px; flex-wrap:wrap; }
    .btn { border:1px solid #e2e8f0; background:#fff; color:#0f172a; padding:10px 14px; border-radius:10px; cursor:pointer; }
    .btn-primary { background:#2563eb; border-color:#2563eb; color:#fff; }
    .divider { height:1px; background:#e2e8f0; margin:14px 0; }
    .notice { padding:10px 12px; border-radius:12px; margin-bottom:10px; }
    .notice.ok { background:#ecfdf5; color:#065f46; border:1px solid #a7f3d0; }
    .notice.bad { background:#fef2f2; color:#991b1b; border:1px solid #fecaca; }
    .pre { background:#0b1220; color:#e2e8f0; padding:12px; border-radius:12px; overflow:auto; }
</style>
@endsection
