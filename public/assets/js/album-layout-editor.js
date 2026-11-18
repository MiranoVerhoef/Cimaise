(() => {
  const root = document.getElementById('album-layout-editor');
  if (!root || root.dataset.initialized) return;
  root.dataset.initialized = 'true';
  window.cimaiseAlbumEditor?.abort();
  const events = new AbortController();
  window.cimaiseAlbumEditor = events;
  const boot = JSON.parse(document.getElementById('layout-editor-data').textContent);
  const labels = boot.labels;
  const initial = boot.state.draft || boot.state.published || {version: 1, blocks: [], include_remaining: true};
  let layout = structuredClone(initial);
  let revision = boot.state.revision;
  let customPublished = Boolean(boot.state.published);
  let selected = layout.blocks[0]?.id || null;
  let selectedRow = null;
  let savedSnapshot = JSON.stringify(initial);
  let history = [JSON.stringify(initial)];
  let historyIndex = 0;
  let versions = boot.versions || [];
  let saving = false;
  let pickerType = null;
  let pickerIds = new Set();
  let dragged = null;
  const frame = document.getElementById('layout-preview');
  const viewport = document.getElementById('layout-preview-viewport');
  function resizePreview() {
    const width = {desktop: 1440, tablet: 768, mobile: 390}[frame.dataset.device || 'desktop'];
    const scale = Math.min(1, viewport.clientWidth / width);
    if (scale <= 0) return;
    frame.style.width = width + 'px';
    frame.style.height = Math.ceil(viewport.clientHeight / scale) + 'px';
    frame.style.transform = 'scale(' + scale + ')';
    frame.style.left = Math.max(0, (viewport.clientWidth - width * scale) / 2) + 'px';
  }
  const resizeObserver = new ResizeObserver(resizePreview);
  resizeObserver.observe(viewport);
  events.signal.addEventListener('abort', () => resizeObserver.disconnect());
  const controls = document.getElementById('layout-block-controls');
  const rowControls = document.getElementById('layout-row-controls');
  const rowSplit = document.getElementById('layout-row-split');
  const rowFit = document.getElementById('layout-row-fit');
  const list = document.getElementById('layout-block-list');
  const status = document.getElementById('layout-editor-status');
  const picker = document.getElementById('layout-photo-picker');
  const historyDialog = document.getElementById('layout-history-dialog');
  const historyError = document.getElementById('layout-history-error');
  const search = document.getElementById('layout-photo-search');
  const photos = boot.images.map(photo => ({...photo, id: Number(photo.id)}));
  const remaining = document.getElementById('layout-remaining');
  const action = name => root.querySelector('[data-action="' + name + '"]');
  const current = () => layout.blocks.find(block => block.id === selected);
  const rowAvailable = () => {
    const block = current();
    if (selectedRow === null || block?.type !== 'grid') return false;
    const remainingPhotos = block.images.filter(id => photoIds.has(id)).length - selectedRow * block.columns;
    return Math.min(block.columns, remainingPhotos) === 2;
  };
  const photoIds = new Set(photos.map(photo => photo.id));
  const dirty = () => JSON.stringify(layout) !== savedSnapshot;

  function showStatus(message, error = false) {
    status.textContent = message;
    status.toggleAttribute('data-error', error);
  }

  function preview() {
    frame.contentWindow?.postMessage({type: 'cimaise-layout-update', layout, selected, selectedRow, labels}, location.origin);
  }

  function refresh() {
    root.querySelector('.layout-editor-workspace').inert = saving;
    if (!current()) selected = layout.blocks[0]?.id || null;
    const block = current();
    controls.hidden = !block;
    list.replaceChildren();
    for (const item of layout.blocks) {
      const li = document.createElement('li');
      const button = document.createElement('button');
      button.type = 'button';
      button.dataset.select = item.id;
      button.draggable = true;
      button.setAttribute('aria-pressed', String(item.id === selected));
      button.textContent = labels[item.type] + (item.type === 'text' ? ' — ' + item.text.slice(0, 32) : ' (' + item.images.length + ')');
      li.append(button);
      list.append(li);
    }
    if (block) {
      document.getElementById('layout-selected-heading').textContent = labels[block.type];
      root.querySelectorAll('[data-prop]').forEach(input => {
        if (document.activeElement !== input) input.value = block[input.dataset.prop] ?? '';
      });
      document.getElementById('layout-width-value').textContent = block.width + '%';
      document.getElementById('layout-grid-controls').hidden = block.type !== 'grid';
      document.getElementById('layout-text-control').hidden = block.type !== 'text';
      document.getElementById('layout-choose').hidden = block.type === 'text';
      action('up').disabled = layout.blocks[0].id === selected;
      action('down').disabled = layout.blocks.at(-1).id === selected;
    }
    rowControls.hidden = !rowAvailable();
    const row = block?.rows?.[selectedRow];
    document.getElementById('layout-row-number').textContent = selectedRow + 1;
    rowSplit.value = row?.split || 'auto';
    rowFit.value = row?.fill ? 'fill' : 'whole';
    rowFit.disabled = rowSplit.value === 'auto';
    remaining.checked = layout.include_remaining;
    action('undo').disabled = saving || historyIndex === 0;
    action('redo').disabled = saving || historyIndex === history.length - 1;
    action('default').disabled = saving || !customPublished;
    action('draft').disabled = saving;
    action('history').disabled = saving;
    document.getElementById('layout-history-list').inert = saving;
    action('publish').disabled = saving;
    showStatus(dirty() ? labels.unsaved : customPublished ? labels.custom_status : labels.default_status);
    preview();
  }

  function remember() {
    const snapshot = JSON.stringify(layout);
    if (snapshot !== history[historyIndex]) {
      history = history.slice(0, historyIndex + 1);
      history.push(snapshot);
      if (history.length > 100) history.shift();
      historyIndex = history.length - 1;
    }
    refresh();
  }

  function insert(block, after = selected) {
    const index = layout.blocks.findIndex(item => item.id === after);
    layout.blocks.splice(index + 1, 0, block);
    selected = block.id;
    selectedRow = null;
    remember();
  }

  function newBlock(type, images = []) {
    const randomBytes = crypto.getRandomValues(new Uint8Array(16));
    const id = Array.from(randomBytes, byte => byte.toString(16).padStart(2, '0')).join('');
    return {
      id, type, images,
      width: 100, max_width: 0, align: 'center', top: 0, bottom: 20,
      columns: 3, gap: 8, text: '',
    };
  }

  function renderPicker() {
    const container = document.getElementById('layout-picker-photos');
    container.replaceChildren();
    const query = search.value.trim().toLowerCase();
    for (const photo of photos) {
      const name = photo.alt_text || photo.caption || '#' + photo.id;
      if (query && !name.toLowerCase().includes(query) && !String(photo.id).includes(query)) continue;
      const label = document.createElement('label');
      const input = document.createElement('input');
      input.type = pickerType === 'photo' ? 'radio' : 'checkbox';
      input.name = 'layout-photo-selection';
      input.value = photo.id;
      input.checked = pickerIds.has(photo.id);
      const image = document.createElement('img');
      image.alt = '';
      image.loading = 'lazy';
      if (photo.preview) image.src = photo.preview.startsWith('/') ? boot.basePath + photo.preview : photo.preview;
      const caption = document.createElement('span');
      caption.textContent = name;
      label.append(input, image, caption);
      container.append(label);
    }
    document.getElementById('layout-picker-count').textContent = pickerIds.size + ' ' + labels.selected;
    action('apply-picker').disabled = pickerIds.size === 0;
  }

  function openPicker(type, existing = false, after = selected) {
    pickerType = type;
    picker.dataset.existing = String(existing);
    picker.dataset.after = after || '';
    action('select-all-picker').hidden = type !== 'grid';
    pickerIds = new Set(existing ? current().images : []);
    search.value = '';
    renderPicker();
    picker.showModal();
    search.focus();
  }

  function showHistory() {
    const list = document.getElementById('layout-history-list');
    document.getElementById('layout-history-empty').hidden = versions.length > 0;
    historyError.hidden = true;
    list.replaceChildren();
    versions.forEach((version, index) => {
      const row = document.createElement('li');
      const details = document.createElement('div');
      const name = document.createElement('strong');
      name.textContent = labels.version_number.replace('{number}', version.revision)
        + ' · ' + labels['version_' + version.kind];
      details.append(name);
      if (version.saved_at) {
        const time = document.createElement('time');
        time.dateTime = version.saved_at.replace(' ', 'T') + 'Z';
        time.textContent = new Date(time.dateTime).toLocaleString(document.documentElement.lang || undefined);
        details.append(time);
      }
      const restore = document.createElement('button');
      restore.type = 'button';
      restore.dataset.action = 'restore-version';
      restore.dataset.versionIndex = index;
      restore.textContent = labels.restore;
      restore.setAttribute('aria-label', labels.restore + ': ' + name.textContent);
      row.append(details, restore);
      list.append(row);
    });
    historyDialog.showModal();
  }

  async function save(mode, version = null) {
    if (saving) return;
    if (mode === 'default' && !confirm(labels.default_confirm)) return;
    if (mode === 'restore' && dirty() && !confirm(labels.restore_confirm)) return;
    historyError.hidden = true;
    saving = true;
    refresh();
    showStatus(labels.saving);
    try {
      const tokenResponse = await fetch(boot.basePath + '/admin/csrf-token', {cache: 'no-store', headers: {Accept: 'application/json'}});
      if (!tokenResponse.ok) throw new Error('Session expired. Reload and sign in again.');
      const token = await tokenResponse.json();
      const response = await fetch(root.dataset.endpoint, {
        method: 'POST',
        headers: {'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': token.token},
        body: JSON.stringify({csrf: token.token, action: mode, revision, ...(version ? {version} : {layout})}),
      });
      const payload = await response.json();
      if (!response.ok) throw new Error(payload.error || 'Unable to save the layout.');
      revision = payload.revision;
      versions = payload.history;
      if (mode !== 'default') {
        layout = payload.layout;
        savedSnapshot = JSON.stringify(layout);
        history = [savedSnapshot];
        historyIndex = 0;
      }
      if (mode === 'publish') customPublished = true;
      if (mode === 'default') customPublished = false;
      saving = false;
      refresh();
      if (mode === 'restore') {
        historyDialog.close();
        showStatus(labels.restored);
      } else {
        showStatus(mode === 'publish' ? labels.published : mode === 'draft' ? labels.saved : labels.default_status);
      }
    } catch (error) {
      saving = false;
      refresh();
      showStatus(error.message, true);
      if (mode === 'restore' && historyDialog.open) {
        historyError.textContent = error.message;
        historyError.hidden = false;
      }
    }
  }

  root.addEventListener('click', event => {
    const button = event.target.closest('button');
    if (!button || saving) return;
    if (button.dataset.select) {
      selected = button.dataset.select;
      selectedRow = null;
      refresh();
      return;
    }
    if (button.dataset.add) {
      if (button.dataset.add === 'text') insert(newBlock('text'));
      else openPicker(button.dataset.add);
      return;
    }
    if (button.dataset.device) {
      frame.dataset.device = button.dataset.device;
      resizePreview();
      root.querySelectorAll('[data-device]').forEach(item => item.setAttribute('aria-pressed', String(item === button)));
      return;
    }
    const mode = button.dataset.action;
    if (['draft', 'publish', 'default'].includes(mode)) { save(mode); return; }
    if (mode === 'history') { showHistory(); return; }
    if (mode === 'close-history') { historyDialog.close(); return; }
    if (mode === 'restore-version') {
      save('restore', versions[Number(button.dataset.versionIndex)]);
      return;
    }
    if (mode === 'undo' || mode === 'redo') {
      historyIndex += mode === 'undo' ? -1 : 1;
      layout = JSON.parse(history[historyIndex]);
      refresh();
    } else if (mode === 'choose' && current()) {
      openPicker(current().type, true);
    } else if (mode === 'select-all-picker' && pickerType === 'grid') {
      picker.querySelectorAll('input[name="layout-photo-selection"]').forEach(input => pickerIds.add(Number(input.value)));
      renderPicker();
    } else if (mode === 'cancel-picker') {
      picker.close();
    } else if (mode === 'apply-picker') {
      const ids = Array.from(pickerIds);
      if (picker.dataset.existing === 'true') {
        current().images = ids;
        remember();
      } else insert(newBlock(pickerType, ids), picker.dataset.after);
      picker.close();
    } else if (mode === 'remove' && current()) {
      layout.blocks = layout.blocks.filter(block => block.id !== selected);
      remember();
    } else if ((mode === 'up' || mode === 'down') && current()) {
      const index = layout.blocks.findIndex(block => block.id === selected);
      const destination = index + (mode === 'up' ? -1 : 1);
      if (destination < 0 || destination >= layout.blocks.length) return;
      const [block] = layout.blocks.splice(index, 1);
      layout.blocks.splice(destination, 0, block);
      remember();
    }
  });

  controls.addEventListener('input', event => {
    const input = event.target;
    const block = current();
    if (!block || !input.dataset.prop || !input.checkValidity()) return;
    block[input.dataset.prop] = ['text', 'align'].includes(input.dataset.prop) ? input.value : Number(input.value || 0);
    document.getElementById('layout-width-value').textContent = block.width + '%';
    showStatus(labels.unsaved);
    preview();
  });
  rowControls.addEventListener('change', event => {
    event.stopPropagation();
    if (!rowAvailable()) return;
    const block = current();
    // JSON may represent an existing set of rows as either an array or an object.
    block.rows = {...block.rows};
    if (rowSplit.value === 'auto') delete block.rows[selectedRow];
    else block.rows[selectedRow] = {split: rowSplit.value, fill: rowFit.value === 'fill'};
    if (!Object.keys(block.rows).length) delete block.rows;
    remember();
  });
  controls.addEventListener('change', remember);
  remaining.addEventListener('change', () => { layout.include_remaining = remaining.checked; remember(); });
  search.addEventListener('input', renderPicker);
  picker.addEventListener('change', event => {
    if (event.target.name !== 'layout-photo-selection') return;
    const id = Number(event.target.value);
    if (pickerType === 'photo') pickerIds.clear();
    if (event.target.checked) pickerIds.add(id);
    else pickerIds.delete(id);
    document.getElementById('layout-picker-count').textContent = pickerIds.size + ' ' + labels.selected;
    action('apply-picker').disabled = pickerIds.size === 0;
  });
  list.addEventListener('dragstart', event => {
    const button = event.target.closest('[data-select]');
    if (!button || saving) return;
    dragged = button.dataset.select;
    event.dataTransfer.setData('text/plain', dragged);
    event.dataTransfer.effectAllowed = 'move';
  });
  list.addEventListener('dragover', event => { if (dragged) event.preventDefault(); });
  list.addEventListener('drop', event => {
    const destination = event.target.closest('[data-select]')?.dataset.select;
    if (!dragged || !destination || dragged === destination) return;
    event.preventDefault();
    const from = layout.blocks.findIndex(block => block.id === dragged);
    const to = layout.blocks.findIndex(block => block.id === destination);
    const [block] = layout.blocks.splice(from, 1);
    layout.blocks.splice(to, 0, block);
    selected = dragged;
    dragged = null;
    remember();
  });
  list.addEventListener('dragend', () => { dragged = null; });
  frame.addEventListener('load', preview);
  window.addEventListener('message', event => {
    if (event.origin !== location.origin || event.source !== frame.contentWindow) return;
    if (event.data?.type === 'cimaise-layout-ready') preview();
    if (event.data?.type === 'cimaise-layout-customize-remaining' && !saving) {
      const placed = new Set(layout.blocks.flatMap(block => block.images || []));
      const ids = photos.filter(photo => !placed.has(photo.id)).map(photo => photo.id).slice(0, 2000);
      if (ids.length) insert(newBlock('grid', ids), layout.blocks.at(-1)?.id);
    }
    if (event.data?.type === 'cimaise-layout-insert' && !saving && ['photo', 'grid', 'text'].includes(event.data.blockType)) {
      if (event.data.blockType === 'text') insert(newBlock('text'), event.data.after);
      else openPicker(event.data.blockType, false, event.data.after);
    }
    if (saving) return;
    const {type, id, row, photo, target, offset} = event.data || {};
    const block = layout.blocks.find(item => item.id === id);
    if (!block) return;
    if (type === 'cimaise-layout-select') {
      selected = id;
      selectedRow = Number.isInteger(row) && row >= 0 ? row : null;
      refresh();
    } else if (type === 'cimaise-layout-move-photo' && block.type === 'grid') {
      const visible = block.images.filter(imageId => photoIds.has(imageId));
      const index = visible.indexOf(photo);
      const destination = target === undefined ? index + offset : visible.indexOf(target);
      if (index < 0 || !Number.isInteger(destination) || destination < 0
        || destination >= visible.length || index === destination) return;
      const from = block.images.indexOf(photo);
      const to = block.images.indexOf(visible[destination]);
      block.images.splice(from, 1);
      block.images.splice(to, 0, photo);
      selected = id;
      selectedRow = Math.floor(destination / block.columns);
      remember();
    }
  }, {signal: events.signal});
  window.addEventListener('beforeunload', event => {
    if (root.isConnected && dirty()) { event.preventDefault(); event.returnValue = ''; }
  }, {signal: events.signal});
  refresh();
})();
