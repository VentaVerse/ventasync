@php
  $pimOld = json_decode((string) old('images_json', ''), true);
  $pimInitial = is_array($pimOld) && $pimOld !== []
      ? array_values(array_filter(array_map(fn ($i) => is_array($i) ? (string) ($i['path'] ?? '') : (string) $i, $pimOld)))
      : ($productImages ?? []);
  $pimToken = (string) old('pim_token', $pimToken ?? '');
  $pimProductId = (int) ($pimProductId ?? 0);
@endphp

<section class="fm-section" aria-labelledby="sec-images">
    <div class="fm-section__head">
        <h2 class="fm-section__title" id="sec-images">Images</h2>
        <x-ui.hint label="How image order works">Drag a tile to reorder. The first image is the one the
            catalog and the marketplaces show, and it is marked Main.</x-ui.hint>
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
        @error('images_json')<div class="fm-error">{{ $message }}</div>@enderror
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
      <div class="fm-pick__tools">
        <x-ui.button type="button" size="sm" id="pimServerUpBtn" disabled>
          <x-ui.icon name="corner-up-left" size="14" /> Up
        </x-ui.button>
        <span class="fm-pick__path" id="pimServerPath">/</span>
        <span class="fm-pick__gap"></span>
        <input type="file" id="pimServerUploadInput" class="x-sr"
               aria-label="Choose image files to upload"
               accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" multiple>
        <x-ui.button type="button" size="sm" id="pimServerUploadBtn">
          <x-ui.icon name="upload" size="14" /> Upload files
        </x-ui.button>
        <x-ui.button type="button" size="sm" id="pimServerUrlBtn">Add from URL</x-ui.button>
        <x-ui.button type="button" size="sm" id="pimNewFolderBtn">
          <x-ui.icon name="folder" size="14" /> New folder
        </x-ui.button>
        <input type="text" id="pimServerSearch" class="x-input fm-pick__search"
               placeholder="Search all images and folders" aria-label="Search all images and folders">
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

      <div class="fm-pick__tools d-none" id="pimFolderRow">
        <input type="text" id="pimFolderName" class="x-input fm-pick__search"
               aria-label="New folder name" placeholder="Folder name" autocomplete="off" maxlength="64">
        <x-ui.button type="button" size="sm" variant="primary" id="pimFolderGo">Create</x-ui.button>
        <x-ui.button type="button" size="sm" id="pimFolderCancel">Cancel</x-ui.button>
      </div>

      <div class="fm-pick__tools d-none" id="pimUrlRow">
        <input type="url" id="pimUrlInput" class="x-input fm-pick__search"
               aria-label="Image address to add" placeholder="https://example.com/photo.jpg">
        <x-ui.button type="button" size="sm" variant="primary" id="pimUrlGo">Add</x-ui.button>
        <x-ui.button type="button" size="sm" id="pimUrlCancel">Cancel</x-ui.button>
      </div>

      <div class="fm-pick__group" id="pimServerFoldersTitle">Folders</div>
      <div class="fm-pick__grid" id="pimServerFolders"></div>

      <div class="fm-pick__group" id="pimServerFilesTitle">Images</div>
      <div class="fm-pick__grid" id="pimServerGrid"></div>
      <div class="fm-pick__empty d-none" id="pimServerEmpty">Nothing in this folder.</div>

      <div class="fm-pick__drop" id="pimDropOverlay" aria-hidden="true">Drop files to upload them here</div>
    </div>
  </div>
</div>

<template id="pimFolderIcon">
  <span class="fm-pick__ico">
    <x-ui.icon name="folder" size="28" />
  </span>
</template>

<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"
        integrity="sha384-BSxuMLxX+FCbTdYec3TbXlnMGEEM2QXTFdtDaveen71o+jswm2J36+xFqp8k4VHM"
        crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script>
(function(){
  const csrf = @json(csrf_token());
  const initialPaths = @json($pimInitial);
  const PIM_PRODUCT_ID = @json($pimProductId);
  const PIM_TOKEN = @json($pimToken);

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
  const serverFoldersTitle = document.getElementById('pimServerFoldersTitle');
  const serverFilesTitle = document.getElementById('pimServerFilesTitle');
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
  let searchMode = false;
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

      if (idx === 0) {
        const main = document.createElement('span');
        main.className = 'fm-img__main';
        main.textContent = 'Main';
        div.appendChild(main);
      }

      const bar = document.createElement('div');
      bar.className = 'fm-img__bar';

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

  function closeIcon() {
    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('width', '13');
    svg.setAttribute('height', '13');
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('fill', 'none');
    svg.setAttribute('stroke', 'currentColor');
    svg.setAttribute('stroke-width', '2.5');
    svg.setAttribute('stroke-linecap', 'round');
    svg.setAttribute('aria-hidden', 'true');

    const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
    path.setAttribute('d', 'M18 6 6 18M6 6l12 12');
    svg.appendChild(path);

    return svg;
  }

  function buildFileCard(f) {
    const cell = document.createElement('div');
    cell.className = 'fm-pick__cell';

    const card = document.createElement('button');
    card.type = 'button';
    card.className = 'fm-pick__item';

    const thumb = document.createElement('img');
    thumb.src = f.url || '';
    thumb.alt = '';
    thumb.loading = 'lazy';
    card.appendChild(thumb);

    const label = document.createElement('span');
    label.className = 'fm-pick__label';
    label.textContent = f.name || '';
    label.title = f.name || '';
    card.appendChild(label);

    if (searchMode && f.folder) {
      const where = document.createElement('span');
      where.className = 'fm-pick__where';
      where.textContent = f.folder;
      where.title = f.folder;
      card.appendChild(where);
    }

    const inLineup = () => items.some(x => x.path === f.path);
    const mark = () => {
      const on = !pickOne && inLineup();
      card.classList.toggle('is-picked', on);
      card.setAttribute('aria-pressed', on ? 'true' : 'false');
    };
    mark();

    card.addEventListener('click', () => {
      if (pickOne) {
        const done = pickOne;
        pickOne = null;
        closeModal();
        done({ path: f.path, url: f.url });
        return;
      }

      if (inLineup()) {
        items = items.filter(x => x.path !== f.path);
        showToast('Removed');
      } else {
        items.push({path: f.path, url: f.url});
        showToast('Added');
      }
      render();
      mark();
    });

    cell.appendChild(card);

    const del = document.createElement('button');
    del.type = 'button';
    del.className = 'fm-pick__del';
    del.appendChild(closeIcon());
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
    cell.appendChild(del);

    return cell;
  }

  function buildFolderCard(d) {
    const card = document.createElement('button');
    card.type = 'button';
    card.className = 'fm-pick__item';

    const iconTpl = document.getElementById('pimFolderIcon');
    if (iconTpl) card.appendChild(iconTpl.content.firstElementChild.cloneNode(true));

    const label = document.createElement('span');
    label.className = 'fm-pick__label';
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

    const sort = serverSort.value || 'newest';

    let filtered = cachedServerFiles;

    filtered = [...filtered];
    if (sort === 'newest') filtered.sort((a,b) => (b.modified||0) - (a.modified||0));
    else if (sort === 'oldest') filtered.sort((a,b) => (a.modified||0) - (b.modified||0));
    else if (sort === 'name_asc') filtered.sort((a,b) => (a.name||'').localeCompare(b.name||''));
    else if (sort === 'name_desc') filtered.sort((a,b) => (b.name||'').localeCompare(a.name||''));

    filtered.forEach(f => serverGrid.appendChild(buildFileCard(f)));

    if (serverFilesTitle) serverFilesTitle.classList.toggle('d-none', filtered.length === 0);

    if (!cachedServerFolders.length && !filtered.length) {
      serverEmpty.classList.remove('d-none');
    }
  }

  async function loadServerDir(path){
    currentServerPath = String(path || '').replace(/\\/g,'/').replace(/^\/+|\/+$/g,'');
    searchMode = false;

    serverGrid.textContent = '';
    serverFolders.textContent = '';
    serverEmpty.classList.add('d-none');
    serverSearch.value = '';

    const u = new URL(@json(route('products.images.browse')), window.location.origin);
    if (currentServerPath) u.searchParams.set('path', currentServerPath);

    let data = null;
    try {
      const resp = await fetch(u.toString(), {headers: {'X-CSRF-TOKEN': csrf, 'Accept':'application/json', 'X-Requested-With':'XMLHttpRequest'}});
      if (!resp.ok) throw new Error('HTTP ' + resp.status);
      data = await resp.json();
    } catch (e) {
      serverEmpty.textContent = 'The library could not be reached. Check your connection and try again.';
      serverEmpty.classList.remove('d-none');
      return;
    }

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

    renderServerFolders();
    renderServerFiles();
  }

  function renderServerFolders() {
    serverFolders.textContent = '';
    cachedServerFolders.forEach(d => serverFolders.appendChild(buildFolderCard(d)));

    if (serverFoldersTitle) {
      serverFoldersTitle.classList.toggle('d-none', cachedServerFolders.length === 0);
      serverFoldersTitle.textContent = searchMode ? 'Matching folders' : 'Folders';
    }
  }

  async function openServerModal(){
    pickReturnFocus = document.activeElement;
    modal.classList.add('active');

    const barWidth = window.innerWidth - document.documentElement.clientWidth;
    document.body.dataset.pimPadding = document.body.style.paddingRight || '';
    document.body.style.overflow = 'hidden';
    if (barWidth > 0) document.body.style.paddingRight = barWidth + 'px';

    const panel = modal.querySelector('.fm-pick');
    const target = panel.querySelector('#pimServerUpBtn:not([disabled])')
        || panel.querySelector('#pimServerSearch')
        || panel;
    if (target && typeof target.focus === 'function') {
        if (target === panel) panel.setAttribute('tabindex', '-1');
        target.focus();
    }
    await loadServerDir('');
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
      folderRow.classList.add('d-none');
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

  let searchTimer = null;

  async function runLibrarySearch(query) {
    const u = new URL(@json(route('products.images.browse')), window.location.origin);
    u.searchParams.set('q', query);

    let data = null;
    try {
      const resp = await fetch(u.toString(), {
        headers: {'X-CSRF-TOKEN': csrf, 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
      });
      if (!resp.ok) throw new Error('HTTP ' + resp.status);
      data = await resp.json();
    } catch (e) {
      serverEmpty.textContent = 'The search could not be run. Check your connection and try again.';
      serverEmpty.classList.remove('d-none');
      return;
    }

    if ((serverSearch.value || '').trim() !== query) return;

    searchMode = true;
    cachedServerFolders = (data && data.folders) ? data.folders : [];
    cachedServerFiles = (data && data.files) ? data.files : [];

    renderServerFolders();
    renderServerFiles();

    serverEmpty.textContent = (!cachedServerFolders.length && !cachedServerFiles.length)
      ? 'Nothing in the library matches "' + query + '".'
      : 'Nothing in this folder.';

    if (data && data.truncated) {
      showToast('Showing the first ' + cachedServerFiles.length + ' matches. Narrow the search to see fewer.');
    }
  }

  serverSearch.addEventListener('input', () => {
    clearTimeout(searchTimer);
    const query = (serverSearch.value || '').trim();

    searchTimer = setTimeout(() => {
      if (query === '') {
        loadServerDir(currentServerPath);
        return;
      }
      runLibrarySearch(query);
    }, 250);
  });

  serverSort.addEventListener('change', () => renderServerFiles());

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

  let pickReturnFocus = null;

  function focusablesIn(el) {
    return Array.prototype.filter.call(
      el.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'),
      (n) => !n.disabled && n.offsetParent !== null
    );
  }

  function closeModal() {
    pickOne = null;
    modal.classList.remove('active');

    document.body.style.overflow = '';
    document.body.style.paddingRight = document.body.dataset.pimPadding || '';
    delete document.body.dataset.pimPadding;

    if (pickReturnFocus && typeof pickReturnFocus.focus === 'function') {
      pickReturnFocus.focus();
    }
    pickReturnFocus = null;
  }

  document.addEventListener('keydown', (e) => {
    if (e.key !== 'Tab' || !modal.classList.contains('active')) return;

    const panel = modal.querySelector('.fm-pick');
    const items = focusablesIn(panel);
    if (!items.length) return;

    const first = items[0];
    const last = items[items.length - 1];

    if (!panel.contains(document.activeElement)) {
      e.preventDefault();
      first.focus();
    } else if (e.shiftKey && document.activeElement === first) {
      e.preventDefault();
      last.focus();
    } else if (!e.shiftKey && document.activeElement === last) {
      e.preventDefault();
      first.focus();
    }
  });

  const newFolderBtn = document.getElementById('pimNewFolderBtn');
  const folderRow = document.getElementById('pimFolderRow');
  const folderName = document.getElementById('pimFolderName');
  const folderGo = document.getElementById('pimFolderGo');
  const folderCancel = document.getElementById('pimFolderCancel');

  function closeFolderRow() {
    folderRow.classList.add('d-none');
    folderName.value = '';
  }

  async function createFolder() {
    const name = (folderName.value || '').trim();
    if (!name) {
      folderName.focus();
      return;
    }

    folderGo.disabled = true;

    try {
      const resp = await fetch(@json(route('products.images.create_folder')), {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': csrf,
          'Accept': 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({ path: currentServerPath || '', name: name }),
      });

      const data = await resp.json().catch(() => ({}));

      if (!resp.ok || !data.ok) {
        showToast(data.message || 'The folder could not be created.', 'error');
        folderName.focus();
        folderName.select();
        return;
      }

      closeFolderRow();
      showToast('Folder created');
      await loadServerDir(currentServerPath || '');
    } finally {
      folderGo.disabled = false;
    }
  }

  if (newFolderBtn) {
    newFolderBtn.addEventListener('click', () => {
      urlRow.classList.add('d-none');
      folderRow.classList.remove('d-none');
      folderName.focus();
    });
  }

  folderGo.addEventListener('click', createFolder);
  folderCancel.addEventListener('click', closeFolderRow);

  folderName.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') { e.preventDefault(); createFolder(); }
    if (e.key === 'Escape') { e.stopPropagation(); closeFolderRow(); }
  });

  modal.addEventListener('click', (e) => {
    const t = e.target;
    if (t === modal || (t && t.closest && t.closest('[data-close="1"]'))) {
      closeModal();
    }
  });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && modal.classList.contains('active')) closeModal();
  });

  render();
})();
</script>
@endpush
