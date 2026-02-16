<!DOCTYPE html>
<html>
<head>
    <title>Shop A Inventory</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        :root {
            --sidebar-w: 240px;
            --sidebar-w-collapsed: 72px;
            --bg: #0b1220;
            --bg2: #0f172a;
            --text: #e2e8f0;
            --text2: #94a3b8;
            --accent: #2563eb;
        }

        body { margin:0; font-family: Arial, sans-serif; background:#f4f6f9; color:#0f172a; }
        .layout { display:flex; min-height:100vh; }

        .sidebar {
            width: var(--sidebar-w);
            background: var(--bg2);
            color: var(--text);
            position: fixed;
            top:0; left:0;
            height: 100vh;
            padding: 16px 10px;
            box-sizing: border-box;
            overflow: hidden; /* prevents hover bleed */
            z-index: 1200;
            transition: transform .45s cubic-bezier(.2,.8,.2,1); will-change: transform;
        }
        /* When collapsed, hide the sidebar completely off-canvas */
        .sidebar.collapsed { transform: translateX(calc(-1 * var(--sidebar-w))); }

        .brand {
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:10px;
            padding: 8px 10px 18px 10px;
            border-bottom: 1px solid rgba(148,163,184,.2);
            margin-bottom: 10px;
        }
        .brand .title { font-weight:700; letter-spacing:.5px; }
        .sidebar.collapsed .brand .title { display:none; }

        .brand-stack { display:flex; flex-direction:column; gap:4px; min-width:0; }
        .brand-logo { height:40px; width:auto; object-fit:contain; display:block; }
        .brand-company { font-weight:700; letter-spacing:.5px; line-height:1.1; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .brand-user { font-size:12px; opacity:.85; line-height:1.1; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }


        .sidebar.collapsed .brand-stack { align-items:center; }
        .sidebar.collapsed .brand-company,
        .sidebar.collapsed .brand-user { display:none; }


        .toggle-btn {
            border: 1px solid rgba(148,163,184,.3);
            background: transparent;
            color: var(--text);
            border-radius: 8px;
            padding: 6px 10px;
            cursor:pointer;
                    display:flex;
            align-items:center;
            gap:8px;
}
.toggle-hamburger { display:inline-block; }

        .sidebar-fab{
            position: fixed;
            top: 14px;
            left: 14px;
            z-index: 1300;
            border: 1px solid rgba(148,163,184,.35);
            background: var(--bg2);
            color: var(--text);
            border-radius: 10px;
            padding: 8px 10px;
            cursor: pointer;
            display: none;
            align-items: center;
            gap: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.25);
        }
        .sidebar-fab:hover{ background: rgba(148,163,184,.10); }
        .sidebar-fab .toggle-hamburger{ font-size: 16px; line-height: 1; }


        .sidebar-nav {
            margin-top: 8px;
            display:flex;
            flex-direction:column;
            gap:6px;
                    padding-bottom: 78px;
        }
        .sidebar-nav a {
            display:flex;
            align-items:center;
            gap:10px;
            color: var(--text2);
            padding: 10px 12px;
            border-radius: 10px;
            text-decoration:none;
            white-space: nowrap;
            transition: background .15s ease, color .15s ease;
        }
        .sidebar-nav a:hover {
            background: rgba(148,163,184,.15);
            color: var(--text);
        }
        .sidebar-nav a.active {
            background: rgba(37,99,235,.22);
            color: #fff;
            border: 1px solid rgba(37,99,235,.35);
        }

        .nav .label { flex:1; }
        .sidebar-footer {
            position: absolute;
            left: 10px;
            right: 10px;
            bottom: 14px;
        }
        .sidebar-footer form { margin: 0; }
        .sidebar-footer .logout-btn {
            width: 100%;
            background: none;
            border: none;
            cursor: pointer;
            text-align: left;

            display: flex;
            align-items: center;
            gap: 10px;
            color: var(--text2);
            padding: 10px 12px;
            border-radius: 10px;
            white-space: nowrap;
            transition: background .15s ease, color .15s ease;
        }
        .sidebar-footer .logout-btn:hover {
            background: rgba(148,163,184,.15);
            color: var(--text);
        }

        .sidebar.collapsed .nav .label { display:none; }

        .content {
            margin-left: var(--sidebar-w);
            padding: 26px;
            width: 100%;
            box-sizing:border-box;
            transition: margin-left .45s cubic-bezier(.2,.8,.2,1); will-change: margin-left;
        }
        

        .topbar {
            display:flex;
            justify-content: flex-end;
            align-items:center;
            padding: 10px 18px;
            margin-left: 0;
        }
        .topbar form { margin: 0; }
        .topbar .btn { border-radius: 10px; padding: 8px 12px; }
.content.collapsed { margin-left: 0; }

        .card {
            background:#fff;
            padding: 18px;
            border-radius: 14px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.06);
        }

        .page-header {
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap: 10px;
            margin-bottom: 12px;
        }
        .page-header h2 { margin:0; }

        .btn {
            display:inline-block;
            background: var(--accent);
            color:#fff;
            padding: 8px 12px;
            text-decoration:none;
            border-radius:10px;
            border: none;
            cursor: pointer;
        }
        .btn.secondary { background: #334155; }
        .btn.danger { background: #dc2626; }
        .btn.small { padding: 6px 10px; border-radius: 10px; font-size: 13px; }

        table {
            width:100%;
            border-collapse: collapse;
        }
        th, td {
            padding: 12px;
            border-bottom: 1px solid #e5e7eb;
            text-align:left;
            vertical-align: top;
        }
        th { color:#334155; font-size: 13px; text-transform: uppercase; letter-spacing: .06em; }

        .toolbar {
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap: 10px;
            margin: 10px 0 14px;
        }
        .toolbar form { display:flex; gap:8px; align-items:center; margin:0; }
        .input {
            padding: 8px 10px;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            min-width: 220px;
        }

        /* Ensure focus ring/border renders above adjacent fields (prevents visual underlap in tight rows) */
        .input:focus {
            position: relative;
            z-index: 20;
        }

        .form-grid { display:grid; gap: 12px; grid-template-columns: 1fr 1fr; }
        .form-grid .full { grid-column: 1 / -1; }
        label { font-size: 13px; color:#334155; display:block; margin-bottom: 6px; }
        textarea.input { min-height: 110px; }
        .hint { font-size: 12px; color:#64748b; margin-top: 6px; }

        .typeahead {
            position: relative;
        }
        .typeahead-list {
            position:absolute;
            top: 100%;
            left:0;
            right:0;
            background:#fff;
            border:1px solid #e5e7eb;
            border-radius: 10px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.08);
            max-height: 240px;
            overflow:auto;
            z-index: 50;
            display:none;
        }
        .typeahead-list button {
            width:100%;
            text-align:left;
            padding: 10px 12px;
            border:0;
            background: transparent;
            cursor:pointer;
        }
        .typeahead-list button:hover { background:#f1f5f9; }

        
/* Pagination (custom, no giant SVG arrows) */
.pagination-nav { margin-top: 14px; }
.pagination { list-style:none; display:flex; flex-wrap:wrap; gap:8px; padding:0; margin:0; align-items:center; }
.pagination li a, .pagination li span {
    display:inline-block;
    padding: 8px 12px;
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    background: #fff;
    color: #0f172a;
    text-decoration:none;
    font-size: 14px;
}
.pagination li a:hover { background:#f1f5f9; }
.pagination li.active span {
    background: var(--accent);
    border-color: var(--accent);
    color: #fff;
}
.pagination li.disabled span { color:#94a3b8; background:#f8fafc; }
nav[role="navigation"] svg { display:none !important; }

        @media (max-width: 900px) {
            .sidebar { position: fixed; z-index: 100; }
            .content { padding: 18px; }
            .form-grid { grid-template-columns: 1fr; }
        }
    
.alert { padding: 10px 12px; border-radius: 8px; font-size: 14px; }
.alert.success { background: #e8fff3; color: #065f46; border: 1px solid #a7f3d0; }
.alert.danger { background: #fff1f2; color: #9f1239; border: 1px solid #fecdd3; }


/* UI fixes: keep buttons readable on dark scheme */
.btn.secondary{
    background:#2b2f36 !important;
    color:#ffffff !important;
    border:1px solid rgba(255,255,255,.14) !important;
}
.btn.secondary:hover{
    background:#343a44 !important;
    color:#ffffff !important;
}

/* Autocomplete dropdown */
.ac-dd{
    position:absolute;
    left:0;
    right:0;
    top:100%;
    margin-top:6px;
    background:#111827;
    border:1px solid rgba(255,255,255,.12);
    border-radius:10px;
    box-shadow:0 12px 30px rgba(0,0,0,.35);
    z-index:50;
    max-height:260px;
    overflow:auto;
}
.ac-dd ul{ list-style:none; margin:0; padding:6px; }
.ac-dd li{
    padding:8px 10px;
    border-radius:8px;
    cursor:pointer;
    color:#e5e7eb;
    font-size:14px;
}
.ac-dd li:hover{ background:rgba(255,255,255,.08); }

        label.required::after { content: ' *'; color: #ef4444; font-weight: 700; }

    
        .nav-group { margin: 6px 0; }
        .nav-group-summary { list-style:none; cursor: default; display:flex; align-items:center; gap:10px; padding: 10px 12px; border-radius: 12px; color: var(--text); }
        .nav-group-summary::-webkit-details-marker { display:none; }
        .nav-group-summary:hover { background: rgba(255,255,255,.06); }
        .nav-group-summary.active { background: rgba(37,99,235,.22); }

                        .nav-sub { padding-left: 34px; display:flex; flex-direction:column; gap:4px; margin-top: 6px; }
        
        .nav-sub a { padding: 10px 12px; border-radius: 12px; display:flex; gap:10px; align-items:center; color: var(--text); text-decoration:none; }
        /* Child menu indentation */
        .nav-sub { padding-left: 34px; }
        .nav-sub a { padding: 9px 12px; border-radius: 10px; }

        .nav-sub a:hover { background: rgba(255,255,255,.06); }
        .nav-sub a.active { background: rgba(37,99,235,.22); }

        .tabs { display:flex; gap:10px; padding: 8px 0 2px 0; }
        .tab { display:inline-flex; align-items:center; justify-content:center; padding: 8px 12px; border-radius: 10px; text-decoration:none; color:#0f172a; background:#e2e8f0; font-weight:600; font-size:14px; }
        .tab.active { background: #2563eb; color: #fff; }
        .tab.disabled { opacity:.55; cursor:not-allowed; }
</style>

    <!-- WYSIWYG (Summernote) -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/summernote@0.8.20/dist/summernote-lite.min.css">
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/summernote@0.8.20/dist/summernote-lite.min.js"></script>
<script>
  document.addEventListener('DOMContentLoaded', function () {
    if (window.jQuery && jQuery.fn && jQuery.fn.summernote) {
      jQuery('textarea.wysiwyg').summernote({
        height: 420,
        toolbar: [
          ['style', ['style']],
          ['font', ['bold', 'italic', 'underline', 'clear']],
          ['para', ['ul', 'ol', 'paragraph']],
          ['insert', ['link', 'table']],
          ['view', ['codeview']]
        ]
      });
    }
  });
</script>

</head>
<body>
    <button id="sidebar-fab" class="sidebar-fab" type="button" aria-label="Open menu" title="Open menu">
        <span class="toggle-hamburger">☰</span>
    </button>
<div class="layout">
    <aside id="sidebar" class="sidebar">
	        @php
	            $u = auth()->user();
	            $s = $appSetting ?? null;
	            $companyName = $s->company_name ?? 'Company';
	            $logoPath = $s->logo_path ?? null;
	
	            // Parent-menu permissions
	            $canCatalog = $u && $u->hasPermission('manage_catalog');
	            $canMarketplace = $u && $u->hasPermission('manage_marketplace_api');
	            $canSettingsParent = $u && $u->hasPermission('manage_settings');
	        @endphp

	        <div class="brand">
	            <div class="brand-stack">
	                @if($logoPath)
	                    <img class="brand-logo" src="{{ asset('storage/'.$logoPath) }}" alt="Company Logo" style="background:transparent;">
	                @else
	                    <div class="brand-company">{{ $companyName }}</div>
	                @endif
	
	                <div class="brand-user">@if($u) Welcome Back, {{ $u->name }}! @endif</div>
	            </div>

	            <button class="toggle-btn" id="sidebarToggle" type="button" aria-label="Toggle Sidebar" title="Toggle Sidebar"><span class="toggle-hamburger" aria-hidden="true">☰</span></button>
	        </div>

	        <nav class="sidebar-nav">
	            <a href="{{ route('dashboard') }}" class="{{ request()->routeIs('dashboard') ? 'active' : '' }}">
	                <span>🏠</span><span class="label">Dashboard</span>
	            </a>

            @if($canCatalog)
            <div class="nav-group">
                <div class="nav-group-summary {{ (request()->routeIs('products.*') || request()->routeIs('categories.*') || request()->routeIs('manufacturers.*') || request()->routeIs('options.*')) ? 'active' : '' }}">
                    <span>🗃️</span><span class="label">Catalog</span>
                </div>
                <div class="nav-sub">
                    <a href="{{ route('categories.index') }}" class="{{ request()->routeIs('categories.*') ? 'active' : '' }}">
                            <span>🗂️</span><span class="label">Categories</span>
                        </a>
                    <a href="{{ route('products.index') }}" class="{{ request()->routeIs('products.*') ? 'active' : '' }}">
                            <span>📦</span><span class="label">Products</span>
                        </a>
                    <a href="{{ route('manufacturers.index') }}" class="{{ request()->routeIs('manufacturers.*') ? 'active' : '' }}">
                            <span>🏷️</span><span class="label">Manufacturers</span>
                        </a>
                    <a href="{{ route('options.index') }}" class="{{ request()->routeIs('options.*') ? 'active' : '' }}">
                            <span>🧩</span><span class="label">Options</span>
                        </a>
                </div>
            </div>
            @endif

            @if($canMarketplace)
            <div class="nav-group">
                <div class="nav-group-summary {{ (request()->routeIs('shopee.*') || request()->routeIs('lazada.*')) ? 'active' : '' }}">
                    <span>🛍️</span><span class="label">Marketplace API</span>
                </div>
                <div class="nav-sub">
                    <a href="{{ route('shopee.index') }}" class="{{ request()->routeIs('shopee.*') ? 'active' : '' }}">
                        <span>🛒</span><span class="label">Shopee</span>
                    </a>
                    <a href="{{ route('lazada.index') }}" class="{{ request()->routeIs('lazada.index') ? 'active' : '' }}">
                        <span>🟦</span><span class="label">Lazada API</span>
                    </a>
                    <a href="{{ route('lazada.listings.index') }}" class="{{ request()->routeIs('lazada.listings.*') ? 'active' : '' }}">
                        <span>📄</span><span class="label">Lazada Listings</span>
                    </a>
                    <a href="{{ route('lazada.categories.index') }}" class="{{ request()->routeIs('lazada.categories.*') ? 'active' : '' }}">
                        <span>🗂️</span><span class="label">Lazada Categories</span>
                    </a>
                    <a href="{{ route('lazada.orders.index') }}" class="{{ request()->routeIs('lazada.orders.*') ? 'active' : '' }}">
                        <span>🧾</span><span class="label">Lazada Orders</span>
                    </a>
                </div>
            </div>
            @endif

            @if($canSettingsParent)
            <div class="nav-group">
                <div class="nav-group-summary {{ (request()->routeIs('users.*') || request()->routeIs('user_groups.*') || request()->routeIs('settings.*')) ? 'active' : '' }}">
                    <span>⚙️</span><span class="label">Settings</span>
                </div>
                <div class="nav-sub">
                    <a href="{{ route('users.index') }}" class="{{ request()->routeIs('users.*') ? 'active' : '' }}">
                        <span>👤</span><span class="label">Users</span>
                    </a>
                    <a href="{{ route('user_groups.index') }}" class="{{ request()->routeIs('user_groups.*') ? 'active' : '' }}">
                        <span>🔐</span><span class="label">User Groups</span>
                    </a>
                    <a href="{{ route('settings.edit') }}" class="{{ request()->routeIs('settings.*') ? 'active' : '' }}">
                        <span>🧰</span><span class="label">Website Setting</span>
                    </a>

                    <a href="{{ route('error_log.index') }}" class="{{ request()->routeIs('error_log.*') ? 'active' : '' }}">
                        <span>🧾</span><span class="label">Error Log</span>
                    </a>
                </div>
            </div>
	            @endif
            </nav>
    
        @auth
            <div class="sidebar-footer">
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="logout-btn nav">
                        <span>🚪</span><span class="label">Logout</span>
                    </button>
                </form>
            </div>
        @endauth

</aside>

    <main id="content" class="content">
        
        <div class="topbar">
            <div></div>
            <div></div>
        </div>
@if(session('status'))
            <div class="alert success" style="margin-bottom:12px;">{{ session('status') }}</div>
        @endif

        @if(session('error'))
            <div class="alert danger" style="margin-bottom:12px;">{{ session('error') }}</div>
        @endif

        @if($errors->any())
            <div class="alert danger" style="margin-bottom:12px;">
                <ul style="margin:0; padding-left:18px;">
                    @foreach($errors->all() as $e)
                        <li>{{ $e }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @yield('content')


    </main>
</div>

<script>
(function () {
    const sidebar = document.getElementById('sidebar');
    const content = document.getElementById('content');
    const btn = document.getElementById('sidebarToggle');
    const fab = document.getElementById('sidebar-fab');

    if (!sidebar || !content || !btn) return;

    const key = 'ngd_sidebar_collapsed';

    function applyState(isCollapsed) {
        sidebar.classList.toggle('collapsed', isCollapsed);
        content.classList.toggle('collapsed', isCollapsed);

        if (fab) {
            fab.style.display = isCollapsed ? 'flex' : 'none';
        }

        localStorage.setItem(key, isCollapsed ? '1' : '0');
    }

    const collapsed = localStorage.getItem(key) === '1';
    applyState(collapsed);

    btn.addEventListener('click', function () {
        applyState(!sidebar.classList.contains('collapsed'));
    });

    if (fab) {
        fab.addEventListener('click', function () {
            applyState(false);
        });
    }
})();
</script>

@stack('scripts')
<script>
(function(){
  function setupManufacturerSearch(){
    var input = document.getElementById('manufacturer-search');
    var dd = document.getElementById('manufacturer-search-dd');
    var list = document.getElementById('manufacturer-search-list');
    if(!input || !dd || !list) return;

    var last = '';
    var timer = null;

    function hide(){ dd.style.display='none'; }
    function show(){ dd.style.display='block'; }
    function render(items){
      list.innerHTML = '';
      if(!items || !items.length){ hide(); return; }
      items.forEach(function(it){
        var li=document.createElement('li');
        li.textContent = it.name;
        li.addEventListener('mousedown', function(e){
          e.preventDefault();
          input.value = it.name;
          hide();
        });
        list.appendChild(li);
      });
      show();
    }

    async function fetchItems(q){
      try{
        var res = await fetch('/api/catalog/manufacturers?term=' + encodeURIComponent(q), {headers:{'Accept':'application/json'}, credentials:'same-origin'});
        if(!res.ok) return [];
        return await res.json();
      }catch(e){ return []; }
    }

    input.addEventListener('input', function(){
      var q = (input.value || '').trim();
      if(q.length < 1){ hide(); return; }
      if(q === last) return;
      last = q;
      clearTimeout(timer);
      timer = setTimeout(async function(){
        var items = await fetchItems(q);
        render(items);
      }, 120);
    });

    document.addEventListener('click', function(e){
      if(!dd.contains(e.target) && e.target !== input){ hide(); }
    });
  }

  document.addEventListener('DOMContentLoaded', setupManufacturerSearch);
})();
</script>

<script>
(function(){
  // Product forms use a simpler typeahead (manufacturer_search/category_search).
  // Keep this logic in the layout so Add/Edit behave the same and require no page-specific scripts.
  function setupTypeahead(opts){
    var input = document.getElementById(opts.inputId);
    var hidden = document.getElementById(opts.hiddenId);
    var list = document.getElementById(opts.listId);
    if(!input || !hidden || !list) return;

    var last = '';
    var timer = null;

    function clearList(){ list.innerHTML = ''; list.style.display='none'; }
    function showList(){ list.style.display='block'; }
    function hideList(){ list.style.display='none'; }

    // If user types/clears manually, assume it's not selected -> reset id.
    input.addEventListener('input', function(){
      hidden.value = '0';
    });

    function render(items){
      list.innerHTML = '';
      if(!items || !items.length){ hideList(); return; }
      items.forEach(function(it){
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.textContent = it.name;
        btn.addEventListener('mousedown', function(e){
          // mousedown so it triggers before input loses focus
          e.preventDefault();
          input.value = it.name;
          hidden.value = String(it.id);
          clearList();
        });
        list.appendChild(btn);
      });
      showList();
    }

    async function fetchItems(q){
      try{
        var res = await fetch(opts.url + encodeURIComponent(q), {headers:{'Accept':'application/json'}, credentials:'same-origin'});
        if(!res.ok) return [];
        var data = await res.json();
        // Normalize to {id,name}
        // manufacturers() returns {manufacturer_id,name}
        // categories() returns {category_id,path}
        // optionValues() returns {option_value_id,name}
        return (data || []).map(function(r){
          return {
            id: r.id ?? r.manufacturer_id ?? r.category_id ?? r.option_id ?? r.value_id ?? r.option_value_id,
            name: r.name ?? r.path
          };
        }).filter(function(r){ return r.id !== undefined && r.name; });
      }catch(e){ return []; }
    }

    input.addEventListener('focus', function(){
      var q = (input.value || '').trim();
      if(q.length >= 1){
        // trigger a refresh when focusing back
        last = '';
        input.dispatchEvent(new Event('keyup'));
      }
    });

    input.addEventListener('keyup', function(){
      var q = (input.value || '').trim();
      if(q.length < 1){ hideList(); return; }
      if(q === last) return;
      last = q;
      clearTimeout(timer);
      timer = setTimeout(async function(){
        var items = await fetchItems(q);
        render(items);
      }, 120);
    });

    document.addEventListener('click', function(e){
      if(e.target !== input && !list.contains(e.target)) hideList();
    });
  }

  document.addEventListener('DOMContentLoaded', function(){
    setupTypeahead({
      inputId: 'manufacturer_search',
      hiddenId: 'manufacturer_id',
      listId: 'manufacturer_list',
      url: '/api/catalog/manufacturers?term=',
    });
    setupTypeahead({
      inputId: 'category_search',
      hiddenId: 'category_id',
      listId: 'category_list',
      url: '/api/catalog/categories?term=',
    });
  });
})();
</script>
</body>
</html>