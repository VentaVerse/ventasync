@php
  $pimInitial = $productImages ?? [];
  $pimToken = $pimToken ?? '';
  $pimProductId = (int) ($pimProductId ?? 0);
@endphp

<h3 class="section-title mt-0">Images</h3>

<div class="pim-wrap">
  <input type="hidden" name="pim_token" value="{{ $pimToken }}">
  <input type="hidden" name="images_json" id="pimImagesJson" value="">

  <div class="pim-toolbar">
    <button class="btn secondary" type="button" id="pimOpenManagerBtn">Image Manager</button>
    <div class="hint" style="margin-left:auto;">Drag to reorder. First image becomes the main image.</div>
  </div>

  <div class="pim-grid-wrap">
    <div class="pim-grid" id="pimGrid"></div>
    <div class="pim-empty" id="pimEmpty">
      No images selected. Click&nbsp;<strong>Image Manager</strong>&nbsp;to add images.
    </div>
  </div>
</div>

<div class="pim-modal" id="pimServerModal" aria-hidden="true">
  <div class="pim-modal-backdrop" data-close="1"></div>
  <div class="pim-modal-card">
    <div class="pim-modal-header">
      <div style="font-weight:700;">Image Manager</div>
      <div class="pim-modal-actions">
        <span class="pim-modal-msg" id="pimModalMsg" style="display:none;" aria-live="polite"></span>
        <button class="btn secondary" type="button" data-close="1">Close</button>
      </div>
    </div>
    <div class="pim-modal-body" id="pimModalBody">
      <div class="hint" style="margin-bottom:10px; opacity:.85;">Navigate to a folder, then upload or drag files here to save into that folder.</div>

      <div class="pim-server-nav" id="pimServerNav">
        <button class="btn secondary" type="button" id="pimServerUpBtn" disabled>Up</button>
        <div class="pim-server-path" id="pimServerPath">/</div>
      </div>

      <div class="pim-server-upload" style="display:flex; align-items:center; gap:10px; margin: 0 0 12px; flex-wrap:wrap;">
        <input type="file" id="pimServerUploadInput" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" multiple style="display:none;">
        <button class="btn secondary" type="button" id="pimServerUploadBtn">Upload (Drag/Drop or Browse)</button>
        <button class="btn secondary" type="button" id="pimServerUrlBtn">Upload from URL</button>
        <div class="hint" style="margin:0; opacity:.85;">Files land in the folder shown above.</div>
      </div>

      <div class="pim-server-filter-bar">
        <input type="text" id="pimServerSearch" class="pim-server-search" placeholder="Search images…">
        <select id="pimServerSort" class="pim-server-sort">
          <option value="newest">Newest first</option>
          <option value="oldest">Oldest first</option>
          <option value="name_asc">Name A–Z</option>
          <option value="name_desc">Name Z–A</option>
        </select>
      </div>

      <div class="pim-server-section-title" id="pimServerFoldersTitle">Folders</div>
      <div class="pim-server-grid" id="pimServerFolders"></div>

      <div class="pim-server-section-title" style="margin-top:14px;" id="pimServerFilesTitle">Images</div>
      <div class="pim-server-grid" id="pimServerGrid"></div>
      <div class="pim-server-empty" id="pimServerEmpty">No folders or images found.</div>

      <div class="pim-drop-overlay" id="pimDropOverlay" aria-hidden="true">
        <div class="pim-drop-overlay-inner">Drop files to upload into this folder</div>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>
<script>
(function(){
  const csrf = @json(csrf_token());
  const initialPaths = @json($pimInitial);
  const PIM_PRODUCT_ID = @json($pimProductId);
  const PIM_TOKEN = @json($pimToken);

  const grid = document.getElementById('pimGrid');
  const empty = document.getElementById('pimEmpty');
  const imagesJson = document.getElementById('pimImagesJson');

  const modal = document.getElementById('pimServerModal');
  const modalBody = document.getElementById('pimModalBody');
  const serverGrid = document.getElementById('pimServerGrid');
  const serverFolders = document.getElementById('pimServerFolders');
  const serverEmpty = document.getElementById('pimServerEmpty');
  const serverPath = document.getElementById('pimServerPath');
  const serverSearch = document.getElementById('pimServerSearch');
  const serverSort = document.getElementById('pimServerSort');
  const serverUpBtn = document.getElementById('pimServerUpBtn');
  const serverUploadBtn = document.getElementById('pimServerUploadBtn');
  const serverUploadInput = document.getElementById('pimServerUploadInput');
  const serverUrlBtn = document.getElementById('pimServerUrlBtn');
  const dropOverlay = document.getElementById('pimDropOverlay');

  let currentServerPath = '';
  let cachedServerFiles = [];
  let cachedServerFolders = [];

  function getManufacturerId() {
    const el = document.getElementById('manufacturer_id');
    const v = el ? String(el.value || '').trim() : '';
    const n = parseInt(v, 10);
    return Number.isFinite(n) ? n : 0;
  }

  function toUrl(path){
    if (!path) return '';
    const parts = String(path).split('/').map(s => encodeURIComponent(s));
    return @json(url('/')) + '/storage/' + parts.join('/');
  }

  function showToast(msg, kind){
    const modalMsg = document.getElementById('pimModalMsg');
    if (!modalMsg) return;
    modalMsg.classList.remove('error','info');
    if (kind) modalMsg.classList.add(kind);
    modalMsg.textContent = msg;
    modalMsg.style.display = 'inline';
    clearTimeout(window.__pimMsgT);
    window.__pimMsgT = setTimeout(()=>{ modalMsg.style.display='none'; }, 1600);
  }

  let items = (initialPaths || []).map(p => ({path: p, url: toUrl(p)}));

  function syncHidden(){
    imagesJson.value = JSON.stringify(items.map(x => x.path));
  }

  function render(){
    grid.innerHTML = '';
    if (!items.length) empty.classList.remove('hidden'); else empty.classList.add('hidden');

    items.forEach((it, idx) => {
      const div = document.createElement('div');
      div.className = 'pim-item';
      div.dataset.path = it.path;

      const img = document.createElement('img');
      img.className = 'pim-thumb';
      img.src = it.url;
      img.loading = 'lazy';

      const badge = document.createElement('div');
      badge.className = 'pim-badge';
      badge.textContent = (idx === 0) ? 'Main' : ('#' + (idx+1));

      const remove = document.createElement('button');
      remove.type = 'button';
      remove.className = 'pim-remove';
      remove.textContent = '\u00D7';
      remove.addEventListener('click', (e) => {
        e.preventDefault();
        items = items.filter(x => x.path !== it.path);
        render();
        syncHidden();
      });

      const top = document.createElement('div');
      top.className = 'pim-item-top';
      top.appendChild(badge);
      top.appendChild(remove);

      div.appendChild(top);
      const wrap = document.createElement('div');
      wrap.className = 'pim-thumb-wrap';
      wrap.appendChild(img);
      div.appendChild(wrap);
      grid.appendChild(div);
    });

    syncHidden();
  }

  function buildFileCard(f) {
    const card = document.createElement('div');
    card.className = 'pim-server-item';

    const thumb = document.createElement('img');
    thumb.src = f.url || '';
    thumb.loading = 'lazy';
    card.appendChild(thumb);

    const label = document.createElement('div');
    label.className = 'label';
    label.textContent = f.name || '';
    card.appendChild(label);

    card.addEventListener('click', () => {
      if (!items.some(x => x.path === f.path)) {
        items.push({path: f.path, url: f.url});
        render();
        showToast('Image Added','info');
      } else {
        showToast('Already Added','info');
      }
    });

    const del = document.createElement('button');
    del.type = 'button';
    del.className = 'pim-server-delete';
    del.textContent = '\u00D7';
    del.title = 'Delete from server';
    del.addEventListener('click', (e) => {
      e.stopPropagation();
      confirmModal('Delete "' + (f.name||'') + '" from server? This cannot be undone.').then(async function(ok) {
        if (!ok) return;
        const resp = await fetch(@json(route('products.images.delete')), {
          method: 'POST',
          headers: {'Content-Type':'application/json', 'X-CSRF-TOKEN': csrf, 'Accept':'application/json', 'X-Requested-With':'XMLHttpRequest'},
          body: JSON.stringify({ path: f.path })
        });
        const data = await resp.json().catch(() => ({}));
        if (!resp.ok || !data.ok) { showToast(data.message || 'Delete failed','error'); return; }
        cachedServerFiles = cachedServerFiles.filter(x => x.path !== f.path);
        renderServerFiles();
        items = items.filter(x => x.path !== f.path);
        render();
        showToast('Deleted','info');
      });
    });
    card.appendChild(del);

    return card;
  }

  function buildFolderCard(d) {
    const card = document.createElement('div');
    card.className = 'pim-server-item';

    const icon = document.createElement('div');
    icon.className = 'icon';
    icon.textContent = '\u{1F4C1}';
    card.appendChild(icon);

    const label = document.createElement('div');
    label.className = 'label';
    label.textContent = d.name || '';
    card.appendChild(label);

    card.addEventListener('click', () => {
      loadServerDir(d.path || '');
    });

    return card;
  }

  function renderServerFiles() {
    serverGrid.innerHTML = '';
    serverEmpty.classList.remove('show');

    const q = (serverSearch.value || '').trim().toLowerCase();
    const sort = serverSort.value || 'newest';

    let filtered = cachedServerFiles;
    if (q) {
      filtered = filtered.filter(f => (f.name || '').toLowerCase().includes(q));
    }

    filtered = [...filtered];
    if (sort === 'newest') filtered.sort((a,b) => (b.modified||0) - (a.modified||0));
    else if (sort === 'oldest') filtered.sort((a,b) => (a.modified||0) - (b.modified||0));
    else if (sort === 'name_asc') filtered.sort((a,b) => (a.name||'').localeCompare(b.name||''));
    else if (sort === 'name_desc') filtered.sort((a,b) => (b.name||'').localeCompare(a.name||''));

    filtered.forEach(f => serverGrid.appendChild(buildFileCard(f)));

    if (!cachedServerFolders.length && !filtered.length) {
      serverEmpty.classList.add('show');
    }
  }

  async function loadServerDir(path, startAtManufacturer){
    const mid = getManufacturerId();
    currentServerPath = String(path || '').replace(/\\/g,'/').replace(/^\/+|\/+$/g,'');

    serverGrid.innerHTML = '';
    serverFolders.innerHTML = '';
    serverEmpty.classList.remove('show');
    serverSearch.value = '';

    const u = new URL(@json(route('products.images.browse')), window.location.origin);
    if (mid) u.searchParams.set('manufacturer_id', mid);
    if (currentServerPath) u.searchParams.set('path', currentServerPath);
    if (startAtManufacturer) u.searchParams.set('start_at_manufacturer', '1');

    const resp = await fetch(u.toString(), {headers: {'X-CSRF-TOKEN': csrf, 'Accept':'application/json', 'X-Requested-With':'XMLHttpRequest'}});
    const data = await resp.json().catch(() => ({}));

    cachedServerFolders = (data && data.folders) ? data.folders : [];
    cachedServerFiles = (data && data.files) ? data.files : [];
    const parent = (data && Object.prototype.hasOwnProperty.call(data,'parent')) ? data.parent : null;

    if (data && typeof data.path === 'string') {
      currentServerPath = data.path;
    }

    serverPath.textContent = '/' + (currentServerPath ? currentServerPath : '');
    if (parent === null) {
      serverUpBtn.disabled = true;
      serverUpBtn.dataset.parent = '';
    } else {
      serverUpBtn.disabled = false;
      serverUpBtn.dataset.parent = String(parent || '');
    }

    cachedServerFolders.forEach(d => serverFolders.appendChild(buildFolderCard(d)));
    renderServerFiles();
  }

  async function openServerModal(){
    const mid = getManufacturerId();
    if (!mid) { showFlashError('Please select a Manufacturer first.'); return; }
    modal.setAttribute('aria-hidden','false');
    await loadServerDir('', true);
  }

  function attachIfNew(path, url) {
    if (!path) return;
    if (items.some(x => x.path === path)) return;
    items.push({path: path, url: url || toUrl(path)});
    render();
  }

  async function uploadToCurrentFolder(file){
    const ext = (file.name.split('.').pop() || '').toLowerCase();
    if (!['jpg','jpeg','png','webp'].includes(ext)) {
      showToast('Only JPG, PNG, and WebP images are allowed.','error');
      return;
    }

    const fd = new FormData();
    fd.append('file', file);
    fd.append('path', currentServerPath || '');

    const resp = await fetch(@json(route('products.images.upload_to_catalog')), {
      method: 'POST',
      headers: {'X-CSRF-TOKEN': csrf, 'Accept':'application/json', 'X-Requested-With':'XMLHttpRequest'},
      body: fd
    });

    const data = await resp.json().catch(() => ({}));
    if (!resp.ok || !data.ok) {
      showToast(data.message || 'Upload failed.','error');
      return;
    }

    attachIfNew(data.path, data.url);
    showToast('Uploaded & attached','info');
  }

  async function importUrlToCurrentFolder(url){
    const resp = await fetch(@json(route('products.images.import_url_to_catalog')), {
      method: 'POST',
      headers: {'Content-Type':'application/json', 'X-CSRF-TOKEN': csrf, 'Accept':'application/json', 'X-Requested-With':'XMLHttpRequest'},
      body: JSON.stringify({ url: url, path: currentServerPath || '' })
    });
    const data = await resp.json().catch(() => ({}));
    if (!resp.ok || !data.ok) {
      showToast(data.message || 'Import failed.','error');
      return;
    }
    attachIfNew(data.path, data.url);
    showToast('Imported & attached','info');
  }

  // Buttons
  document.getElementById('pimOpenManagerBtn').addEventListener('click', openServerModal);

  serverUpBtn.addEventListener('click', async () => {
    if (serverUpBtn.disabled) return;
    const p = String(serverUpBtn.dataset.parent || '');
    await loadServerDir(p);
  });

  if (serverUploadBtn && serverUploadInput) {
    serverUploadBtn.addEventListener('click', () => serverUploadInput.click());
    serverUploadInput.addEventListener('change', async () => {
      const files = Array.from(serverUploadInput.files || []);
      if (!files.length) return;

      for (const f of files) {
        await uploadToCurrentFolder(f);
      }

      serverUploadInput.value = '';
      await loadServerDir(currentServerPath || '');
    });
  }

  if (serverUrlBtn) {
    serverUrlBtn.addEventListener('click', async () => {
      const url = prompt('Paste image URL (JPG/PNG/WebP):');
      if (!url) return;
      await importUrlToCurrentFolder(url);
      await loadServerDir(currentServerPath || '');
    });
  }

  // Drag & drop into the modal body → upload to current folder
  let dragDepth = 0;
  function hasFiles(e){
    const dt = e.dataTransfer;
    if (!dt) return false;
    const types = dt.types;
    if (!types) return false;
    for (let i = 0; i < types.length; i++) {
      if (types[i] === 'Files') return true;
    }
    return false;
  }
  modalBody.addEventListener('dragenter', (e) => {
    if (!hasFiles(e)) return;
    e.preventDefault();
    dragDepth++;
    dropOverlay.setAttribute('aria-hidden','false');
  });
  modalBody.addEventListener('dragover', (e) => {
    if (!hasFiles(e)) return;
    e.preventDefault();
  });
  modalBody.addEventListener('dragleave', (e) => {
    if (!hasFiles(e)) return;
    dragDepth = Math.max(0, dragDepth - 1);
    if (dragDepth === 0) dropOverlay.setAttribute('aria-hidden','true');
  });
  modalBody.addEventListener('drop', async (e) => {
    if (!hasFiles(e)) return;
    e.preventDefault();
    dragDepth = 0;
    dropOverlay.setAttribute('aria-hidden','true');
    const files = Array.from(e.dataTransfer.files || []);
    for (const f of files) {
      await uploadToCurrentFolder(f);
    }
    await loadServerDir(currentServerPath || '');
  });

  // Search & sort
  let searchTimer = null;
  serverSearch.addEventListener('input', () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => renderServerFiles(), 200);
  });
  serverSort.addEventListener('change', () => renderServerFiles());

  // Sortable
  new Sortable(grid, {
    animation: 150,
    onEnd: function(){
      const newOrder = [];
      grid.querySelectorAll('.pim-item').forEach(el => {
        const p = el.dataset.path;
        const found = items.find(x => x.path === p);
        if (found) newOrder.push(found);
      });
      items = newOrder;
      render();
    }
  });

  // Modal close
  modal.addEventListener('click', (e) => {
    const t = e.target;
    if (t && t.getAttribute && t.getAttribute('data-close') === '1') {
      modal.setAttribute('aria-hidden','true');
    }
  });

  render();
})();
</script>
