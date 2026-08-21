@php
  $pimInitial = $productImages ?? [];
  $pimToken = $pimToken ?? '';
  $pimProductId = (int) ($pimProductId ?? 0);
@endphp

{{--
  Product images.

  The wire is unchanged. Two hidden inputs travel with the product form:

    pim_token    an upload token, so images attached before the product row
                 exists can be finalised into its folder by store()
    images_json  a JSON array of storage paths, IN ORDER. The first path is
                 the product's main image. update()/store() read it through
                 imagesFromRequest().

  Everything else is fetch against ProductImageController:

    products.images.browse                 GET  folders + files in a folder
    products.images.upload_to_catalog      POST a file into the open folder
    products.images.import_url_to_catalog  POST a remote URL into it
    products.images.delete                 POST remove a file from the server

  (products.images.upload and products.images.import_url are the older
  product-scoped pair; nothing calls them from here, and that was already the
  case before this redesign.)

  The picker dialog and the script are pushed to the layout's scripts stack
  rather than rendered inline, so they land outside the product <form>.
  Nested inside it, the dialog's search box would submit the whole product on
  a stray Enter.
--}}

<section class="fm-section" aria-labelledby="sec-images">
    <div class="fm-section__head">
        <h2 class="fm-section__title" id="sec-images">Images</h2>
        <x-ui.hint label="How image order works">Drag a tile to reorder. The first image is the one the
            catalogue and the marketplaces show, and it is marked Main.</x-ui.hint>
        <div class="fm-section__aside">
            <x-ui.button type="button" size="sm" id="pimOpenManagerBtn">
                <x-ui.icon name="image" size="14" /> Browse library
            </x-ui.button>
        </div>
    </div>

    <div class="fm-img">
        <input type="hidden" name="pim_token" value="{{ $pimToken }}">
        <input type="hidden" name="images_json" id="pimImagesJson" value="">

        <div class="fm-img__grid" id="pimGrid"></div>
        <div class="fm-img__empty" id="pimEmpty">No images yet. Browse the library to add some.</div>
    </div>
</section>

@push('scripts')
<div class="modal-backdrop" id="pimServerModal">
  <div class="fm-pick" role="dialog" aria-modal="true" aria-labelledby="pimPickTitle">
    <div class="fm-pick__head">
      <h3 class="fm-pick__title" id="pimPickTitle">Image library</h3>
      <div class="fm-pick__actions">
        <span class="fm-pick__msg" id="pimModalMsg" role="status" aria-live="polite"></span>
        <x-ui.button type="button" size="sm" data-close="1">Close</x-ui.button>
      </div>
    </div>

    <div class="fm-pick__body" id="pimModalBody">
      <div class="fm-pick__nav">
        <x-ui.button type="button" size="sm" id="pimServerUpBtn" disabled>
          <x-ui.icon name="corner-up-left" size="14" /> Up
        </x-ui.button>
        <span class="fm-pick__path" id="pimServerPath">/</span>
      </div>

      <div class="fm-pick__tools">
        {{-- Visually hidden, but still reachable: x-sr keeps it in the tab
             order, so it needs a name like any other control. --}}
        <input type="file" id="pimServerUploadInput" class="x-sr"
               aria-label="Choose image files to upload"
               accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" multiple>
        <x-ui.button type="button" size="sm" id="pimServerUploadBtn">
          <x-ui.icon name="upload" size="14" /> Upload files
        </x-ui.button>
        <x-ui.button type="button" size="sm" id="pimServerUrlBtn">Add from URL</x-ui.button>
        <input type="text" id="pimServerSearch" class="x-input fm-pick__search"
               placeholder="Search this folder" aria-label="Search this folder">
        <div class="x-select-wrap">
          <select id="pimServerSort" class="x-input" aria-label="Sort images in this folder">
            <option value="newest">Newest first</option>
            <option value="oldest">Oldest first</option>
            <option value="name_asc">Name A to Z</option>
            <option value="name_desc">Name Z to A</option>
          </select>
          <x-ui.icon name="chevron-down" size="14" class="x-select-wrap__chevron" />
        </div>
      </div>

      {{-- Paste an image address here instead of a browser prompt(). Same
           endpoint, same payload; this project does not use native dialogs. --}}
      <div class="fm-pick__tools d-none" id="pimUrlRow">
        <input type="url" id="pimUrlInput" class="x-input fm-pick__search"
               aria-label="Image address to add" placeholder="https://example.com/photo.jpg">
        <x-ui.button type="button" size="sm" variant="primary" id="pimUrlGo">Add</x-ui.button>
        <x-ui.button type="button" size="sm" id="pimUrlCancel">Cancel</x-ui.button>
      </div>

      <div class="fm-pick__label" id="pimServerFoldersTitle">Folders</div>
      <div class="fm-pick__grid" id="pimServerFolders"></div>

      <div class="fm-pick__label" id="pimServerFilesTitle">Images</div>
      <div class="fm-pick__grid" id="pimServerGrid"></div>
      <div class="fm-pick__empty d-none" id="pimServerEmpty">Nothing in this folder.</div>

      <div class="fm-pick__drop" id="pimDropOverlay" aria-hidden="true">Drop files to upload them here</div>
    </div>
  </div>
</div>

{{-- Cloned per folder tile. A <template> rather than an innerHTML string:
     this project builds DOM, it does not paste markup. --}}
<template id="pimFolderIcon">
  <span class="icon">
    <x-ui.icon name="folder" size="28" />
  </span>
</template>

{{-- Pinned with subresource integrity, which the pre-redesign copy of this
     tag did not carry. --}}
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"
        integrity="sha384-BSxuMLxX+FCbTdYec3TbXlnMGEEM2QXTFdtDaveen71o+jswm2J36+xFqp8k4VHM"
        crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script>
(function(){
  const csrf = @json(csrf_token());
  const initialPaths = @json($pimInitial);
  const PIM_PRODUCT_ID = @json($pimProductId);
  const PIM_TOKEN = @json($pimToken);

  /* SINGLE-PICK MODE, for callers that want one image rather than another
     entry in the product gallery.
     ------------------------------------------------------------------
     The variations grid needs a picture per variation, and it needs the
     library that already exists: the same folders, the same upload, the same
     manufacturer scoping. What it does not want is the tile click appending
     to the product's own image list.

     So the modal grows one extra state. When pickOne is set, choosing a file
     hands it to that callback and closes instead of adding. It is cleared on
     every exit, including the ones the operator takes by pressing Escape or
     clicking the backdrop, so the mode can never leak into the next open. */
  let pickOne = null;

  window.pimPickOne = function (onPicked) {
    pickOne = typeof onPicked === 'function' ? onPicked : null;
    openServerModal();
  };

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
  const urlRow = document.getElementById('pimUrlRow');
  const urlInput = document.getElementById('pimUrlInput');
  const urlGo = document.getElementById('pimUrlGo');
  const urlCancel = document.getElementById('pimUrlCancel');
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
    modalMsg.classList.remove('error');
    if (kind === 'error') modalMsg.classList.add('error');
    modalMsg.textContent = msg;
    clearTimeout(window.__pimMsgT);
    window.__pimMsgT = setTimeout(()=>{ modalMsg.textContent = ''; }, 2400);
  }

  let items = (initialPaths || []).map(p => ({path: p, url: toUrl(p)}));

  function syncHidden(){
    imagesJson.value = JSON.stringify(items.map(x => x.path));
  }

  function render(){
    grid.textContent = '';
    empty.classList.toggle('d-none', items.length > 0);

    /* What a variation with no image of its own inherits.
       Position 1 IS the main image on this form, so this is republished on
       every render rather than read once: dragging a different photo to the
       front changes what every inheriting variation shows, and the grid
       downstream should follow that without being told. */
    window.pimPrimaryImage = items.length ? items[0].url : '';
    document.dispatchEvent(new CustomEvent('pim:primary-changed'));

    items.forEach((it, idx) => {
      const div = document.createElement('div');
      div.className = 'fm-img__item';
      div.dataset.path = it.path;

      const wrap = document.createElement('div');
      wrap.className = 'fm-img__thumb-wrap';
      const img = document.createElement('img');
      img.className = 'fm-img__thumb';
      img.src = it.url;
      img.alt = '';
      img.loading = 'lazy';
      wrap.appendChild(img);
      div.appendChild(wrap);

      // Main is a badge ON the picture, not a caption under it. Which image
      // the catalogue and every marketplace show is the one state that
      // matters in this grid, and a word in a footer is read, not seen.
      if (idx === 0) {
        const main = document.createElement('span');
        main.className = 'fm-img__main';
        main.textContent = 'Main';
        div.appendChild(main);
      }

      const bar = document.createElement('div');
      bar.className = 'fm-img__bar';

      // The grip is the reorder affordance. It replaces the sentence that
      // used to say "drag to reorder": an instruction you have to read is
      // worse than a handle you can see, and the sentence has moved into
      // the heading's hint for anyone who wants it spelled out.
      const grip = document.createElement('span');
      grip.className = 'fm-img__grip';
      grip.setAttribute('aria-hidden', 'true');
      grip.innerHTML = '<svg width="12" height="12" viewBox="0 0 12 12" fill="currentColor">'
        + '<circle cx="4" cy="2.5" r="1"/><circle cx="8" cy="2.5" r="1"/>'
        + '<circle cx="4" cy="6" r="1"/><circle cx="8" cy="6" r="1"/>'
        + '<circle cx="4" cy="9.5" r="1"/><circle cx="8" cy="9.5" r="1"/></svg>';
      bar.appendChild(grip);

      const pos = document.createElement('span');
      pos.className = 'fm-img__pos';
      pos.textContent = String(idx + 1);
      bar.appendChild(pos);

      const remove = document.createElement('button');
      remove.type = 'button';
      remove.className = 'fm-img__x';
      remove.setAttribute('aria-label', 'Remove image ' + (idx + 1));
      // Drawn, not a multiplication sign. The craft floor bans a glyph
      // standing in for an icon, and this one sat at 18px besides.
      remove.innerHTML = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
        + ' stroke-width="2.5" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>';
      remove.addEventListener('click', (e) => {
        e.preventDefault();
        items = items.filter(x => x.path !== it.path);
        render();
      });
      bar.appendChild(remove);

      div.appendChild(bar);
      grid.appendChild(div);
    });

    syncHidden();
  }

  function buildFileCard(f) {
    const card = document.createElement('button');
    card.type = 'button';
    card.className = 'fm-pick__item';

    const thumb = document.createElement('img');
    thumb.src = f.url || '';
    thumb.alt = '';
    thumb.loading = 'lazy';
    card.appendChild(thumb);

    const label = document.createElement('span');
    label.className = 'label';
    label.textContent = f.name || '';
    label.title = f.name || '';
    card.appendChild(label);

    card.addEventListener('click', () => {
      // Single-pick: hand the file back and get out of the way.
      if (pickOne) {
        const done = pickOne;
        pickOne = null;
        modal.classList.remove('active');
        done({ path: f.path, url: f.url });
        return;
      }

      if (!items.some(x => x.path === f.path)) {
        items.push({path: f.path, url: f.url});
        render();
        showToast('Added');
      } else {
        showToast('Already added');
      }
    });

    const del = document.createElement('button');
    del.type = 'button';
    del.className = 'fm-pick__del';
    del.textContent = '×';
    del.setAttribute('aria-label', 'Delete ' + (f.name || 'image') + ' from the server');
    del.addEventListener('click', (e) => {
      e.stopPropagation();
      window.confirmModal('Delete "' + (f.name||'') + '" from the server? This cannot be undone.').then(async function(ok) {
        if (!ok) return;
        const resp = await fetch(@json(route('products.images.delete')), {
          method: 'POST',
          headers: {'Content-Type':'application/json', 'X-CSRF-TOKEN': csrf, 'Accept':'application/json', 'X-Requested-With':'XMLHttpRequest'},
          body: JSON.stringify({ path: f.path })
        });
        const data = await resp.json().catch(() => ({}));
        if (!resp.ok || !data.ok) { showToast(data.message || 'Could not delete it', 'error'); return; }
        cachedServerFiles = cachedServerFiles.filter(x => x.path !== f.path);
        renderServerFiles();
        items = items.filter(x => x.path !== f.path);
        render();
        showToast('Deleted');
      });
    });
    card.appendChild(del);

    return card;
  }

  function buildFolderCard(d) {
    const card = document.createElement('button');
    card.type = 'button';
    card.className = 'fm-pick__item';

    const iconTpl = document.getElementById('pimFolderIcon');
    if (iconTpl) card.appendChild(iconTpl.content.firstElementChild.cloneNode(true));

    const label = document.createElement('span');
    label.className = 'label';
    label.textContent = d.name || '';
    label.title = d.name || '';
    card.appendChild(label);

    card.addEventListener('click', () => {
      loadServerDir(d.path || '');
    });

    return card;
  }

  function renderServerFiles() {
    serverGrid.textContent = '';
    serverEmpty.classList.add('d-none');

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
      serverEmpty.classList.remove('d-none');
    }
  }

  async function loadServerDir(path, startAtManufacturer){
    const mid = getManufacturerId();
    currentServerPath = String(path || '').replace(/\\/g,'/').replace(/^\/+|\/+$/g,'');

    serverGrid.textContent = '';
    serverFolders.textContent = '';
    serverEmpty.classList.add('d-none');
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
    if (!mid) {
      // The library is organised by manufacturer folder, so there is no
      // sensible folder to open yet. Says so where the operator is looking
      // instead of through window.showFlashError, which needs a
      // #js-flash-container that layouts.shell does not render.
      const wrap = document.getElementById('manufacturer_search');
      if (wrap) {
        wrap.classList.add('input-error');
        wrap.focus();
        wrap.scrollIntoView({behavior:'smooth', block:'center'});
        const field = wrap.closest('.fm-field');
        if (field && !field.querySelector('.fm-error--js')) {
          const err = document.createElement('div');
          err.className = 'fm-error fm-error--js';
          err.textContent = 'Choose a manufacturer first. Images are filed under it.';
          field.appendChild(err);
        }
      }
      return;
    }
    modal.classList.add('active');
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
      showToast('Only JPG, PNG and WebP images are allowed.','error');
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
    showToast('Uploaded and added');
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
    showToast('Imported and added');
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
    serverUrlBtn.addEventListener('click', () => {
      urlRow.classList.remove('d-none');
      urlInput.value = '';
      urlInput.focus();
    });
    urlCancel.addEventListener('click', () => urlRow.classList.add('d-none'));
    urlInput.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') { e.preventDefault(); urlGo.click(); }
    });
    urlGo.addEventListener('click', async () => {
      const url = (urlInput.value || '').trim();
      if (!url) return;
      urlRow.classList.add('d-none');
      await importUrlToCurrentFolder(url);
      await loadServerDir(currentServerPath || '');
    });
  }

  // Drag and drop into the dialog body uploads into the open folder
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

  // Search and sort
  let searchTimer = null;
  serverSearch.addEventListener('input', () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => renderServerFiles(), 200);
  });
  serverSort.addEventListener('change', () => renderServerFiles());

  // Drag to reorder. First tile is the main image, so order is the wire.
  if (window.Sortable) {
    new Sortable(grid, {
      animation: 150,
      onEnd: function(){
        const newOrder = [];
        grid.querySelectorAll('.fm-img__item').forEach(el => {
          const p = el.dataset.path;
          const found = items.find(x => x.path === p);
          if (found) newOrder.push(found);
        });
        items = newOrder;
        render();
      }
    });
  }

  // Dialog close
  modal.addEventListener('click', (e) => {
    const t = e.target;
    if (t === modal || (t && t.closest && t.closest('[data-close="1"]'))) {
      pickOne = null;
      modal.classList.remove('active');
    }
  });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && modal.classList.contains('active')) {
      pickOne = null;
      modal.classList.remove('active');
    }
  });

  render();
})();
</script>
@endpush
