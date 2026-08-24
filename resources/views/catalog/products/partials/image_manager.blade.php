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
      {{-- Where you are and what you can do to it, on one line. These were
           two stacked rows - Up and the path, then the tools - which spent a
           second row of a dialog that is already scrolling its grid. --}}
      <div class="fm-pick__tools">
        <x-ui.button type="button" size="sm" id="pimServerUpBtn" disabled>
          <x-ui.icon name="corner-up-left" size="14" /> Up
        </x-ui.button>
        <span class="fm-pick__path" id="pimServerPath">/</span>
        <span class="fm-pick__gap"></span>
        {{-- Visually hidden, but still reachable: x-sr keeps it in the tab
             order, so it needs a name like any other control. --}}
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

      {{-- Naming a folder, inline. Not a prompt(): this project does not use
           native dialogs, and a prompt cannot show the error the server sends
           back when a name collides or carries an illegal character. --}}
      <div class="fm-pick__tools d-none" id="pimFolderRow">
        <input type="text" id="pimFolderName" class="x-input fm-pick__search"
               aria-label="New folder name" placeholder="Folder name" autocomplete="off" maxlength="64">
        <x-ui.button type="button" size="sm" variant="primary" id="pimFolderGo">Create</x-ui.button>
        <x-ui.button type="button" size="sm" id="pimFolderCancel">Cancel</x-ui.button>
      </div>

      {{-- Paste an image address here instead of a browser prompt(). Same
           endpoint, same payload; this project does not use native dialogs. --}}
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

{{-- Cloned per folder tile. A <template> rather than an innerHTML string:
     this project builds DOM, it does not paste markup. --}}
<template id="pimFolderIcon">
  <span class="fm-pick__ico">
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
      // the catalog and every marketplace show is the one state that
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

  /* One drawn cross, for both grids. The product grid built its own inline
     and the picker was still typing a multiplication sign. */
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
    /* A CELL WRAPPING TWO SIBLING BUTTONS, not a button inside a button.
       ------------------------------------------------------------------
       The delete control used to be a child of the tile, which is invalid -
       a button permits no interactive descendant - and Chrome's own
       accessibility tree showed what it costs: the SELECT action's name
       absorbed the delete label, so the filename was announced twice and the
       word "Delete" was read as part of the name of the control that picks
       the image. Two actions, two siblings, two names. */
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

    /* Where it came from. A result list spanning 240 folders is unreadable
       without it - two files can carry the same name and mean different
       pictures, and picking the wrong one is silent. Only in search: inside
       a folder listing every row would repeat the same line. */
    if (searchMode && f.folder) {
      const where = document.createElement('span');
      where.className = 'fm-pick__where';
      where.textContent = f.folder;
      where.title = f.folder;
      card.appendChild(where);
    }

    card.addEventListener('click', () => {
      // Single-pick: hand the file back and get out of the way.
      if (pickOne) {
        const done = pickOne;
        pickOne = null;
        closeModal();
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

    cell.appendChild(card);

    const del = document.createElement('button');
    del.type = 'button';
    del.className = 'fm-pick__del';
    // Drawn, not typed. The craft floor bans a glyph standing in for an icon,
    // and the product grid 200 lines up was already fixed; this was missed.
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

    /* No client-side filtering any more. In a folder listing there is nothing
       to filter - the server sent exactly this folder - and in search mode
       the server has already decided what matches, including files that match
       by their FOLDER's name and would be thrown away by a filter on the
       filename. */
    let filtered = cachedServerFiles;

    filtered = [...filtered];
    if (sort === 'newest') filtered.sort((a,b) => (b.modified||0) - (a.modified||0));
    else if (sort === 'oldest') filtered.sort((a,b) => (a.modified||0) - (b.modified||0));
    else if (sort === 'name_asc') filtered.sort((a,b) => (a.name||'').localeCompare(b.name||''));
    else if (sort === 'name_desc') filtered.sort((a,b) => (b.name||'').localeCompare(a.name||''));

    filtered.forEach(f => serverGrid.appendChild(buildFileCard(f)));

    /* "Images" over an empty grid is a label for nothing, and this dialog
       showed both of its headings unconditionally - so an empty folder read
       as two broken regions rather than as an empty folder. */
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
      /* A REQUEST THAT FAILED IS NOT AN EMPTY FOLDER. Both of these used to
         swallow the error and render "Nothing in this folder", which is a
         false statement made with total confidence - and it looks exactly
         like a folder you have already emptied. */
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

  /* Extracted so a search can redraw the folder half too. It used to be
     inline in loadServerDir, which meant only a directory listing could
     produce folders - and a search that matches folder names has to be able
     to show them. */
  function renderServerFolders() {
    serverFolders.textContent = '';
    cachedServerFolders.forEach(d => serverFolders.appendChild(buildFolderCard(d)));

    if (serverFoldersTitle) {
      serverFoldersTitle.classList.toggle('d-none', cachedServerFolders.length === 0);
      // In a result list the heading has to say what it is a list OF.
      serverFoldersTitle.textContent = searchMode ? 'Matching folders' : 'Folders';
    }
  }

  async function openServerModal(){
    /* NO MANUFACTURER GATE. It used to refuse to open at all until a
       manufacturer was chosen, because uploads were filed under one - and
       that has not been true for a while: the modal's upload already posts
       to whatever folder you are browsing. The gate was left guarding a rule
       it had stopped enforcing, and it blocked the library on a product that
       simply had not been categorised yet.

       Where a file goes is now the operator's decision, made by standing in
       a folder, which is what a file browser is for. */
    // Remember who opened it, so closing hands focus back rather than
    // stranding it on a button that is no longer on screen.
    pickReturnFocus = document.activeElement;
    modal.classList.add('active');

    /* Lock the page behind the dim. Wheeling over the backdrop scrolled the
       form underneath - measured, 924 to 981 - so the thing you came back to
       was not the thing you left. The scrollbar's width is replaced as
       padding so the page does not jump sideways as it locks. */
    const barWidth = window.innerWidth - document.documentElement.clientWidth;
    document.body.dataset.pimPadding = document.body.style.paddingRight || '';
    document.body.style.overflow = 'hidden';
    if (barWidth > 0) document.body.style.paddingRight = barWidth + 'px';

    // Into the dialog, on the first thing in it. Not the Close button: that
    // is the way out, and opening a file browser with the exit selected reads
    // as "are you sure", which is not the question being asked.
    const panel = modal.querySelector('.fm-pick');
    const target = panel.querySelector('#pimServerUpBtn:not([disabled])')
        || panel.querySelector('#pimServerSearch')
        || panel;
    if (target && typeof target.focus === 'function') {
        if (target === panel) panel.setAttribute('tabindex', '-1');
        target.focus();
    }
    /* ALWAYS THE ROOT.
       The old behaviour jumped to the manufacturer's folder, which was handy
       right up to the moment a product's pictures were not all in it - and
       then it was a guess dressed as a shortcut, with no way to tell which
       you were getting. One predictable starting point removes every "what
       if" that surrounded it, and search now reaches the whole library
       anyway, so nothing was actually being saved by the jump. */
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

  /* SEARCH REACHES THE WHOLE LIBRARY, not the folder you happen to be in.
     ------------------------------------------------------------------
     It used to filter cachedServerFiles, which only ever holds the current
     directory - so the box labelled "search" answered a question about where
     you were standing rather than about the library, and finding a picture
     still meant knowing which of 240 folders it was in.

     The server does the walk now and returns three things at once: folders
     whose name matches, images whose name matches wherever they live, and
     every image inside a matching folder. Asking for "Bigsby" gets the
     Bigsby folder AND the Bigsby pictures, which are different answers to
     the same question and both worth having.

     Debounced, because each keystroke is a real request. 250ms is long
     enough that typing a word costs one search and short enough that it
     still feels like it is keeping up. */
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

    // A slower earlier request must not overwrite a newer answer.
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
      // Clearing the box returns you to the folder you were browsing, rather
      // than leaving you in a result list with no way back.
      if (query === '') {
        loadServerDir(currentServerPath);
        return;
      }
      runLibrarySearch(query);
    }, 250);
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

  /* FOCUS BEHAVIOUR, because this now says aria-modal="true".
     ------------------------------------------------------------------
     It said it was a modal dialog and behaved like a floating div: opening it
     left focus wherever it was, so the next Tab walked into the sidebar
     BEHIND the dim, operating a page the operator could not see. Claiming
     modal semantics and not keeping focus is worse than claiming nothing,
     because a screen reader tells the user they are in a dialog and then the
     keyboard disagrees.

     Same three obligations the confirm dialog in confirm-modal.js already
     meets: take focus on open, keep it inside while open, hand it back to
     whatever opened you. */
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

    // Wrap at both ends, and pull focus back in if it has escaped.
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

  /* Making a folder.
     ------------------------------------------------------------------
     It lands in whatever folder you are currently looking at, which is the
     only place it could mean anything, and the library reloads there so the
     new folder is visible rather than merely reported. */
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
        // The server's own words: it knows whether the name collided, held a
        // slash, or was refused for a character this library cannot address.
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
    // Escape closes the row, not the whole dialog behind it.
    if (e.key === 'Escape') { e.stopPropagation(); closeFolderRow(); }
  });

  // Dialog close
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
