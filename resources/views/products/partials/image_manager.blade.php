@php
  $pimInitial = $productImages ?? [];
  $pimToken = $pimToken ?? '';
  $pimProductId = (int) ($pimProductId ?? 0);
@endphp

<h3 class="section-title mt-0">Images</h3>

<div class="pim-wrap">
  <input type="hidden" name="pim_token" value="{{ $pimToken }}">
  <div class="pim-toolbar">
    <button class="btn secondary" type="button" id="pimAddLocalBtn">Upload (Drag/Drop or Browse)</button>
    <button class="btn secondary" type="button" id="pimAddUrlBtn">Upload from URL</button>
    <button class="btn secondary" type="button" id="pimAddServerBtn">Upload from Server</button>
    <div class="hint" style="margin-left:auto;">Drag to reorder. First image becomes the main image.</div>
</div>

  <input type="file" id="pimFileInput" accept=".jpg,.jpeg,.png,image/jpeg,image/png" multiple style="display:none;">
  <input type="hidden" name="images_json" id="pimImagesJson" value="">

  <div class="pim-drop" id="pimDrop">
    <div class="pim-grid" id="pimGrid"></div>
    <div class="pim-empty" id="pimEmpty">
      Drop images here, or click “Upload”.
    </div>
  </div>
</div>

<div class="pim-modal" id="pimServerModal" aria-hidden="true">
  <div class="pim-modal-backdrop" data-close="1"></div>
  <div class="pim-modal-card">
    <div class="pim-modal-header">
      <div style="font-weight:700;">Choose from Server</div>
      <div class="pim-modal-actions">
        <span class="pim-modal-msg" id="pimModalMsg" style="display:none;" aria-live="polite"></span>
        <button class="btn secondary" type="button" data-close="1">Close</button>
      </div>
    </div>
    <div class="pim-modal-body">
      <div class="hint" style="margin-bottom:10px; opacity:.85;">Images are loaded from your Laravel storage folder (catalog/*).</div>
      <div class="pim-server-nav" id="pimServerNav">
        <button class="btn secondary" type="button" id="pimServerUpBtn" disabled>Up</button>
        <div class="pim-server-path" id="pimServerPath">/</div>
      </div>

      <div class="pim-server-upload" style="display:flex; align-items:center; gap:10px; margin: 0 0 12px;">
        <input type="file" id="pimServerUploadInput" accept=".jpg,.jpeg,.png,image/jpeg,image/png" multiple style="display:none;">
        <button class="btn secondary" type="button" id="pimServerUploadBtn">Upload to this folder</button>
        <div class="hint" style="margin:0; opacity:.85;">Uploads to the folder you’re currently viewing.</div>
      </div>

      <div class="pim-server-section-title" id="pimServerFoldersTitle">Folders</div>
      <div class="pim-server-grid" id="pimServerFolders"></div>

      <div class="pim-server-section-title" style="margin-top:14px;" id="pimServerFilesTitle">Images</div>
      <div class="pim-server-grid" id="pimServerGrid"></div>
      <div class="pim-server-empty" id="pimServerEmpty">No folders or images found.</div>
    </div>
  </div>
</div>

<style>
.pim-wrap {
  margin-top: 10px;
  border: 1px solid var(--border-light, #f1f5f9);
  background: var(--surface-alt, #f8fafc);
  border-radius: var(--radius-lg, 16px);
  padding: 16px;
  box-shadow: var(--shadow-sm, 0 1px 3px rgba(0,0,0,.06));
}
.pim-toolbar { display:flex; gap:10px; align-items:center; margin-bottom:10px; flex-wrap:wrap; }

.pim-drop {
  border: 2px dashed rgba(255,255,255,0.22);
  border-radius: 16px;
  padding: 16px;
  background: rgba(0,0,0,0.10);
  position: relative;
  min-height: 124px;
}
.pim-drop.dragover { border-color: rgba(88, 160, 255, 0.65); background: rgba(88, 160, 255, 0.06); }

.pim-grid { display:grid; grid-template-columns: repeat(auto-fill, minmax(110px, 1fr)); gap:12px; }
.pim-item {
  border: 1px solid rgba(255,255,255,0.14);
  border-radius: 14px;
  overflow: hidden;
  background: rgba(0,0,0,0.12);
  cursor: grab;
  position: relative;
}
.pim-item:active { cursor: grabbing; }

.pim-item-top {
  display:flex;
  align-items:center;
  justify-content:space-between;
  padding: 6px 8px;
  background: rgba(0,0,0,0.12);
}

.pim-thumb-wrap {
  width: 100%;
  aspect-ratio: 1 / 1;
  overflow: hidden;
}

.pim-thumb {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display:block;
}
.pim-badge {
  /* now shown in header row (not over the image) */
  background: rgba(0,0,0,0.65);
  border:1px solid rgba(255,255,255,0.12);
  border-radius: 999px;
  padding: 4px 8px;
  font-size: 12px;
  color: #fff;
  line-height: 1;
}
.pim-remove {
  /* now shown in header row (not over the image) */
  border: none;
  background: transparent;
  color: #ff3b3b;
  font-size: 22px;
  line-height: 1;
  padding: 0 4px;
  cursor: pointer;
}

.pim-empty {
  position:absolute; inset: 0;
  display:flex; align-items:center; justify-content:center;
  color: rgba(255,255,255,0.75);
  pointer-events:none;
}
.pim-empty.hidden { display:none; }

/* Modal */
.pim-modal { display:none; position:fixed; inset:0; z-index: 9999; }
.pim-modal[aria-hidden="false"] { display:block; }
.pim-modal-backdrop { position:absolute; inset:0; background: rgba(0,0,0,0.55); }
.pim-modal-card {
  position:relative;
  max-width: 980px;
  margin: 6vh auto;
  max-height: 88vh;
  display:flex;
  flex-direction: column;
  background: rgba(20,20,20,0.96);
  border: 1px solid rgba(255,255,255,0.12);
  border-radius: 14px;
  box-shadow: 0 12px 50px rgba(0,0,0,0.5);

  color: rgba(255,255,255,0.92);}
.pim-modal-header {
  display:flex; align-items:center; justify-content:space-between;
  padding: 14px 14px;
  border-bottom: 1px solid rgba(255,255,255,0.08);
}
.pim-modal-actions{ display:flex; align-items:center; gap:10px; }
.pim-modal-msg{ font-weight:700; color:#2ecc71; font-size:13px; }
.pim-modal-msg.error{ color:#e74c3c; }
.pim-modal-msg.info{ color:#2ecc71; }
.pim-modal-body {
  padding: 14px;
  flex: 1 1 auto;
  overflow:auto;
}
.pim-server-nav{ display:flex; align-items:center; gap:10px; margin: 8px 0 12px; }
.pim-server-path{ font-size: 12px; opacity: .85; white-space: nowrap; overflow:hidden; text-overflow: ellipsis; }
.pim-server-section-title{ font-size: 12px; font-weight: 700; opacity: .85; margin: 10px 0 8px; }
.pim-server-grid {
  display:grid;
  grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
  gap: 12px;
}
.pim-server-item {
  border: 1px solid rgba(255,255,255,0.10);
  background: rgba(0,0,0,0.22);
  border-radius: 12px;
  overflow:hidden;
  cursor:pointer;
}
.pim-server-item:hover { border-color: rgba(88,160,255,0.45); }
.pim-server-item img { width:100%; aspect-ratio: 1/1; object-fit: cover; display:block; }
.pim-server-item .label { padding: 8px; font-size: 12px; opacity: .85; white-space:nowrap; overflow:hidden; text-overflow: ellipsis; }
.pim-server-item .icon {
  width:100%; aspect-ratio: 1/1;
  display:flex; align-items:center; justify-content:center;
  font-size: 46px;
  opacity: .9;
}
.pim-server-empty { margin-top: 12px; opacity: .75; display:none; }
.pim-server-empty.show { display:block; }

/* Ensure modal text is readable on dark background */
.pim-modal-card, .pim-modal-card * { color: rgba(255,255,255,0.92); }
.pim-modal-card .muted, .pim-modal-card small, .pim-modal-card .hint { color: rgba(255,255,255,0.72) !important; }
.pim-modal-header h3 { margin:0; font-size: 16px; font-weight: 700; }
.pim-modal-header .btn { color: rgba(255,255,255,0.92); }
.pim-modal-header .btn.secondary { border-color: rgba(255,255,255,0.18); }
.pim-server-empty { color: rgba(255,255,255,0.78); }


.pim-notice{
  display:none;
  margin: 8px 0 12px 0;
  padding: 10px 12px;
  border-radius: 12px;
  border: 1px solid rgba(255,255,255,0.14);
  background: rgba(88, 160, 255, 0.10);
  color: rgba(255,255,255,0.90);
  font-size: 13px;
}
.pim-notice.show{ display:block; }

</style>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>
<script>
(function(){
  const csrf = @json(csrf_token());
  const initialPaths = @json($pimInitial);
  const PIM_PRODUCT_ID = @json($pimProductId);
  const PIM_TOKEN = @json($pimToken);
  const grid = document.getElementById('pimGrid');
  const empty = document.getElementById('pimEmpty');
  const input = document.getElementById('pimFileInput');
  const drop = document.getElementById('pimDrop');
  const imagesJson = document.getElementById('pimImagesJson');

  const modal = document.getElementById('pimServerModal');
  const serverGrid = document.getElementById('pimServerGrid');
  const serverFolders = document.getElementById('pimServerFolders');
  const serverEmpty = document.getElementById('pimServerEmpty');
  const serverPath = document.getElementById('pimServerPath');
  const serverUpBtn = document.getElementById('pimServerUpBtn');
  const serverUploadBtn = document.getElementById('pimServerUploadBtn');
  const serverUploadInput = document.getElementById('pimServerUploadInput');

  let currentServerPath = '';

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

    function getPimToken() {
    const el = document.querySelector('input[name="pim_token"]');
    return el ? String(el.value || '').trim() : '';
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
      // High-resolution rendering: ask browser to downscale cleanly
      img.src = it.url;
      img.loading = 'lazy';

      const badge = document.createElement('div');
      badge.className = 'pim-badge';
      badge.textContent = (idx === 0) ? 'Main' : ('#' + (idx+1));

      const remove = document.createElement('button');
      remove.type = 'button';
      remove.className = 'pim-remove';
      remove.innerHTML = '&times;';
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

  async function uploadFile(file){
    const mid = getManufacturerId();
    if (!mid) {
      alert('Please select a Manufacturer first.');
      return;
    }

    const fd = new FormData();
    fd.append('manufacturer_id', mid);
    if (PIM_PRODUCT_ID && Number(PIM_PRODUCT_ID) > 0) fd.append('product_id', String(PIM_PRODUCT_ID));
    if (PIM_TOKEN) fd.append('token', String(PIM_TOKEN));
    fd.append('file', file);
    const resp = await fetch(@json(route('products.images.upload')), {
      method: 'POST',
      headers: {'X-CSRF-TOKEN': csrf, 'Accept':'application/json', 'X-Requested-With':'XMLHttpRequest'},
      body: fd
    });

    const data = await resp.json().catch(() => ({}));
    if (!resp.ok || !data.ok) {
      alert(data.message || 'Upload failed.');
      return;
    }

    items.push({path: data.path, url: data.url});
    render();
    showToast('Image Added','info');
    }

  async function importFromUrl(url){
    const mid = getManufacturerId();
    if (!mid) { alert('Please select a Manufacturer first.'); return; }

    const resp = await fetch(@json(route('products.images.import_url')), {
      method: 'POST',
      headers: {'Content-Type':'application/json', 'X-CSRF-TOKEN': csrf, 'Accept':'application/json', 'X-Requested-With':'XMLHttpRequest'},
      body: JSON.stringify({ manufacturer_id: mid, url: url, product_id: (PIM_PRODUCT_ID && Number(PIM_PRODUCT_ID) > 0 ? Number(PIM_PRODUCT_ID) : null), token: (PIM_TOKEN || null) })
    });

    const data = await resp.json().catch(() => ({}));
    if (!resp.ok || !data.ok) { alert(data.message || 'Import failed.'); return; }

    items.push({path: data.path, url: data.url});
    render();
    showToast('Image Added','info');
    }

  async function loadServerDir(path, startAtManufacturer){
    const mid = getManufacturerId();
    if (!mid) { alert('Please select a Manufacturer first.'); return; }
    currentServerPath = String(path || '').replace(/\\/g,'/').replace(/^\/+|\/+$/g,'');

    serverGrid.innerHTML = '';
    serverFolders.innerHTML = '';
    serverEmpty.classList.remove('show');

    const u = new URL(@json(route('products.images.browse')), window.location.origin);
    u.searchParams.set('manufacturer_id', mid);
    if (currentServerPath) u.searchParams.set('path', currentServerPath);
    if (startAtManufacturer) u.searchParams.set('start_at_manufacturer', '1');

    const resp = await fetch(u.toString(), {headers: {'X-CSRF-TOKEN': csrf, 'Accept':'application/json', 'X-Requested-With':'XMLHttpRequest'}});
    const data = await resp.json().catch(() => ({}));

    const folders = (data && data.folders) ? data.folders : [];
    const files = (data && data.files) ? data.files : [];
    const parent = (data && Object.prototype.hasOwnProperty.call(data,'parent')) ? data.parent : null;

    // Update currentServerPath from server response (may differ when start_at_manufacturer resolved a folder)
    if (data && typeof data.path === 'string') {
      currentServerPath = data.path;
    }

    // Nav
    serverPath.textContent = '/' + (currentServerPath ? currentServerPath : '');
    if (parent === null) {
      serverUpBtn.disabled = true;
      serverUpBtn.dataset.parent = '';
    } else {
      serverUpBtn.disabled = false;
      serverUpBtn.dataset.parent = String(parent || '');
    }

    // Folders (already sorted by server)
    (folders || []).forEach(d => {
      const card = document.createElement('div');
      card.className = 'pim-server-item';
      card.innerHTML = '<div class="icon">📁</div><div class="label">'+(d.name||'')+'</div>';
      card.addEventListener('click', () => {
        loadServerDir(d.path || '');
      });
      serverFolders.appendChild(card);
    });

    // Files (already sorted by server)
    (files || []).forEach(f => {
      const card = document.createElement('div');
      card.className = 'pim-server-item';
      card.innerHTML = '<img src="'+f.url+'" loading="lazy"><div class="label">'+(f.name||'')+'</div>';
      card.addEventListener('click', () => {
        if (!items.some(x => x.path === f.path)) {
          items.push({path: f.path, url: f.url});
          render();
          showToast('Image Added','info');
    } else {
          showToast('Already Added','info');
    }
      });
      serverGrid.appendChild(card);
    });

    if (!(folders && folders.length) && !(files && files.length)) {
      serverEmpty.classList.add('show');
    }
  }

  async function openServerModal(){
    const mid = getManufacturerId();
    if (!mid) { alert('Please select a Manufacturer first.'); return; }
    modal.setAttribute('aria-hidden','false');
    await loadServerDir('', true);
  }

  // Buttons
  document.getElementById('pimAddLocalBtn').addEventListener('click', () => input.click());
  document.getElementById('pimAddUrlBtn').addEventListener('click', async () => {
    const url = prompt('Paste image URL (JPG/PNG):');
    if (!url) return;
    await importFromUrl(url);
  });
  document.getElementById('pimAddServerBtn').addEventListener('click', openServerModal);
  serverUpBtn.addEventListener('click', async () => {
    if (serverUpBtn.disabled) return;
    const p = String(serverUpBtn.dataset.parent || '');
    await loadServerDir(p);

  // Upload to current server folder
  if (serverUploadBtn && serverUploadInput) {
    serverUploadBtn.addEventListener('click', () => serverUploadInput.click());
    serverUploadInput.addEventListener('change', async () => {
      const files = Array.from(serverUploadInput.files || []);
      if (!files.length) return;

      for (const f of files) {
        const ext = (f.name.split('.').pop() || '').toLowerCase();
        if (!['jpg','jpeg','png'].includes(ext)) {
          showToast('Only JPG and PNG images are allowed.','error');
          continue;
        }

        const fd = new FormData();
        fd.append('file', f);
        fd.append('path', currentServerPath || '');

        const resp = await fetch(@json(route('products.images.upload_to_catalog')), {
          method: 'POST',
          headers: {'X-CSRF-TOKEN': csrf, 'Accept':'application/json', 'X-Requested-With':'XMLHttpRequest'},
          body: fd
        });

        const data = await resp.json().catch(() => ({}));
        if (!resp.ok || !data.ok) {
          showToast(data.message || 'Upload failed.','error');
          continue;
        }

        showToast('Image Added','info');
      }

      serverUploadInput.value = '';
      await loadServerDir(currentServerPath || '');
    });
  }
  });

  input.addEventListener('change', async () => {
    const files = Array.from(input.files || []);
    input.value = '';
    for (const f of files) {
      const ext = (f.name.split('.').pop() || '').toLowerCase();
      if (!['jpg','jpeg','png'].includes(ext)) { alert('Only JPG and PNG images are allowed.'); continue; }
      await uploadFile(f);
    }
  });

  // Drag & drop upload
  drop.addEventListener('dragover', (e) => { e.preventDefault(); drop.classList.add('dragover'); });
  drop.addEventListener('dragleave', () => { drop.classList.remove('dragover'); });
  drop.addEventListener('drop', async (e) => {
    e.preventDefault();
    drop.classList.remove('dragover');
    const files = Array.from(e.dataTransfer.files || []);
    for (const f of files) {
      const ext = (f.name.split('.').pop() || '').toLowerCase();
      if (!['jpg','jpeg','png'].includes(ext)) { alert('Only JPG and PNG images are allowed.'); continue; }
      await uploadFile(f);
    }
  });

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
      render(); // refresh badges
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
